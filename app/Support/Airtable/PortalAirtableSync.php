<?php

namespace App\Support\Airtable;

use App\Modules\Core\Models\Action;
use App\Support\Core\CoreActionType;
use App\Support\Core\CoreLedger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Replays unsynced portal ledger rows into Airtable, oldest first.
 *
 * Each action becomes one or more units: a row to create or patch, or a record to delete. Most
 * actions are one row; a report is one row per line plus a delete for every line an edit dropped.
 * A dry run reads the live schema and the rows but writes nothing, anywhere. A sent run pushes
 * every unit, writes a new record's id back to its SQL row so the next change patches instead of
 * duplicating, and stamps synced_at only once every unit of the action has landed. A unit that
 * fails leaves the action unsynced with the reason recorded, so the next run retries it.
 */
final class PortalAirtableSync
{
    /** Outcomes that mean the unit is done: nothing more to send for it. */
    private const LANDED = ['synced', 'discarded', 'deleted', 'superseded', 'covered', 'nothing'];

    public function __construct(private readonly AirtableClient $airtable) {}

    /**
     * @return array{
     *     sent: bool,
     *     counts: array<string, int>,
     *     items: list<array{actionId: int, resource: ?string, actionType: string, recordId: ?string, outcome: string, table: ?string, airtableId: ?string, fields: array<string, mixed>, problems: list<string>}>
     * }
     */
    public function run(bool $send): array
    {
        $schema = $this->airtable->tables();
        $counts = array_fill_keys(['create', 'update', 'delete', 'discard', 'synced', 'covered', 'superseded', 'nothing', 'blocked', 'failed', 'skipped'], 0);
        $items = [];
        // The first unit on a row pushes that row's current state, which already holds every later one.
        $firstByRow = [];

        $actions = Action::query()
            ->where('product', CoreLedger::PRODUCT_PORTAL)
            ->whereNull('synced_at')
            ->orderBy('id')
            ->get();

        foreach ($actions as $action) {
            $units = [];
            foreach ($this->units($action, $schema, $firstByRow) as $unit) {
                if ($send && in_array($unit['outcome'], ['create', 'update', 'delete', 'discard'], true)) {
                    $unit = $this->push($action, $unit);
                }
                if (str_contains((string) $unit['recordId'], '#') && ! isset(AirtableSyncMap::for($unit['resource'])['changed_only'])) {
                    $firstByRow[(string) $unit['recordId']] ??= $unit;
                }
                $units[] = $unit;
            }

            if ($send) {
                $this->settle($action, $units);
            }
            foreach ($units as $unit) {
                $counts[$this->bucket($unit['outcome'])]++;
                $items[] = $unit;
            }
        }

        return ['sent' => $send, 'counts' => $counts, 'items' => $items];
    }

    /**
     * Plans the newest real row of every mapped resource against the live base, writing nothing,
     * so a mapping is proven before any member has produced an action for it.
     *
     * @return list<array{actionId: int, resource: ?string, actionType: string, recordId: ?string, outcome: string, table: ?string, airtableId: ?string, fields: array<string, mixed>, problems: list<string>}>
     */
    public function check(): array
    {
        $schema = $this->airtable->tables();
        $items = [];

        foreach (AirtableSyncMap::resources() as $resource) {
            $map = AirtableSyncMap::for($resource);
            if ($map === null) {
                continue;
            }
            $query = DB::connection('portal')->table($map['sql_table'])->orderByDesc('id');
            if (isset($map['sample'])) {
                $query->where($map['sample']['column'], $map['sample']['value']);
            }
            if (isset($map['changed_only'])) {
                // Changed-only resources only ever update, so the check samples a row Airtable has.
                $query->whereNotNull('airtable_record_id');
            }
            if (isset($map['discard_when'])) {
                $column = $map['discard_when']['column'];
                $query->where(fn ($where) => $where->whereNull($column)->orWhere($column, '!=', $map['discard_when']['value']));
            }
            $id = $query->value('id');
            $probe = (new Action)->forceFill(['resource' => $resource, 'action_type' => 'check', 'record_id' => (string) $id]);

            $items[] = $id === null
                ? $this->unit($probe, $map['airtable_table'], null, 'skipped', ['No SQL row to check this mapping against.'])
                : $this->row($probe, $map, (int) $id, $schema, false, true);
        }

        return $items;
    }

