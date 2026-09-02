<?php

namespace App\Support\Workspace;

/**
 * Workspace blueprint: the tables in a product and the fields in one table.
 *
 * Junction tables never appear in the rail. They are Airtable link fields wearing a
 * table costume -- 26 of the portal's 48 tables are junctions -- so they are folded
 * into chip columns on both parents instead, which is what the Airtable base they
 * were converted from actually showed.
 */
final class WorkspaceSchema
{
    /**
     * Options listed for a select column before the filter falls back to free text.
     */
    private const MAX_OPTIONS = 25;

    /**
     * Fields the Key preset shows beyond the identity and title columns.
     */
    private const KEY_PRESET_SIZE = 8;

    public function __construct(private readonly WorkspaceIntrospector $introspector) {}

    /**
     * The table rail: every browsable table with its exact row count.
     *
     * @return array{product: string, database: string, tables: list<array{name: string, label: string, rows: int, title: string}>, hidden: list<array{name: string, reason: string}>}
     */
    public function nav(string $product): array
    {
        $tables = $this->introspector->tableNames($product);
        $junctions = $this->introspector->junctions($product);
        $infrastructure = (array) config('workspace.hidden_tables.'.$product, []);

        $visible = [];
        $hidden = [];
        foreach ($tables as $table) {
            if (isset($junctions[$table])) {
                $hidden[] = ['name' => $table, 'reason' => 'link'];

                continue;
            }

            if (in_array($table, $infrastructure, true)) {
                $hidden[] = ['name' => $table, 'reason' => 'infrastructure'];

                continue;
            }

            $visible[] = $table;
        }

        $counts = $this->introspector->rowCounts($product, $visible);

        $rows = [];
        foreach ($visible as $table) {
            $rows[] = [
                'name' => $table,
                'label' => $this->tableLabel($product, $table),
                'rows' => $counts[$table] ?? 0,
                'title' => $this->titleColumn($product, $table),
            ];
        }

        return [
            'product' => $product,
            'database' => $this->introspector->database($product),
            'tables' => $rows,
            'hidden' => $hidden,
        ];
    }

    /**
     * Every field of one table, ordered so the useful half of a 93-column table is on
     * screen before the first horizontal scroll.
     *
     * @return array{product: string, table: string, label: string, rows: int, title: string, fields: list<array<string, mixed>>, presets: array{key: list<string>, all: list<string>}}
     */
    public function table(string $product, string $table, bool $isAdmin): array
    {
        $this->assertBrowsable($product, $table);

        $columns = $this->introspector->columns($product)[$table] ?? [];
        $readable = [];
        foreach ($columns as $column) {
            if (! WorkspaceRedactor::isHidden($column['name'], $isAdmin)) {
                $readable[] = $column;
            }
        }

        $probeable = [];
        foreach ($readable as $column) {
            if ($this->isStringColumn($column['data_type'])) {
                $probeable[] = $column['name'];
            }
        }

        $probes = $this->introspector->probe($product, $table, $probeable);
        $title = $this->titleColumn($product, $table);

        $fields = [];
        foreach ($readable as $column) {
            $type = WorkspaceFieldType::infer($column, $probes[$column['name']] ?? null);
            if ($column['name'] === $title && $type !== WorkspaceFieldType::ID) {
                $type = WorkspaceFieldType::TITLE;
            }

            $field = [
                'name' => $column['name'],
                'label' => WorkspaceFieldType::label($column['name']),
                'type' => $type,
                'width' => WorkspaceFieldType::width($type),
                'nullable' => $column['nullable'],
                'sortable' => true,
                'searchable' => WorkspaceFieldType::isSearchable($type),
                'numeric' => WorkspaceFieldType::isNumericType($type),
                'filled' => $probes[$column['name']]['nonnull'] ?? null,
            ];

            if ($type === WorkspaceFieldType::SELECT) {
                $field['options'] = $this->introspector->options($product, $table, $column['name'], self::MAX_OPTIONS);
            }

            $fields[] = $field;
        }

        foreach ($this->linkFields($product, $table) as $link) {
            $fields[] = $link;
        }

        $fields = $this->ordered($fields);
        $counts = $this->introspector->rowCounts($product, [$table]);

        return [
            'product' => $product,
            'table' => $table,
            'label' => $this->tableLabel($product, $table),
            'rows' => $counts[$table] ?? 0,
            'title' => $title,
            'fields' => $fields,
            'presets' => $this->presets($fields),
        ];
    }

