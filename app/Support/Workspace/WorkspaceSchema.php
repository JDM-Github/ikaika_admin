<?php

namespace App\Support\Workspace;

/**
 * Workspace blueprint: the tables in a product and the fields in one table.
 *
 * Junction tables never appear in the rail. They are Airtable link fields wearing a
 * table costume -- 26 of the portal's 48 tables are junctions -- so they are folded
 * into chip columns on both parents instead, which is what the Airtable base they
 * were converted from actually showed. WorkspaceLinks builds those columns; this
 * class decides what a table looks like once they are in.
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

    /**
     * Chip columns the Key preset shows before it goes back to scalars.
     */
    private const KEY_PRESET_LINKS = 3;

    public function __construct(
        private readonly WorkspaceIntrospector $introspector,
        private readonly WorkspaceLinks $links,
    ) {}

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
     * @return array{product: string, table: string, label: string, rows: int, title: string, key: ?string, editable: bool, fields: list<array<string, mixed>>, presets: array{key: list<string>, all: list<string>}}
     */
    public function table(string $product, string $table, WorkspaceAccess $access): array
    {
        $this->assertBrowsable($product, $table);

        $columns = $this->introspector->columns($product)[$table] ?? [];
        $readable = [];
        foreach ($columns as $column) {
            if (! WorkspaceRedactor::isSecret($column['name'])) {
                $readable[] = $column;
            }
        }

        $probeable = [];
        foreach ($readable as $column) {
            if ($this->isStringColumn($column['data_type']) && ! $this->isLocked($column['name'], $access)) {
                $probeable[] = $column['name'];
            }
        }

        $probes = $this->introspector->probe($product, $table, $probeable);
        $title = $this->titleColumn($product, $table);
        $references = $this->links->parentColumns($product, $table);
        $key = $this->introspector->surrogateKey($product, $table);
        $writable = $key !== null && $access->canEdit() && WorkspaceWriter::isWritable($product);

        $fields = [];
        foreach ($readable as $column) {
            $reference = $references[$column['name']] ?? null;
            if ($reference !== null) {
                $fields[] = array_merge($reference, [
                    'locked' => false,
                    'filled' => $probes[$column['name']]['nonnull'] ?? null,
                ]);

                continue;
            }

            $locked = $this->isLocked($column['name'], $access);
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
                'sortable' => ! $locked,
                'searchable' => ! $locked && WorkspaceFieldType::isSearchable($type),
                'numeric' => WorkspaceFieldType::isNumericType($type),
                'filled' => $locked ? null : ($probes[$column['name']]['nonnull'] ?? null),
                'locked' => $locked,
                'editable' => $writable && ! $locked && WorkspaceFieldType::isEditable($type),
            ];

            if ($type === WorkspaceFieldType::SELECT) {
                $field['options'] = $this->introspector->options($product, $table, $column['name'], self::MAX_OPTIONS);
            }

            $fields[] = $field;
        }

        foreach ($this->links->fields($product, $table) as $link) {
            $fields[] = $link;
        }

        $fields = $this->ordered($fields, $table);
        $counts = $this->introspector->rowCounts($product, [$table]);

        return [
            'product' => $product,
            'table' => $table,
            'label' => $this->tableLabel($product, $table),
            'rows' => $counts[$table] ?? 0,
            'title' => $title,
            'key' => $key,
            'editable' => $writable,
            'fields' => $fields,
            'presets' => $this->presets($fields),
        ];
    }

    /**
     * A private column stays on screen for someone who may not read it, greyed and
     * empty. Dropping it outright made the grid look as though the column did not
     * exist, which answers the question worse than naming who may see it.
     */
    private function isLocked(string $column, WorkspaceAccess $access): bool
    {
        return WorkspaceRedactor::isPrivate($column) && ! $access->canReadPrivate();
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
    private function ordered(array $fields, string $table): array
    {
        usort($fields, function (array $a, array $b) use ($table): int {
            $rank = WorkspaceFieldType::rank((string) $a['type']) <=> WorkspaceFieldType::rank((string) $b['type']);
            if ($rank !== 0) {
                return $rank;
            }

            $priority = $this->linkPriority($a, $table) <=> $this->linkPriority($b, $table);

            return $priority !== 0 ? $priority : strcmp((string) $a['name'], (string) $b['name']);
        });

        return array_values($fields);
    }

    /**
     * Which links lead the pack. A link we bothered to name in config is one the
     * Airtable base showed as its own field, and a self-join -- an employee's manager
     * and reports -- is the one a reader is least often after.
     *
     * @param  array<string, mixed>  $field
     */
    private function linkPriority(array $field, string $table): int
    {
        if (($field['type'] ?? '') !== WorkspaceFieldType::LINK) {
            return 0;
        }

        $variant = $field['variant'] ?? null;
        if (is_string($variant) && isset(((array) config('workspace.link_labels', []))[$variant])) {
            return 0;
        }

        return ($field['target'] ?? null) === $table ? 2 : 1;
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

        $links = 0;
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
            if (($field['locked'] ?? false) === true) {
                continue;
            }

            // employees carries eight of them; unchecked they would fill the preset and
            // leave no room for the name the reader came to find.
            if ($field['type'] === WorkspaceFieldType::LINK) {
                if ($links >= self::KEY_PRESET_LINKS) {
                    continue;
                }

                $links++;
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