    /**
     * @param  array<string, array<string, array{type: string, choices: list<string>|null}>>  $schema
     * @param  array<string, array{actionId: int, resource: ?string, actionType: string, recordId: ?string, outcome: string, table: ?string, airtableId: ?string, fields: array<string, mixed>, problems: list<string>}>  $firstByRow
     * @return list<array{actionId: int, resource: ?string, actionType: string, recordId: ?string, outcome: string, table: ?string, airtableId: ?string, fields: array<string, mixed>, problems: list<string>}>
     */
    private function units(Action $action, array $schema, array $firstByRow): array
    {
        $portalOnly = AirtableSyncMap::portalOnly($action->resource);
        if ($portalOnly !== null) {
            return [$this->unit($action, null, $action->record_id, 'nothing', [$portalOnly])];
        }

        $map = AirtableSyncMap::for($action->resource);
        if ($map === null) {
            return [$this->unit($action, null, $action->record_id, 'skipped', ['Not mapped to an Airtable table yet; left for a later run.'])];
        }

        $rows = $map['rows'] ?? null;
        $payload = $this->payload($action);

        if ($action->action_type === CoreActionType::DELETE) {
            return $this->deleteUnits($action, $map, $payload);
        }

        if ($rows === null) {
            return [$this->rowOrCovered($action, $map, (int) $action->record_id, $schema, $firstByRow, false)];
        }

        $lineIds = $payload[$rows['lines']] ?? null;
        if (! is_array($lineIds)) {
            return [$this->unit($action, $map['airtable_table'], $action->record_id, 'skipped', ['Written before lines were ledgered; carries no line ids to sync.'])];
        }

        $units = [];
        foreach ($lineIds as $lineId) {
            $units[] = $this->rowOrCovered($action, $map, (int) $lineId, $schema, $firstByRow, true);
        }
        $removed = is_array($payload[$rows['removed']] ?? null) ? $payload[$rows['removed']] : [];
        $units = [...$units, ...$this->deletes($action, $map['airtable_table'], $removed)];

        return $units !== []
            ? $units
            : [$this->unit($action, $map['airtable_table'], $action->record_id, 'nothing', ['No line left to send and none Airtable held to remove.'])];
    }

    /**
     * A delete names what Airtable held in its snapshot. A snapshot written before those ids were
     * recorded cannot say, so it is left unsynced rather than guessed at; one that says "none" --
     * the row never reached Airtable -- is done with nothing to send.
     *
     * @param  array<string, mixed>  $map
     * @param  array<string, mixed>  $payload
     * @return list<array{actionId: int, resource: ?string, actionType: string, recordId: ?string, outcome: string, table: ?string, airtableId: ?string, fields: array<string, mixed>, problems: list<string>}>
     */
    private function deleteUnits(Action $action, array $map, array $payload): array
    {
        $table = $map['airtable_table'];
        $unknown = [$this->unit($action, $table, $action->record_id, 'skipped', ['This delete predates Airtable ids in the ledger; left for a manual check.'])];

        if (isset($map['rows'])) {
            $lines = is_array($payload[$map['rows']['deleted']] ?? null) ? $payload[$map['rows']['deleted']] : [];
            $ids = [];
            foreach ($lines as $line) {
                if (! is_array($line) || ! array_key_exists('airtable_record_id', $line)) {
                    return $unknown;
                }
                $ids[] = $line['airtable_record_id'];
            }
        } elseif (isset($map['deleted_id']) && array_key_exists($map['deleted_id'], $payload)) {
            $ids = [$payload[$map['deleted_id']]];
        } else {
            return $unknown;
        }

        $units = $this->deletes($action, $table, $ids);

        return $units !== []
            ? $units
            : [$this->unit($action, $table, $action->record_id, 'nothing', ['Never reached Airtable, so there is nothing to delete there.'])];
    }