    /**
     * Link fields for one table, one per junction side. A junction carrying a
     * qualifier column splits into one field per value, so employees_projects becomes
     * PM, Project Members and Support Members rather than a single opaque column.
     *
     * @return list<array<string, mixed>>
     */
    public function linkFields(string $product, string $table): array
    {
        $labels = (array) config('workspace.link_labels', []);
        $fields = [];

        foreach ($this->introspector->junctions($product) as $junction => $shape) {
            foreach ([['left', 'right'], ['right', 'left']] as [$near, $far]) {
                if ($shape[$near] !== $table) {
                    continue;
                }

                $target = $shape[$far];
                $variants = [null];
                if ($shape['qualifier'] !== null) {
                    $found = $this->introspector->options($product, $junction, $shape['qualifier'], 8);
                    $variants = $found === [] ? [null] : $found;
                }

                foreach ($variants as $variant) {
                    $name = $variant === null ? 'link__'.$target : 'link__'.$target.'__'.$variant;
                    $label = $variant === null
                        ? WorkspaceFieldType::label($target)
                        : (string) ($labels[$variant] ?? WorkspaceFieldType::label($variant));

                    $fields[] = [
                        'name' => $name,
                        'label' => $label,
                        'hint' => $this->linkHint($junction, $target, (string) $shape[$far.'_column']),
                        'type' => WorkspaceFieldType::LINK,
                        'width' => WorkspaceFieldType::width(WorkspaceFieldType::LINK),
                        'nullable' => true,
                        'sortable' => false,
                        'searchable' => false,
                        'numeric' => false,
                        'filled' => null,
                        'target' => $target,
                        'via' => $junction,
                        'near_column' => $shape[$near.'_column'],
                        'far_column' => $shape[$far.'_column'],
                        'qualifier' => $shape['qualifier'],
                        'variant' => $variant,
                    ];
                }
            }
        }

        return $this->disambiguated($fields);
    }

    /**
     * Two junctions can reach the same table from one side, and a self-join reaches it
     * twice through a single junction -- employees links to employees as both manager
     * and report. A bare link__employees would collide, so colliding names take the
     * relationship as a suffix and the rest keep the clean name.
     *
     * @param  list<array<string, mixed>>  $fields
     * @return list<array<string, mixed>>
     */
    private function disambiguated(array $fields): array
    {
        $seen = [];
        foreach ($fields as $field) {
            $seen[(string) $field['name']] = ($seen[(string) $field['name']] ?? 0) + 1;
        }

        $resolved = [];
        foreach ($fields as $field) {
            $name = (string) $field['name'];
            if (($seen[$name] ?? 0) > 1) {
                $field['name'] = $name.'__'.$field['hint'];
                $field['label'] = $field['label'].' ('.WorkspaceFieldType::label((string) $field['hint']).')';
            }

            unset($field['hint']);
            $resolved[] = $field;
        }

        return $resolved;
    }

    /**
     * What tells two links to the same table apart: the column pointed at for a
     * self-join, the junction otherwise.
     */
    private function linkHint(string $junction, string $target, string $farColumn): string
    {
        $stem = str_ends_with($farColumn, '_id') ? substr($farColumn, 0, -3) : $farColumn;

        return $stem === rtrim($target, 's') ? $junction : $stem;
    }

