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
    private const OPERATORS = ['eq', 'ne', 'contains', 'starts', 'gte', 'lte', 'empty', 'filled'];

    public function __construct(
        private readonly WorkspaceIntrospector $introspector,
        private readonly WorkspaceSchema $schema,
    ) {}

    /**
     * @return array{product: string, table: string, columns: list<string>, data: list<array<string, mixed>>, meta: array<string, mixed>, sql: string}
     */
    public function rows(string $product, string $table, Request $request, bool $isAdmin): array
    {
        $blueprint = $this->schema->table($product, $table, $isAdmin);
        $fields = $this->keyed($blueprint['fields']);

        $perPage = $this->perPage($request);
        $page = max(1, $request->integer('page', 1));
        $columns = $this->requestedColumns($request, $blueprint);

        $scalars = [];
        $links = [];
        foreach ($columns as $column) {
            $field = $fields[$column] ?? null;
            if ($field === null) {
                continue;
            }
            if ($field['type'] === WorkspaceFieldType::LINK) {
                $links[] = $field;

                continue;
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

        $applied = $this->applyFilters($query, $request, $fields);
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
            $data[] = $row;
        }

        if ($links !== [] && $key !== null) {
            $this->attachChips($product, $links, $data, $key);
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
    public function record(string $product, string $table, string $id, bool $isAdmin): array
    {
        $blueprint = $this->schema->table($product, $table, $isAdmin);
        $key = $this->identityColumn($blueprint);

        if ($key === null) {
            abort(404, 'That table has no single-column key to address a record by.');
        }

        $scalars = [];
        $links = [];
        foreach ($blueprint['fields'] as $field) {
            if ($field['type'] === WorkspaceFieldType::LINK) {
                $links[] = $field;

                continue;
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

        $rows = [$row];
        if ($links !== []) {
            $this->attachChips($product, $links, $rows, $key, chipLimit: 0);
        }

        return [
            'product' => $product,
            'table' => $table,
            'id' => $row[$key]['v'] ?? null,
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

        $connection = $this->introspector->connection($product);

        foreach ($links as $field) {
            $junction = (string) $field['via'];
            $target = (string) $field['target'];
            $near = (string) $field['near_column'];
            $far = (string) $field['far_column'];

            $targetKey = $this->targetKey($product, $target);
            if ($targetKey === null) {
                continue;
            }

            $parts = $this->schema->titleParts($product, $target);
            $labelParts = [];
            foreach ($parts as $part) {
                $labelParts[] = 'coalesce(cast(t.'.$this->introspector->quote($part)." as char), '')";
            }
            $label = count($labelParts) === 1
                ? $labelParts[0]
                : 'concat_ws('.chr(39).' · '.chr(39).', '.implode(', ', $labelParts).')';

            $query = $connection->table($junction.' as j')
                ->join($target.' as t', 't.'.$targetKey, '=', 'j.'.$far)
                ->whereIn('j.'.$near, $owners)
                ->select($connection->raw(
                    'j.'.$this->introspector->quote($near).' as owner, t.'.
                    $this->introspector->quote($targetKey).' as rid, '.$label.' as label',
                ))
                ->limit(self::MAX_CHIP_ROWS);

            if ($field['qualifier'] !== null && $field['variant'] !== null) {
                $query->where('j.'.(string) $field['qualifier'], $field['variant']);
            }

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
                    'to' => $target,
                ];
            }
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $fields
     * @return array<string, string>
     */
    private function applyFilters(Builder $query, Request $request, array $fields): array
    {
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
            if ($field === null || $field['type'] === WorkspaceFieldType::LINK) {
                continue;
            }

            [$operator, $value] = $this->splitExpression($expression);

            match ($operator) {
                'eq' => $query->where($column, '=', $value),
                'ne' => $query->where($column, '!=', $value),
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
            return ['eq', $expression];
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

    private function targetKey(string $product, string $target): ?string
    {
        foreach ($this->introspector->columns($product)[$target] ?? [] as $column) {
            if (str_contains(strtolower($column['extra']), 'auto_increment')) {
                return $column['name'];
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