    /**
     * @param  array<string, mixed>  $map
     * @param  array<string, array<string, array{type: string, choices: list<string>|null}>>  $schema
     * @param  array<string, array{actionId: int, resource: ?string, actionType: string, recordId: ?string, outcome: string, table: ?string, airtableId: ?string, fields: array<string, mixed>, problems: list<string>}>  $firstByRow
     * @return array{actionId: int, resource: ?string, actionType: string, recordId: ?string, outcome: string, table: ?string, airtableId: ?string, fields: array<string, mixed>, problems: list<string>}
     */
    private function rowOrCovered(Action $action, array $map, int $rowId, array $schema, array $firstByRow, bool $multiRow): array
    {
        $key = $map['sql_table'].'#'.$rowId;
        $first = isset($map['changed_only']) ? null : ($firstByRow[$key] ?? null);
        if ($first === null) {
            return $this->row($action, $map, $rowId, $schema, $multiRow);
        }

        $landed = in_array($first['outcome'], [...self::LANDED, 'create', 'update', 'discard'], true);

        return $this->unit(
            $action,
            $first['table'],
            $key,
            $landed ? 'covered' : $first['outcome'],
            [$landed
                ? 'Same row as #'.$first['actionId'].', which already carries its current state.'
                : 'Same row as #'.$first['actionId'].', which did not go through; retried with it next run.'],
            $first['airtableId'],
        );
    }

    /**
     * What one SQL row would become in Airtable, decided without writing anything.
     *
     * @param  array<string, mixed>  $map
     * @param  array<string, array<string, array{type: string, choices: list<string>|null}>>  $schema
     * @return array{actionId: int, resource: ?string, actionType: string, recordId: ?string, outcome: string, table: ?string, airtableId: ?string, fields: array<string, mixed>, problems: list<string>}
     */
    private function row(Action $action, array $map, int $rowId, array $schema, bool $multiRow, bool $probe = false): array
    {
        $key = $map['sql_table'].'#'.$rowId;
        $row = DB::connection('portal')->table($map['sql_table'])->where('id', $rowId)->first();
        if ($row === null) {
            // Gone by design only when a later action on the same report, or the same record,
            // explains it (an edit replaced the line, a delete removed it). Anything else is retried:
            // it may be a line whose report has not finished committing yet.
            $explained = $action->getKey() !== null && Action::query()
                ->where('product', CoreLedger::PRODUCT_PORTAL)
                ->where('resource', $action->resource)
                ->where('id', '>', $action->getKey())
                ->where($multiRow ? 'recycle_key' : 'record_id', $multiRow ? $action->recycle_key : (string) $rowId)
                ->exists();

            return $explained
                ? $this->unit($action, $map['airtable_table'], $key, 'superseded', ['Replaced or deleted later; the newer action carries this row.'])
                : $this->unit($action, $map['airtable_table'], $key, 'failed', ['The SQL row '.$key.' is not there, and no later action explains why.']);
        }

        $airtableId = is_string($row->airtable_record_id ?? null) && $row->airtable_record_id !== '' ? $row->airtable_record_id : null;

        // Withdrawn by the member: it was never really filed, so Airtable should not hold it at all.
        $discard = $map['discard_when'] ?? null;
        if ($discard !== null && is_string($row->{$discard['column']} ?? null)
            && $this->comparable($row->{$discard['column']}) === $this->comparable($discard['value'])) {
            return $this->unit($action, $map['airtable_table'], $key, 'discard', [$airtableId === null
                ? 'Cancelled before it reached Airtable; it will never be sent.'
                : 'Cancelled after it reached Airtable; its record there will be deleted.'], $airtableId);
        }

        $creating = $airtableId === null;
        // A changed-only table is Airtable's own, except where the map says the portal creates rows in
        // it too: a row Airtable has never seen has no earlier state to protect, so it goes whole.
        $createsWhole = $creating && ($map['create_full'] ?? false) === true;
        $columns = $map['fields'];
        if (isset($map['changed_only']) && ! $probe && ! $createsWhole) {
            // Airtable-maintained records: only what this action changed, so HR's edits there survive.
            if ($creating) {
                return $this->unit($action, $map['airtable_table'], $key, 'blocked', ['Only changes to records Airtable already has are synced for this table.']);
            }
            $columns = array_intersect_key($columns, array_flip($this->changedColumns($action, $map['changed_only'])));
            if ($columns === []) {
                return $this->unit($action, $map['airtable_table'], $key, 'nothing', ['None of the changed columns are synced to Airtable.'], $airtableId);
            }
        }

        [$links, $linkProblems] = $this->links($map, $row);
        $fields = $this->coerce($map['airtable_table'], $this->fitChoices(
            $map['airtable_table'],
            $this->fields([...$map, 'fields' => $columns], $row, $creating) + $links + $this->attachments($map, $row, $action),
            $schema,
            $map['aliases'] ?? [],
        ), $schema);
        $problems = [...$linkProblems, ...$this->problems($map['airtable_table'], $fields, $schema)];

        return [
            ...$this->unit($action, $map['airtable_table'], $key, $problems !== [] ? 'blocked' : ($creating ? 'create' : 'update'), $problems, $airtableId),
            'fields' => $fields,
        ];
    }