    /**
     * The frozen first column, and the label a chip shows when another table links
     * here. Curated where inference would pick wrong: user_reports has no name column
     * at all, and the estimator chips in Airtable were employee id numbers, not names.
     */
    public function titleColumn(string $product, string $table): string
    {
        $configured = config('workspace.title_fields.'.$product.'.'.$table);
        if (is_string($configured)) {
            return $configured;
        }
        if (is_array($configured) && isset($configured[0]) && is_string($configured[0])) {
            return $configured[0];
        }

        $columns = $this->introspector->columns($product)[$table] ?? [];
        $names = [];
        foreach ($columns as $column) {
            $names[] = $column['name'];
        }

        foreach (['name', 'title', $table.'_name', 'label', 'description', 'code'] as $candidate) {
            if (in_array($candidate, $names, true)) {
                return $candidate;
            }
        }

        foreach ($columns as $column) {
            if ($column['name'] === 'id' || $column['name'] === 'airtable_record_id') {
                continue;
            }
            if ($this->isStringColumn($column['data_type'])) {
                return $column['name'];
            }
        }

        return $names[0] ?? 'id';
    }

    /**
     * Extra columns joined into a chip label, for tables whose title alone repeats --
     * a user report is only identifiable as a date plus who filed it.
     *
     * @return list<string>
     */
    public function titleParts(string $product, string $table): array
    {
        $configured = config('workspace.title_fields.'.$product.'.'.$table);
        if (is_array($configured)) {
            $parts = [];
            foreach ($configured as $part) {
                if (is_string($part)) {
                    $parts[] = $part;
                }
            }

            return $parts;
        }

        return [$this->titleColumn($product, $table)];
    }

    public function tableLabel(string $product, string $table): string
    {
        $configured = config('workspace.table_labels.'.$product.'.'.$table);
        if (is_string($configured)) {
            return $configured;
        }

        return WorkspaceFieldType::label($table);
    }

    /**
     * A junction or an infrastructure table is not addressable as a grid.
     */
    public function assertBrowsable(string $product, string $table): void
    {
        if (! in_array($table, $this->introspector->tableNames($product), true)) {
            abort(404, 'That table is not in this database.');
        }

        if (isset($this->introspector->junctions($product)[$table])) {
            abort(404, 'That table is a link between two tables, not a table of its own.');
        }

        if (in_array($table, (array) config('workspace.hidden_tables.'.$product, []), true)) {
            abort(404, 'That table is framework plumbing, not data.');
        }
    }

    /**
     * @param  list<array<string, mixed>>  $fields
     * @return list<array<string, mixed>>
     */
    private function ordered(array $fields): array
    {
        usort($fields, function (array $a, array $b): int {
            $rank = WorkspaceFieldType::rank((string) $a['type']) <=> WorkspaceFieldType::rank((string) $b['type']);

            return $rank !== 0 ? $rank : strcmp((string) $a['name'], (string) $b['name']);
        });

        return array_values($fields);
    }

    /**
     * @param  list<array<string, mixed>>  $fields
     * @return array{key: list<string>, all: list<string>}
     */
    private function presets(array $fields): array
    {
        $all = [];
        foreach ($fields as $field) {
            $all[] = (string) $field['name'];
        }

        $key = [];
        foreach ($fields as $field) {
            if (in_array($field['type'], [WorkspaceFieldType::ID, WorkspaceFieldType::TITLE], true)) {
                $key[] = (string) $field['name'];
            }
        }

        foreach ($fields as $field) {
            if (count($key) >= self::KEY_PRESET_SIZE + count(array_slice($key, 0, 2))) {
                break;
            }
            if (in_array((string) $field['name'], $key, true)) {
                continue;
            }
            if ($field['type'] === WorkspaceFieldType::EXTERNAL || $field['filled'] === 0) {
                continue;
            }

            $key[] = (string) $field['name'];
        }

        return ['key' => $key, 'all' => $all];
    }

    private function isStringColumn(string $dataType): bool
    {
        return in_array(strtolower($dataType), ['char', 'varchar', 'text', 'tinytext', 'mediumtext', 'longtext'], true);
    }
}
