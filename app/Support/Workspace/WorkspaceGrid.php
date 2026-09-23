<?php

namespace App\Support\Workspace;

use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;

/**
 * Workspace rows: one page of typed cells, with linked records resolved as chips.
 *
 * Every cell is wrapped in a value envelope so NULL, empty string and zero stay
 * distinguishable in the grid -- Airtable renders the first two identically, which is
 * a standing source of "the data is wrong" reports. Chips are resolved with one
 * batched query per link column, never one per row.
 */
final class WorkspaceGrid
{
    /**
     * Rows scanned when resolving chips for a page, across all link columns.
     */
    private const MAX_CHIP_ROWS = 2000;

    /**
     * @var list<string>
     */
    private const OPERATORS = ['eq', 'ne', 'in', 'contains', 'starts', 'gte', 'lte', 'empty', 'filled'];

    public function __construct(
        private readonly WorkspaceIntrospector $introspector,
        private readonly WorkspaceSchema $schema,
    ) {}

    /**
     * @return array{product: string, table: string, columns: list<string>, data: list<array<string, mixed>>, meta: array<string, mixed>, sql: string}
     */
    public function rows(string $product, string $table, Request $request, WorkspaceAccess $access): array
    {
        $blueprint = $this->schema->table($product, $table, $access);
        $fields = $this->keyed($blueprint['fields']);

        $perPage = $this->perPage($request);
        $page = max(1, $request->integer('page', 1));
        $columns = $this->requestedColumns($request, $blueprint);

        $scalars = [];
        $links = [];
        $references = [];
        $locked = [];
        foreach ($columns as $column) {
            $field = $fields[$column] ?? null;
            if ($field === null) {
                continue;
            }
            if ($field['type'] === WorkspaceFieldType::LINK) {
                $links[] = $field;

                continue;
            }
            if (($field['locked'] ?? false) === true) {
                $locked[] = $column;

                continue;
            }
            if ($field['type'] === WorkspaceFieldType::REFERENCE) {
                $references[] = $field;
            }
            $scalars[] = $column;
        }

        $key = $this->identityColumn($blueprint);
        if ($key !== null && ! in_array($key, $scalars, true)) {
            $scalars[] = $key;
        }

        $connection = $this->introspector->connection($product);
        $query = $connection->table($table);

        $unfilteredTotal = (clone $query)->count();

        $applied = $this->applyFilters($query, $request, $fields, $product, $table, $key);
        $searched = $this->applySearch($query, $request, $fields);
        $total = (clone $query)->count();

        $sort = $this->applySort($query, $request, $fields, $key);

        $selected = $scalars === [] ? ['*'] : array_map(fn (string $c): string => $this->introspector->quote($c), $scalars);
        $sql = $this->describe($query, $selected, $table, $perPage, $page);

        $records = $query
            ->select($connection->raw(implode(', ', $selected)))
            ->forPage($page, $perPage)
            ->get()
            ->all();

        $data = [];
        foreach ($records as $record) {
            $row = [];
            foreach ((array) $record as $column => $value) {
                $row[$column] = ['v' => $value];
            }
            foreach ($locked as $column) {
                $row[$column] = ['locked' => true];
            }
            $data[] = $row;
        }

        if ($links !== [] && $key !== null) {
            $this->attachChips($product, $links, $data, $key);
        }

        if ($references !== []) {
            $this->attachReferences($product, $references, $data);
        }

        return [
            'product' => $product,
            'table' => $table,
            'label' => $blueprint['label'],
            'title' => $blueprint['title'],
            'columns' => $columns,
            'data' => $data,
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'unfiltered_total' => $unfilteredTotal,
                'last_page' => max(1, (int) ceil($total / $perPage)),
                'filtered' => $applied !== [] || $searched !== '',
                'filters' => $applied,
                'q' => $searched,
                'sort' => $sort,
            ],
            'sql' => $sql,
        ];
    }

    /**
     * One record with every field and every link resolved, for the peek panel.
     *
     * @return array{product: string, table: string, id: mixed, data: array<string, mixed>, fields: list<array<string, mixed>>}
     */
    public function record(string $product, string $table, string $id, WorkspaceAccess $access): array
    {
        $blueprint = $this->schema->table($product, $table, $access);
        $key = $this->identityColumn($blueprint);

        if ($key === null) {
            abort(404, 'That table has no single-column key to address a record by.');
        }

        $scalars = [];
        $links = [];
        $references = [];
        $locked = [];
        foreach ($blueprint['fields'] as $field) {
            if ($field['type'] === WorkspaceFieldType::LINK) {
                $links[] = $field;

                continue;
            }
            if (($field['locked'] ?? false) === true) {
                $locked[] = (string) $field['name'];

                continue;
            }
            if ($field['type'] === WorkspaceFieldType::REFERENCE) {
                $references[] = $field;
            }
            $scalars[] = $this->introspector->quote((string) $field['name']);
        }

        $connection = $this->introspector->connection($product);
        $record = $connection->table($table)
            ->select($connection->raw(implode(', ', $scalars)))
            ->where($key, $id)
            ->first();

        if ($record === null) {
            abort(404, 'That record was not found.');
        }

        $row = [];
        foreach ((array) $record as $column => $value) {
            $row[$column] = ['v' => $value];
        }
        foreach ($locked as $column) {
            $row[$column] = ['locked' => true];
        }

        $rows = [$row];
        if ($links !== []) {
            $this->attachChips($product, $links, $rows, $key, chipLimit: 0);
        }
        if ($references !== []) {
            $this->attachReferences($product, $references, $rows);
        }

        return [
            'product' => $product,
            'table' => $table,
            'id' => $rows[0][$key]['v'] ?? null,
            'data' => $rows[0],
            'fields' => $blueprint['fields'],
        ];
    }

    /**
     * Resolve every link column for the page in one query each: junction joined to the
     * target, filtered to the ids on screen.
     *
     * @param  list<array<string, mixed>>  $links
     * @param  list<array<string, mixed>>  $rows
     */
    private function attachChips(string $product, array $links, array &$rows, string $key, ?int $chipLimit = null): void
    {
        $limit = $chipLimit ?? (int) config('workspace.chips_per_cell', 3);

        $owners = [];
        foreach ($rows as $row) {
            $value = $row[$key]['v'] ?? null;
            if ($value !== null) {
                $owners[] = $value;
            }
        }

        if ($owners === []) {
            return;
        }

        foreach ($links as $field) {
            $target = (string) $field['target'];
            $targetKey = $this->introspector->surrogateKey($product, $target);
            if ($targetKey === null) {
                continue;
            }

            $query = $field['kind'] === WorkspaceLinks::CHILD
                ? $this->childChipQuery($product, $field, $targetKey, $owners)
                : $this->junctionChipQuery($product, $field, $targetKey, $owners);

            $grouped = [];
            foreach ($query->get() as $chip) {
                $grouped[(string) $chip->owner][] = [
                    'id' => $chip->rid,
                    'label' => trim((string) $chip->label) === '' ? (string) $chip->rid : (string) $chip->label,
                ];
            }

            $name = (string) $field['name'];
            foreach ($rows as $index => $row) {
                $owner = (string) ($row[$key]['v'] ?? '');
                $found = $grouped[$owner] ?? [];
                $shown = $limit > 0 ? array_slice($found, 0, $limit) : $found;

                $rows[$index][$name] = [
                    'chips' => $shown,
                    'more' => max(0, count($found) - count($shown)),
                    'count' => count($found),
                    'to' => $target,
                    'kind' => $field['kind'],
                ];
            }
        }
    }

    /**
     * @param  array<string, mixed>  $field
     * @param  list<mixed>  $owners
     */
    private function junctionChipQuery(string $product, array $field, string $targetKey, array $owners): Builder
    {
        $connection = $this->introspector->connection($product);
        $target = (string) $field['target'];
        $near = (string) $field['near_column'];

        $query = $connection->table((string) $field['via'].' as j')
            ->join($target.' as t', 't.'.$targetKey, '=', 'j.'.(string) $field['far_column'])
            ->whereIn('j.'.$near, $owners)
            ->select($connection->raw(
                'j.'.$this->introspector->quote($near).' as owner, t.'.
                $this->introspector->quote($targetKey).' as rid, '.
                $this->labelExpression($product, $target, 't').' as label',
            ))
            ->limit(self::MAX_CHIP_ROWS);

        if ($field['qualifier'] !== null && $field['variant'] !== null) {
            $query->where('j.'.(string) $field['qualifier'], $field['variant']);
        }

        return $query;
    }

    /**
     * A child table needs no junction: the chips are its own rows, labelled by the one
     * column that is not the link back.
     *
     * @param  array<string, mixed>  $field
     * @param  list<mixed>  $owners
     */
    private function childChipQuery(string $product, array $field, string $targetKey, array $owners): Builder
    {
        $connection = $this->introspector->connection($product);
        $near = (string) $field['far_column'];
        $labelColumn = $field['label_column'];

        $label = is_string($labelColumn)
            ? 'coalesce(cast(t.'.$this->introspector->quote($labelColumn)." as char), '')"
            : $this->labelExpression($product, (string) $field['target'], 't');

        return $connection->table((string) $field['target'].' as t')
            ->whereIn('t.'.$near, $owners)
            ->select($connection->raw(
                't.'.$this->introspector->quote($near).' as owner, t.'.
                $this->introspector->quote($targetKey).' as rid, '.$label.' as label',
            ))
            ->limit(self::MAX_CHIP_ROWS);
    }

    /**
     * Resolve the single record a foreign-key column points at, one query per target
     * rather than one per row. Without this the cell is a bare integer and the reader
     * has to know that project 47 is the Ayala tower.
     *
     * @param  list<array<string, mixed>>  $references
     * @param  list<array<string, mixed>>  $rows
     */
    private function attachReferences(string $product, array $references, array &$rows): void
    {
        $connection = $this->introspector->connection($product);

        foreach ($references as $field) {
            $name = (string) $field['name'];
            $target = (string) $field['target'];
            $targetKey = (string) $field['far_column'];

            $ids = [];
            foreach ($rows as $row) {
                $value = $row[$name]['v'] ?? null;
                if ($value !== null && $value !== '') {
                    $ids[(string) $value] = $value;
                }
            }

            if ($ids === []) {
                continue;
            }

            $found = $connection->table($target.' as t')
                ->whereIn('t.'.$targetKey, array_values($ids))
                ->select($connection->raw(
                    't.'.$this->introspector->quote($targetKey).' as rid, '.
                    $this->labelExpression($product, $target, 't').' as label',
                ))
                ->limit(self::MAX_CHIP_ROWS)
                ->get();

            $labels = [];
            foreach ($found as $row) {
                $labels[(string) $row->rid] = trim((string) $row->label) === ''
                    ? (string) $row->rid
                    : (string) $row->label;
            }

            foreach ($rows as $index => $row) {
                $value = $row[$name]['v'] ?? null;
                if ($value === null || $value === '') {
                    continue;
                }

                $rows[$index][$name] = [
                    'v' => $value,
                    'chips' => [['id' => $value, 'label' => $labels[(string) $value] ?? (string) $value]],
                    'more' => 0,
                    'count' => 1,
                    'to' => $target,
                    'kind' => WorkspaceLinks::PARENT,
                ];
            }
        }
    }

    /**
     * How a chip for one row of $table reads. Several tables need more than one column
     * -- a user report is only identifiable as a date plus who filed it.
     */
    private function labelExpression(string $product, string $table, string $alias): string
    {
        $parts = [];
        foreach ($this->schema->titleParts($product, $table) as $part) {
            $parts[] = 'coalesce(cast('.$alias.'.'.$this->introspector->quote($part)." as char), '')";
        }

        if ($parts === []) {
            return "''";
        }

        return count($parts) === 1
            ? $parts[0]
            : 'concat_ws('.chr(39).' · '.chr(39).', '.implode(', ', $parts).')';
    }

    /**
     * @param  array<string, array<string, mixed>>  $fields
     * @return array<string, string>
     */
    private function applyFilters(
        Builder $query,
        Request $request,
        array $fields,
        string $product,
        string $table,
        ?string $key,
    ): array {
        $raw = $request->query('filter');
        if (! is_array($raw)) {
            return [];
        }

        $applied = [];
        foreach ($raw as $column => $expression) {
            if (! is_string($column) || ! is_string($expression) || $expression === '') {
                continue;
            }

            $field = $fields[$column] ?? null;
            if ($field === null || ($field['locked'] ?? false) === true) {
                continue;
            }

            [$operator, $value] = $this->splitExpression($expression);

            if ($field['type'] === WorkspaceFieldType::LINK) {
                if ($key === null || ! $this->applyLinkFilter($query, $field, $operator, $value, $product, $table, $key)) {
                    continue;
                }

                $applied[$column] = $operator.':'.$value;

                continue;
            }

            match ($operator) {
                'eq' => $query->where($column, '=', $value),
                'ne' => $query->where($column, '!=', $value),
                'in' => $query->whereIn($column, $this->splitList($value)),
                'contains' => $query->where($column, 'like', '%'.$this->escapeLike($value).'%'),
                'starts' => $query->where($column, 'like', $this->escapeLike($value).'%'),
                'gte' => $query->where($column, '>=', $value),
                'lte' => $query->where($column, '<=', $value),
                'empty' => $query->where(function (Builder $inner) use ($column): void {
                    $inner->whereNull($column)->orWhere($column, '=', '');
                }),
                'filled' => $query->whereNotNull($column)->where($column, '!=', ''),
                default => null,
            };

            $applied[$column] = $operator.':'.$value;
        }

        return $applied;
    }

    /**
     * Narrow a table by one of its relationships: every project this person is PM of,
     * every record with no link at all. The chips already say who is linked; without
     * this the grid can show that and still not answer the obvious next question.
     *
     * @param  array<string, mixed>  $field
     */
    private function applyLinkFilter(
        Builder $query,
        array $field,
        string $operator,
        string $value,
        string $product,
        string $table,
        string $key,
    ): bool {
        if (! in_array($operator, ['eq', 'contains', 'empty', 'filled'], true)) {
            return false;
        }

        $target = (string) $field['target'];
        $targetKey = $this->introspector->surrogateKey($product, $target);
        if ($targetKey === null) {
            return false;
        }

        $build = function (Builder $sub) use ($field, $operator, $value, $product, $table, $key, $target, $targetKey): void {
            $sub->selectRaw('1');

            if ($field['kind'] === WorkspaceLinks::CHILD) {
                $sub->from($target.' as t')->whereColumn('t.'.(string) $field['far_column'], $table.'.'.$key);
            } else {
                $sub->from((string) $field['via'].' as j')
                    ->whereColumn('j.'.(string) $field['near_column'], $table.'.'.$key);

                if ($field['qualifier'] !== null && $field['variant'] !== null) {
                    $sub->where('j.'.(string) $field['qualifier'], $field['variant']);
                }

                if ($operator === 'eq') {
                    $sub->where('j.'.(string) $field['far_column'], $value);

                    return;
                }

                if ($operator === 'contains') {
                    $sub->join($target.' as t', 't.'.$targetKey, '=', 'j.'.(string) $field['far_column']);
                }
            }

            if ($operator === 'eq') {
                $sub->where('t.'.$targetKey, $value);
            }

            if ($operator === 'contains') {
                $like = '%'.$this->escapeLike($value).'%';
                $parts = $this->schema->titleParts($product, $target);
                $sub->where(function (Builder $inner) use ($parts, $like): void {
                    foreach ($parts as $part) {
                        $inner->orWhere('t.'.$part, 'like', $like);
                    }
                });
            }
        };

        if ($operator === 'empty') {
            $query->whereNotExists($build);

            return true;
        }

        $query->whereExists($build);

        return true;
    }

    /**
     * Pipe-separated, because a select value here is quite capable of holding a comma
     * -- "Design, Build" is a real type_of_job.
     *
     * @return list<string>
     */
    private function splitList(string $value): array
    {
        $values = [];
        foreach (explode('|', $value) as $part) {
            $part = trim($part);
            if ($part !== '') {
                $values[] = $part;
            }
        }

        return $values;
    }

    /**
     * @param  array<string, array<string, mixed>>  $fields
     */
    private function applySearch(Builder $query, Request $request, array $fields): string
    {
        $search = trim((string) $request->query('q', ''));
        if ($search === '') {
            return '';
        }

        $targets = [];
        foreach ($fields as $field) {
            if ($field['searchable'] === true) {
                $targets[] = (string) $field['name'];
            }
        }

        if ($targets === []) {
            return $search;
        }

        $like = '%'.$this->escapeLike($search).'%';
        $query->where(function (Builder $inner) use ($targets, $like): void {
            foreach ($targets as $column) {
                $inner->orWhere($column, 'like', $like);
            }
        });

        return $search;
    }

    /**
     * @param  array<string, array<string, mixed>>  $fields
     */
    private function applySort(Builder $query, Request $request, array $fields, ?string $key): string
    {
        $requested = trim((string) $request->query('sort', ''));
        [$column, $direction] = array_pad(explode(':', $requested, 2), 2, 'asc');
        $direction = strtolower(trim($direction)) === 'desc' ? 'desc' : 'asc';

        $field = $fields[$column] ?? null;
        if ($field === null || $field['sortable'] !== true) {
            if ($key !== null) {
                $query->orderBy($key);

                return $key.':asc';
            }

            return '';
        }

        $query->orderBy($column, $direction);

        // A stable tiebreak, so page 2 cannot repeat a row from page 1.
        if ($key !== null && $key !== $column) {
            $query->orderBy($key);
        }

        return $column.':'.$direction;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitExpression(string $expression): array
    {
        $position = strpos($expression, ':');
        if ($position === false) {
            // empty and filled take no argument, so they arrive with no colon to split
            // on -- read as a value they would silently mean "= the word empty".
            return in_array($expression, ['empty', 'filled'], true)
                ? [$expression, '']
                : ['eq', $expression];
        }

        $operator = substr($expression, 0, $position);
        if (! in_array($operator, self::OPERATORS, true)) {
            return ['eq', $expression];
        }

        return [$operator, substr($expression, $position + 1)];
    }

    /**
     * @param  array{fields: list<array<string, mixed>>, presets: array{key: list<string>, all: list<string>}}  $blueprint
     * @return list<string>
     */
    private function requestedColumns(Request $request, array $blueprint): array
    {
        $requested = trim((string) $request->query('cols', 'key'));

        if ($requested === 'all') {
            return $blueprint['presets']['all'];
        }

        $known = $this->keyed($blueprint['fields']);

        if ($requested === '' || $requested === 'key') {
            return $this->withActive($blueprint['presets']['key'], $request, $known);
        }

        $columns = [];
        foreach (explode(',', $requested) as $column) {
            $column = trim($column);
            if ($column !== '' && isset($known[$column])) {
                $columns[] = $column;
            }
        }

        return $columns === [] ? $this->withActive($blueprint['presets']['key'], $request, $known) : $columns;
    }

    /**
     * A preset that omits the column being filtered or sorted on reads as though the
     * grid is hiding why rows disappeared, so those columns are appended to it.
     *
     * @param  list<string>  $columns
     * @param  array<string, array<string, mixed>>  $known
     * @return list<string>
     */
    private function withActive(array $columns, Request $request, array $known): array
    {
        $active = array_keys((array) $request->query('filter', []));
        $sorted = explode(':', (string) $request->query('sort', ''))[0];
        if ($sorted !== '') {
            $active[] = $sorted;
        }

        foreach ($active as $column) {
            if (is_string($column) && isset($known[$column]) && ! in_array($column, $columns, true)) {
                $columns[] = $column;
            }
        }

        return $columns;
    }

    /**
     * @param  array{fields: list<array<string, mixed>>}  $blueprint
     */
    private function identityColumn(array $blueprint): ?string
    {
        foreach ($blueprint['fields'] as $field) {
            if ($field['type'] === WorkspaceFieldType::ID) {
                return (string) $field['name'];
            }
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $fields
     * @return array<string, array<string, mixed>>
     */
    private function keyed(array $fields): array
    {
        $keyed = [];
        foreach ($fields as $field) {
            $keyed[(string) $field['name']] = $field;
        }

        return $keyed;
    }

    private function perPage(Request $request): int
    {
        $default = (int) config('workspace.page_size', 50);
        $max = (int) config('workspace.max_page_size', 200);

        return min($max, max(1, $request->integer('per_page', $default)));
    }

    /**
     * The exact statement behind the grid, for the Show SQL affordance. Bindings are
     * inlined for reading only -- the query that ran is the parameterised one.
     *
     * @param  list<string>  $selected
     */
    private function describe(Builder $query, array $selected, string $table, int $perPage, int $page): string
    {
        $sql = 'select '.implode(', ', $selected).' from '.$this->introspector->quote($table);

        $rendered = $query->toSql();
        $where = strstr($rendered, ' where ');
        if (is_string($where)) {
            $sql .= $where;
        }

        foreach ($query->getBindings() as $binding) {
            $value = is_string($binding) ? "'".str_replace("'", "''", $binding)."'" : (string) $binding;
            $sql = preg_replace('/\?/', $value, $sql, 1) ?? $sql;
        }

        return $sql.' limit '.$perPage.' offset '.(($page - 1) * $perPage);
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $value);
    }
}