    /**
     * @param  array<mixed>  $airtableIds
     * @return list<array{actionId: int, resource: ?string, actionType: string, recordId: ?string, outcome: string, table: ?string, airtableId: ?string, fields: array<string, mixed>, problems: list<string>}>
     */
    private function deletes(Action $action, string $table, array $airtableIds): array
    {
        $units = [];
        foreach ($airtableIds as $airtableId) {
            if (is_string($airtableId) && $airtableId !== '') {
                $units[] = $this->unit($action, $table, $airtableId, 'delete', ['This line is gone from SQL; its Airtable record will be deleted.'], $airtableId);
            }
        }

        return $units;
    }

    /**
     * @param  array{actionId: int, resource: ?string, actionType: string, recordId: ?string, outcome: string, table: ?string, airtableId: ?string, fields: array<string, mixed>, problems: list<string>}  $unit
     * @return array{actionId: int, resource: ?string, actionType: string, recordId: ?string, outcome: string, table: ?string, airtableId: ?string, fields: array<string, mixed>, problems: list<string>}
     */
    private function push(Action $action, array $unit): array
    {
        $map = AirtableSyncMap::for($action->resource);
        if ($map === null || $unit['table'] === null) {
            return $unit;
        }
        [, $rowId] = array_pad(explode('#', (string) $unit['recordId'], 2), 2, null);

        try {
            if ($unit['outcome'] === 'delete') {
                $this->airtable->delete($unit['table'], (string) $unit['airtableId']);

                return [...$unit, 'outcome' => 'deleted'];
            }

            if ($unit['outcome'] === 'discard') {
                if ($unit['airtableId'] !== null) {
                    $this->airtable->delete($unit['table'], $unit['airtableId']);
                    DB::connection('portal')->table($map['sql_table'])->where('id', $rowId)->update(['airtable_record_id' => null]);
                }

                return [...$unit, 'outcome' => 'discarded'];
            }

            if ($unit['airtableId'] !== null) {
                try {
                    $this->airtable->update($unit['table'], $unit['airtableId'], $unit['fields']);

                    return [...$unit, 'outcome' => 'synced'];
                } catch (AirtableRecordGone) {
                    // Deleted in Airtable while SQL still holds the row. SQL is the truth, so the row is
                    // sent again -- except where only changed columns are ever sent, which cannot rebuild it.
                    if (isset($map['changed_only'])) {
                        return [...$unit, 'outcome' => 'blocked', 'problems' => [...$unit['problems'], 'Its Airtable record was deleted there; only changes are synced for this table, so it cannot be rebuilt.']];
                    }
                    DB::connection('portal')->table($map['sql_table'])
                        ->where('id', $rowId)
                        ->where('airtable_record_id', $unit['airtableId'])
                        ->update(['airtable_record_id' => null]);
                    $unit['fields'] = array_filter($unit['fields'], static fn (mixed $value): bool => $value !== null);
                }
            }

            $created = $this->airtable->create($unit['table'], $unit['fields']);
            // Written back only onto a row still without one: a row that changed mid-run (an edit, a
            // delete, another run) must not end up pointing at a second record.
            $written = 0;
            try {
                $written = DB::connection('portal')->table($map['sql_table'])
                    ->where('id', $rowId)
                    ->whereNull('airtable_record_id')
                    ->update(['airtable_record_id' => $created]);
            } finally {
                if ($written !== 1) {
                    // An id SQL never kept would make the retry create a second record, so undo this one.
                    $this->airtable->delete($unit['table'], $created);
                }
            }
            if ($written !== 1) {
                throw new RuntimeException('The row changed while it was being sent; the new Airtable record was removed and it retries next run.');
            }

            return [...$unit, 'outcome' => 'synced', 'airtableId' => $created];
        } catch (Throwable $error) {
            return [...$unit, 'outcome' => 'failed', 'problems' => [...$unit['problems'], $error->getMessage()]];
        }
    }

