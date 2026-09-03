<?php

namespace App\Support\Workspace;

use App\Support\Core\CoreLedger;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * The one write path out of the workspace: correct a scalar value on one record.
 *
 * These are live production databases, so this is deliberately the narrowest edit
 * that is still worth having. A cell, a record, a column the schema already declared
 * editable -- never a link, never a key, never a column the actor may not read. Every
 * accepted change lands in core.actions with its previous value, so an edit made here
 * is as reviewable as one made through a portal endpoint.
 */
final class WorkspaceWriter
{
    /**
     * Columns changed in one request. A grid edits a cell at a time; a larger payload
     * is a script, and a script should be using the portal endpoints.
     */
    private const MAX_COLUMNS = 12;

    public function __construct(
        private readonly WorkspaceIntrospector $introspector,
        private readonly WorkspaceSchema $schema,
        private readonly WorkspaceGrid $grid,
        private readonly CoreLedger $ledger,
    ) {}

    /**
     * A product whose writes the ledger can record. core is its own bookkeeping and is
     * never edited from here.
     */
    public static function isWritable(string $product): bool
    {
        return in_array($product, (array) config('workspace.writable_products', []), true);
    }

    /**
     * @param  array<string, mixed>  $changes  column => new value
     * @param  array<string, mixed>  $expected  column => the value the editor last saw
     * @return array{product: string, table: string, id: mixed, changed: array<string, array{from: mixed, to: mixed}>, action_id: int, data: array<string, mixed>, fields: list<array<string, mixed>>}
     */
    public function update(
        string $product,
        string $table,
        string $id,
        array $changes,
        array $expected,
        WorkspaceAccess $access,
    ): array {
        $access->assertCanEdit();

        if (! self::isWritable($product)) {
            abort(422, 'This database is not editable from the workspace.');
        }

        $blueprint = $this->schema->table($product, $table, $access);
        $key = $this->introspector->surrogateKey($product, $table);
        if ($key === null) {
            abort(422, 'That table has no single-column key, so a row cannot be addressed.');
        }

        if ($changes === []) {
            abort(422, 'No columns were given to change.');
        }

        if (count($changes) > self::MAX_COLUMNS) {
            abort(422, 'Change at most '.self::MAX_COLUMNS.' columns in one request.');
        }

        $fields = [];
        foreach ($blueprint['fields'] as $field) {
            $fields[(string) $field['name']] = $field;
        }

        $connection = $this->introspector->connection($product);
        $current = $connection->table($table)->where($key, $id)->first();
        if ($current === null) {
            abort(404, 'That record was not found.');
        }

        $updates = [];
        $changed = [];
        foreach ($changes as $column => $value) {
            $field = is_string($column) ? ($fields[$column] ?? null) : null;
            if ($field === null || ($field['editable'] ?? false) !== true) {
                abort(422, 'Column ['.(is_string($column) ? $column : '?').'] cannot be edited here.');
            }

            $before = ((array) $current)[$column] ?? null;
            $after = $this->coerce($product, $table, $field, $value);

            if (array_key_exists($column, $expected)) {
                $this->assertUnchanged($column, $before, $expected[$column]);
            }

            if ($this->same($before, $after)) {
                continue;
            }

            $updates[$column] = $after;
            $changed[$column] = ['from' => $before, 'to' => $after];
        }

        if ($updates === []) {
            return $this->result($product, $table, $id, [], 0, $access);
        }

        $connection->table($table)->where($key, $id)->update($updates);
        WorkspaceIntrospector::flush();

        $actionId = $this->ledger->recordEdit(
            $product,
            $this->introspector->database($product),
            ['table' => $table, 'key' => $key, 'changes' => $changed],
            $table,
            (string) $id,
            null,
            $access->actorId(),
            $access->actorIdNo(),
        );

        return $this->result($product, $table, $id, $changed, $actionId, $access);
    }

    /**
     * @param  array<string, array{from: mixed, to: mixed}>  $changed
     * @return array{product: string, table: string, id: mixed, changed: array<string, array{from: mixed, to: mixed}>, action_id: int, data: array<string, mixed>, fields: list<array<string, mixed>>}
     */
    private function result(string $product, string $table, string $id, array $changed, int $actionId, WorkspaceAccess $access): array
    {
        $record = $this->grid->record($product, $table, $id, $access);

        return [
            'product' => $product,
            'table' => $table,
            'id' => $record['id'],
            'changed' => $changed,
            'action_id' => $actionId,
            'data' => $record['data'],
            'fields' => $record['fields'],
        ];
    }