    /**
     * Stamps the action once every unit landed; records the failures when any unit failed. A
     * blocked or skipped unit leaves it quietly unsynced, since retrying cannot help until fixed.
     *
     * @param  list<array{actionId: int, resource: ?string, actionType: string, recordId: ?string, outcome: string, table: ?string, airtableId: ?string, fields: array<string, mixed>, problems: list<string>}>  $units
     */
    private function settle(Action $action, array $units): void
    {
        $failed = array_filter($units, static fn (array $unit): bool => $unit['outcome'] === 'failed');
        if ($failed !== []) {
            $messages = array_merge(...array_map(static fn (array $unit): array => $unit['problems'], $failed));
            Action::query()->whereKey($action->getKey())->update([
                'attempts' => DB::raw('attempts + 1'),
                'last_error' => mb_substr(implode(' | ', $messages), 0, 1000),
            ]);

            return;
        }

        $done = array_filter($units, static fn (array $unit): bool => in_array($unit['outcome'], self::LANDED, true));
        if ($units !== [] && count($done) === count($units)) {
            Action::query()->whereKey($action->getKey())->update(['synced_at' => Carbon::now(), 'last_error' => null]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Action $action): array
    {
        $blob = is_array($action->parameters) ? $action->parameters : [];
        $inner = $blob['parameters'] ?? $blob;

        return is_array($inner) ? $inner : [];
    }

    /**
     * The columns an action changed: a flat `fields` map, else the keys of its `changes` diff.
     *
     * @param  list<string>  $keys
     * @return list<string>
     */
    private function changedColumns(Action $action, array $keys): array
    {
        $payload = $this->payload($action);
        foreach ($keys as $key) {
            if (is_array($payload[$key] ?? null)) {
                return array_map('strval', array_keys($payload[$key]));
            }
        }

        return [];
    }

    /**
     * @param  list<string>  $problems
     * @return array{actionId: int, resource: ?string, actionType: string, recordId: ?string, outcome: string, table: ?string, airtableId: ?string, fields: array<string, mixed>, problems: list<string>}
     */
    private function unit(Action $action, ?string $table, ?string $recordId, string $outcome, array $problems = [], ?string $airtableId = null): array
    {
        return [
            'actionId' => (int) $action->getKey(),
            'resource' => $action->resource,
            'actionType' => (string) $action->action_type,
            'recordId' => $recordId,
            'outcome' => $outcome,
            'table' => $table,
            'airtableId' => $airtableId,
            'fields' => [],
            'problems' => $problems,
        ];
    }

    /**
     * @param  array{sql_table: string, airtable_table: string, fields: array<string, string>}  $map
     * @return array<string, mixed>
     */
    private function fields(array $map, object $row, bool $creating): array
    {
        $fields = [];
        foreach ($map['fields'] as $column => $field) {
            $value = $row->{$column} ?? null;
            // A create leaves an empty column out; an update sends null so a cleared value clears.
            if ($value === null && $creating) {
                continue;
            }
            $fields[$field] = $value;
        }

        return $fields;
    }

    /**
     * A link carries the linked rows' own Airtable ids, never SQL ids. A linked row Airtable has
     * never seen blocks the action, since pushing without it would silently drop the link.
     *
     * A link is either a junction walk (SQL keeps the pair in its own table, optionally narrowed by a
     * column on it, such as a member role) or, when the link names `from`, one row the row itself
     * names -- a project's lead is an email on the project, with no junction to walk.
     *
     * @param  array{links?: array<string, array{junction?: string, local?: string, foreign?: string, table: string, where?: array<string, string>, matches?: string, from?: string}>}  $map
     * @return array{0: array<string, list<string>>, 1: list<string>}
     */
    private function links(array $map, object $row): array
    {
        $fields = [];
        $problems = [];
        foreach ($map['links'] ?? [] as $field => $link) {
            $from = $link['from'] ?? null;
            if ($from !== null) {
                $source = $row->{$from} ?? null;
                // Nothing named, nothing to link; an empty value must never match a blank column.
                if (! is_string($source) || trim($source) === '') {
                    continue;
                }
                $linked = DB::connection('portal')
                    ->table($link['table'])
                    ->where((string) $link['matches'], $source)
                    ->get([$link['table'].'.id', $link['table'].'.airtable_record_id']);
            } else {
                $linked = DB::connection('portal')
                    ->table((string) $link['junction'])
                    ->join($link['table'], $link['table'].'.id', '=', (string) $link['junction'].'.'.$link['foreign'])
                    ->where((string) $link['junction'].'.'.$link['local'], $row->id)
                    ->when(isset($link['where']), fn ($query) => $query->where($link['where']))
                    ->orderBy($link['table'].'.id')
                    ->get([$link['table'].'.id', $link['table'].'.airtable_record_id']);
            }

            $ids = [];
            foreach ($linked as $target) {
                if (! is_string($target->airtable_record_id) || $target->airtable_record_id === '') {
                    $problems[] = '"'.$field.'" links '.$link['table'].'#'.$target->id.', which Airtable does not have yet.';

                    continue;
                }
                $ids[] = $target->airtable_record_id;
            }
            // Never sent empty: SQL's junctions may not hold every link Airtable has, so an empty
            // list is no evidence the link should go.
            if ($ids !== []) {
                $fields[$field] = $ids;
            }
        }

        return [$fields, $problems];
    }

    /**
     * Airtable fetches each file from its URL, so a receipt must be a public link -- Cloudinary's.
     *
     * @param  array{attachments?: array<string, array{table_name: string, field_name: string, payload_key?: string}>}  $map
     * @return array<string, list<array{url: string, filename: string}>>
     */
    private function attachments(array $map, object $row, Action $action): array
    {
        $fields = [];
        foreach ($map['attachments'] ?? [] as $field => $source) {
            $files = DB::connection('portal')
                ->table('attachments')
                ->where('table_name', $source['table_name'])
                ->where('field_name', $source['field_name'])
                ->where('record_id', $row->id)
                ->orderBy('id')
                ->get(['file_url', 'file_name']);

            $list = [];
            foreach ($files as $file) {
                if (! is_string($file->file_url) || ! str_starts_with($file->file_url, 'https://')) {
                    // Airtable replaces the whole list, so a partial one would drop the file it cannot fetch.
                    continue 2;
                }
                $list[] = ['url' => $file->file_url, 'filename' => (string) ($file->file_name ?? 'file')];
            }
            // Sent empty only when the portal itself removed the file. SQL did not import every
            // receipt Airtable holds, so an empty list alone is no evidence of a removal.
            if ($list !== [] || (isset($source['payload_key']) && $this->portalRemoved($action, (int) $row->id, $source['payload_key']))) {
                $fields[$field] = $list;
            }
        }

        return $fields;
    }

    /**
     * True when the ledger shows this row once carried the file under `$key` and its latest entry
     * carries none: a removal the portal made, not a receipt SQL simply never imported.
     */
    private function portalRemoved(Action $action, int $rowId, string $key): bool
    {
        $seen = false;
        $latest = null;
        $history = Action::query()
            ->where('product', CoreLedger::PRODUCT_PORTAL)
            ->where('resource', $action->resource)
            ->where('record_id', (string) $rowId)
            ->orderBy('id')
            ->get(['parameters']);

        foreach ($history as $entry) {
            $payload = $this->payload($entry);
            if (! array_key_exists($key, $payload)) {
                continue;
            }
            $latest = $payload[$key];
            $seen = $seen || $latest !== null;
        }

        return $seen && $latest === null;
    }

    /**
     * MySQL returns DECIMAL as a string and typecast is off, so a number field must get a number; a
     * date field gets the date alone. Decided by the Airtable field's type, never by the value's
     * look, so a reason that happens to start with a date is sent whole.
     *
     * @param  array<string, mixed>  $fields
     * @param  array<string, array<string, array{type: string, choices: list<string>|null}>>  $schema
     * @return array<string, mixed>
     */
    private function coerce(string $table, array $fields, array $schema): array
    {
        foreach ($fields as $name => $value) {
            $type = $schema[$table][$name]['type'] ?? null;
            if (in_array($type, ['number', 'currency', 'percent'], true) && is_numeric($value)) {
                $fields[$name] = $value + 0;
            } elseif ($type === 'date' && is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}/', $value) === 1) {
                $fields[$name] = substr($value, 0, 10);
            }
        }

        return $fields;
    }

    /**
     * Rewrites each select value to the option Airtable offers for it, matched against the live
     * base rather than a fixed table, so "01 Vacation Leave" still lands on "Vacation Leave" after
     * either side renames or renumbers its labels. A value with no counterpart is left as it was,
     * and problems() blocks it.
     *
     * @param  array<string, mixed>  $fields
     * @param  array<string, array<string, array{type: string, choices: list<string>|null}>>  $schema
     * @param  array<string, array<string, string>>  $aliases
     * @return array<string, mixed>
     */
    private function fitChoices(string $table, array $fields, array $schema, array $aliases = []): array
    {
        foreach ($fields as $name => $value) {
            $choices = $schema[$table][$name]['choices'] ?? null;
            if ($choices === null || ! is_string($value)) {
                continue;
            }
            foreach ($aliases[$name] ?? [] as $from => $to) {
                if ($this->comparable($from) === $this->comparable($value)) {
                    $value = $to;
                    break;
                }
            }
            $exact = array_values(array_filter($choices, fn (string $choice): bool => $this->comparable($choice, false) === $this->comparable($value, false)));
            $loose = array_values(array_filter($choices, fn (string $choice): bool => $this->comparable($choice) === $this->comparable($value)));
            // "2 Day" must not land on "1 Day" just because both read "day" without their numbers.
            if (count($exact) === 1) {
                $fields[$name] = $exact[0];
            } elseif (count($loose) === 1) {
                $fields[$name] = $loose[0];
            }
        }

        return $fields;
    }

    // Case, spacing and (loosely) a leading "01 " / "3." numbering are presentation, not meaning.
    private function comparable(string $label, bool $dropNumbering = true): string
    {
        if ($dropNumbering) {
            $label = (string) preg_replace('/^\s*\d+\s*[.)\-]?\s*/', '', $label);
        }

        return strtolower((string) preg_replace('/\s+/', ' ', trim($label)));
    }

    /**
     * Checked against the live base, so a mapping that has drifted from Airtable never reaches it.
     *
     * @param  array<string, mixed>  $fields
     * @param  array<string, array<string, array{type: string, choices: list<string>|null}>>  $schema
     * @return list<string>
     */
    private function problems(string $table, array $fields, array $schema): array
    {
        if (! isset($schema[$table])) {
            return ['Airtable has no table named "'.$table.'".'];
        }

        $problems = [];
        foreach ($fields as $name => $value) {
            $field = $schema[$table][$name] ?? null;
            if ($field === null) {
                $problems[] = 'Airtable table "'.$table.'" has no field "'.$name.'".';

                continue;
            }
            if ($field['choices'] !== null && $value !== null && ! in_array((string) $value, $field['choices'], true)) {
                $problems[] = '"'.$name.'" has no option "'.$value.'" (Airtable offers: '.implode(', ', $field['choices']).').';
            }
        }

        return $problems;
    }

    private function bucket(string $outcome): string
    {
        return match ($outcome) {
            'discarded', 'deleted' => 'synced',
            'create', 'update', 'delete', 'discard', 'synced', 'covered', 'superseded', 'nothing', 'blocked', 'failed' => $outcome,
            default => 'skipped',
        };
    }
}