    /**
     * Someone else got there first. Refusing beats overwriting a value this editor
     * never saw.
     */
    private function assertUnchanged(string $column, mixed $before, mixed $expected): void
    {
        $seen = $expected === null ? null : (string) $expected;
        $actual = $before === null ? null : (string) $before;

        if ($seen !== $actual) {
            abort(409, 'Column ['.$column.'] changed since you loaded it. Reload the row and try again.');
        }
    }

    /**
     * Shape the value the way the column stores it, and refuse anything the column
     * cannot hold. The grid has no model to validate against, so this is the only
     * check between a typed cell and the live table.
     *
     * @param  array<string, mixed>  $field
     */
    private function coerce(string $product, string $table, array $field, mixed $value): mixed
    {
        $name = (string) $field['name'];

        if (is_array($value) || is_object($value)) {
            abort(422, 'Column ['.$name.'] takes a single value.');
        }

        if ($value === null || $value === '') {
            if ($value === '' && ! in_array($field['type'], [WorkspaceFieldType::TEXT, WorkspaceFieldType::LONGTEXT, WorkspaceFieldType::TITLE], true)) {
                $value = null;
            }

            if ($value === null && ($field['nullable'] ?? true) !== true) {
                abort(422, 'Column ['.$name.'] cannot be emptied.');
            }

            return $value;
        }

        return match ($field['type']) {
            WorkspaceFieldType::BOOLEAN => $this->coerceBoolean($name, $value),
            WorkspaceFieldType::INTEGER => $this->coerceInteger($name, $value),
            WorkspaceFieldType::DECIMAL, WorkspaceFieldType::PERCENT => $this->coerceDecimal($name, $value),
            WorkspaceFieldType::DATE => $this->coerceDate($name, $value, 'Y-m-d'),
            WorkspaceFieldType::DATETIME => $this->coerceDate($name, $value, 'Y-m-d H:i:s'),
            WorkspaceFieldType::EMAIL => $this->coerceFiltered($name, $value, FILTER_VALIDATE_EMAIL, 'is not an email address'),
            WorkspaceFieldType::URL => $this->coerceFiltered($name, $value, FILTER_VALIDATE_URL, 'is not a URL'),
            default => $this->coerceString($product, $table, $name, $value),
        };
    }

    private function coerceBoolean(string $name, mixed $value): int
    {
        $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($parsed === null) {
            abort(422, 'Column ['.$name.'] takes true or false.');
        }

        return $parsed ? 1 : 0;
    }

    private function coerceInteger(string $name, mixed $value): int
    {
        if (! is_numeric($value) || (string) (int) $value !== trim((string) $value)) {
            abort(422, 'Column ['.$name.'] takes a whole number.');
        }

        return (int) $value;
    }

    private function coerceDecimal(string $name, mixed $value): string
    {
        if (! is_numeric($value)) {
            abort(422, 'Column ['.$name.'] takes a number.');
        }

        return (string) $value;
    }

    private function coerceDate(string $name, mixed $value, string $format): string
    {
        try {
            return Carbon::parse((string) $value)->format($format);
        } catch (Throwable) {
            abort(422, 'Column ['.$name.'] takes a date.');
        }
    }

    private function coerceFiltered(string $name, mixed $value, int $filter, string $complaint): string
    {
        $text = trim((string) $value);
        if (filter_var($text, $filter) === false) {
            abort(422, 'Column ['.$name.'] '.$complaint.'.');
        }

        return $text;
    }

    /**
     * MySQL in a non-strict mode truncates silently, which would show the editor a
     * value the database never stored.
     */
    private function coerceString(string $product, string $table, string $name, mixed $value): string
    {
        $text = (string) $value;

        foreach ($this->introspector->columns($product)[$table] ?? [] as $column) {
            if ($column['name'] !== $name || $column['max_length'] === null) {
                continue;
            }

            if (mb_strlen($text) > $column['max_length']) {
                abort(422, 'Column ['.$name.'] holds at most '.$column['max_length'].' characters.');
            }
        }

        return $text;
    }

    /**
     * Loose on purpose: the database hands back "1" where the request sent 1, and a
     * decimal column returns "8.00" for 8.
     */
    private function same(mixed $before, mixed $after): bool
    {
        if ($before === null || $after === null) {
            return $before === $after;
        }

        if (is_numeric($before) && is_numeric($after)) {
            return (float) $before === (float) $after;
        }

        return (string) $before === (string) $after;
    }
}
