<?php

namespace App\Support\Airtable;

use App\Modules\Core\Models\Action;
use App\Support\Core\CoreActionType;
use App\Support\Core\CoreLedger;
use GuzzleHttp\Exception\ConnectException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
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

    // Marks a create whose answer never came back: Airtable may hold it, so it is not sent again blind.
    public const UNCERTAIN_CREATE = 'Create may already be in Airtable (its answer was lost); check there, then run with --retry-creates.';

    // A row still missing this long after its action was written is gone, not a write still committing.
    private const GONE_AFTER_MINUTES = 10;

    // Field types Airtable fills itself; a write to one is refused.
    private const COMPUTED = ['formula', 'rollup', 'multipleLookupValues', 'count', 'autoNumber', 'createdTime', 'lastModifiedTime', 'createdBy', 'lastModifiedBy', 'button', 'aiText'];

    // attempts is a SMALLINT UNSIGNED; an action failing forever must never overflow it and stop every run.
    private const MAX_ATTEMPTS = 65000;

    private bool $retryCreates = false;

    public function __construct(private readonly AirtableClient $airtable) {}

    /**
     * @return array{
     *     sent: bool,
     *     counts: array<string, int>,
     *     items: list<array{actionId: int, resource: ?string, actionType: string, recordId: ?string, outcome: string, table: ?string, airtableId: ?string, fields: array<string, mixed>, problems: list<string>}>,
     *     stopped: bool
     * }
     */
    public function run(bool $send, int $settleSeconds = 0, bool $retryCreates = false, ?int $afterId = null, bool $backoff = false, ?int $budgetSeconds = null): array
    {
        $this->retryCreates = $retryCreates;
        $schema = $this->airtable->tables();
        $counts = array_fill_keys(['create', 'update', 'delete', 'discard', 'synced', 'covered', 'superseded', 'nothing', 'blocked', 'failed', 'skipped'], 0);
        $items = [];
        // The first unit on a row pushes that row's current state, which already holds every later one.
        $firstByRow = [];
        // Past its budget a run takes no new action (2026-10-20): each settles on its own, so the next run
        // carries on, and a run never outlives the lock that keeps two sends apart.
        $deadline = $budgetSeconds === null ? null : Carbon::now()->addSeconds($budgetSeconds);
        $stopped = false;

        foreach ($this->pending($settleSeconds, $afterId, $backoff) as $action) {
            if ($deadline !== null && Carbon::now()->gte($deadline)) {
                $stopped = true;
                break;
            }
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

        return ['sent' => $send, 'counts' => $counts, 'items' => $items, 'stopped' => $stopped];
    }

    /**
     * Whether a scheduled run has anything to try, read from SQL alone (2026-10-20): an idle minute
     * then asks Airtable nothing, which matters on a plan that counts every call.
     */
    public function hasDueWork(int $settleSeconds): bool
    {
        return $this->pending($settleSeconds, null, true)->isNotEmpty();
    }

    /**
     * Settles every unsynced action up to an id without sending it (2026-10-20): what was ledgered before
     * an import names rows the import has since renumbered, so replaying it could touch the wrong records.
     */
    public function skipThrough(int $actionId): int
    {
        return Action::query()
            ->where('product', CoreLedger::PRODUCT_PORTAL)
            ->whereNull('synced_at')
            ->where('id', '<=', $actionId)
            ->update(['synced_at' => Carbon::now(), 'last_error' => 'Not sent: settled by --skip-through='.$actionId.'.']);
    }

    /**
     * @return Collection<int, Action>
     */
    private function pending(int $settleSeconds, ?int $afterId, bool $backoff): Collection
    {
        // ponytail: loads every unsynced action at once; page it with lazyById if a backlog ever runs to tens of thousands.
        return Action::query()
            ->where('product', CoreLedger::PRODUCT_PORTAL)
            ->whereNull('synced_at')
            // The ledger commits a moment before the portal row does; a fresh action waits a run.
            ->when($settleSeconds > 0, fn ($query) => $query->where('created_at', '<=', Carbon::now()->subSeconds($settleSeconds)))
            // Only what came after a given action: a test scenario judges its own writes alone.
            ->when($afterId !== null, fn ($query) => $query->where('id', '>', $afterId))
            ->orderBy('id')
            ->get()
            ->when($backoff, fn ($actions) => $actions->filter(fn (Action $action): bool => self::due($action)))
            ->values();
    }

    /**
     * A try that did not land waits longer each time, counted from that try (2026-10-20): 1, 2, 4 ...
     * minutes, doubling up to six hours, then every six hours. A blip clears in minutes; a value Airtable
     * refuses stops costing a call a minute, yet lands on its own once someone fixes the base.
     */
    private static function due(Action $action): bool
    {
        $attempts = (int) $action->attempts;
        $since = $action->attempted_at ?? $action->created_at;
        if ($attempts === 0 || $since === null) {
            return true;
        }

        return Carbon::parse($since)->addMinutes(min(2 ** min($attempts - 1, 9), 360))->lte(Carbon::now());
    }

    /**
     * Names a base that holds none of the records this database points at (2026-10-20): sent to
     * another base -- the test base on production -- every update would read as a deleted record,
     * clear its airtable_record_id and create a copy there. The newest id of up to five tables is
     * asked; one found is enough, since a right base answers for nearly every row.
     */
    public function wrongBase(): ?string
    {
        $tables = [];
        foreach (AirtableSyncMap::resources() as $resource) {
            $map = AirtableSyncMap::for($resource);
            if ($map !== null) {
                $tables[$map['sql_table']] ??= $map['airtable_table'];
            }
        }

        $asked = 0;
        foreach ($tables as $sqlTable => $airtableTable) {
            $recordId = DB::connection('portal')->table($sqlTable)->whereNotNull('airtable_record_id')->orderByDesc('id')->value('airtable_record_id');
            if (! is_string($recordId) || $recordId === '') {
                continue;
            }
            if ($this->airtable->exists($airtableTable, $recordId)) {
                return null;
            }
            if (++$asked === 5) {
                break;
            }
        }

        return $asked === 0
            ? null
            : 'The Airtable base holds none of the '.$asked.' records this database points at, so AIRTABLE_BASE_ID names another base. Nothing was sent.';
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
        // A table whose deletes the map does not carry (projects: the record is Airtable's to keep) has
        // nothing to send, so the action settles instead of coming back every run.
        if (! isset($map['rows']) && ! isset($map['deleted_id'])) {
            return [$this->unit($action, $table, $action->record_id, 'nothing', ['Deletes are not synced for this table; Airtable keeps its record.'])];
        }
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
            // A create whose answer was lost may have landed for this line: that waits for a person and
            // --retry-creates, never a silent supersede that would leave a duplicate behind (2026-10-20).
            if (! $this->retryCreates && str_contains((string) $action->last_error, self::UNCERTAIN_CREATE)) {
                return $this->unit($action, $map['airtable_table'], $key, 'failed', [self::UNCERTAIN_CREATE]);
            }
            // Gone by design only when a later action on the same report, or the same record,
            // explains it (an edit replaced the line, a delete removed it). Anything else is retried:
            // it may be a line whose report has not finished committing yet.
            $scope = $multiRow ? $action->recycle_key : (string) $rowId;
            $explained = $action->getKey() !== null && $scope !== null && Action::query()
                ->where('product', CoreLedger::PRODUCT_PORTAL)
                ->where('resource', $action->resource)
                ->where('id', '>', $action->getKey())
                ->where($multiRow ? 'recycle_key' : 'record_id', $scope)
                ->exists();
            // Long missing is gone: a later edit or delete under another resource replaced it.
            $stale = $action->created_at !== null && Carbon::parse($action->created_at)->lt(Carbon::now()->subMinutes(self::GONE_AFTER_MINUTES));

            return $explained || $stale
                ? $this->unit($action, $map['airtable_table'], $key, 'superseded', ['Replaced or deleted later; the newer action carries this row.'])
                : $this->unit($action, $map['airtable_table'], $key, 'failed', ['The SQL row '.$key.' is not there, and no later action explains why.']);
        }

        $airtableId = is_string($row->airtable_record_id ?? null) && $row->airtable_record_id !== '' ? $row->airtable_record_id : null;

        // In the Recycle Bin: a record Airtable has stays Airtable's, and one it never had is not sent.
        if (($row->deleted_at ?? null) !== null && ! $probe) {
            return $this->unit($action, $map['airtable_table'], $key, 'nothing', [$airtableId === null
                ? 'Moved to the Recycle Bin before it reached Airtable; it will not be sent.'
                : 'In the Recycle Bin; Airtable keeps its own record.'], $airtableId);
        }

        // Withdrawn by the member: it was never really filed, so Airtable should not hold it at all. A
        // changed-only edit deletes the record only when it is the edit that cancelled it, so a typo fixed
        // on a cancelled row never takes a record out of Airtable (2026-10-20).
        $discard = $map['discard_when'] ?? null;
        if ($discard !== null && is_string($row->{$discard['column']} ?? null)
            && $this->comparable($row->{$discard['column']}) === $this->comparable($discard['value'])
            && ($airtableId === null || $probe || ! isset($map['changed_only'])
                || in_array($discard['column'], $this->changedColumns($action, $map['changed_only']), true))) {
            return $this->unit($action, $map['airtable_table'], $key, 'discard', [$airtableId === null
                ? 'Cancelled before it reached Airtable; it will never be sent.'
                : 'Cancelled after it reached Airtable; its record there will be deleted.'], $airtableId);
        }

        $creating = $airtableId === null;
        // A changed-only table is Airtable's own, except where the map says the portal creates rows in
        // it too: a row Airtable has never seen has no earlier state to protect, so it goes whole.
        $createsWhole = $creating && ($map['create_full'] ?? false) === true;
        $columns = $map['fields'];
        // Null means everything: a full-row resource, a whole create, or a check.
        $changed = null;
        if (isset($map['changed_only']) && ! $probe && ! $createsWhole) {
            // Airtable-maintained records: only what this action changed, so HR's edits there survive.
            if ($creating) {
                return $this->unit($action, $map['airtable_table'], $key, 'blocked', ['Only changes to records Airtable already has are synced for this table.']);
            }
            $changed = $this->changedColumns($action, $map['changed_only']);
            $columns = array_intersect_key($columns, array_flip($changed));
        }

        [$links, $linkProblems] = $this->links($map, $row, $changed);
        $files = $this->attachments($map, $row, $action, $creating);
        if ($changed !== null && $columns === [] && $links === [] && $linkProblems === [] && $files === []) {
            return $this->unit($action, $map['airtable_table'], $key, 'nothing', ['None of the changed columns are synced to Airtable.'], $airtableId);
        }
        $fields = $this->fitChoices(
            $map['airtable_table'],
            $this->coerce($map['airtable_table'], $this->fields([...$map, 'fields' => $columns], $row, $creating) + $links + $files, $schema),
            $schema,
            $map['aliases'] ?? [],
        );
        // A lenient choice Airtable does not offer is left out rather than blocking the whole row.
        $notes = [];
        foreach ($map['lenient'] ?? [] as $field) {
            $choices = $schema[$map['airtable_table']][$field]['choices'] ?? null;
            if ($choices !== null && is_string($fields[$field] ?? null) && ! in_array($fields[$field], $choices, true)) {
                $notes[] = '"'.$field.'" left out: Airtable has no option "'.$fields[$field].'".';
                unset($fields[$field]);
            }
        }
        $problems = [...$linkProblems, ...$this->problems($map['airtable_table'], $fields, $schema)];
        // Everything an edit changed was left out: there is no update to send, and an empty one is refused.
        if ($changed !== null && $fields === [] && $problems === []) {
            return $this->unit($action, $map['airtable_table'], $key, 'nothing', $notes, $airtableId);
        }

        return [
            ...$this->unit($action, $map['airtable_table'], $key, $problems !== [] ? 'blocked' : ($creating ? 'create' : 'update'), [...$problems, ...$notes], $airtableId),
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
                        return [...$unit, 'outcome' => 'failed', 'problems' => [...$unit['problems'], 'Its Airtable record was deleted there; only changes are synced for this table, so it cannot be rebuilt.']];
                    }
                    DB::connection('portal')->table($map['sql_table'])
                        ->where('id', $rowId)
                        ->where('airtable_record_id', $unit['airtableId'])
                        ->update(['airtable_record_id' => null]);
                    $unit['fields'] = array_filter($unit['fields'], static fn (mixed $value): bool => $value !== null);
                }
            }

            if (! $this->retryCreates && (str_contains((string) $action->last_error, self::UNCERTAIN_CREATE) || $this->heldOnRow($action, $map, (int) $rowId))) {
                return [...$unit, 'outcome' => 'failed', 'problems' => [...$unit['problems'], self::UNCERTAIN_CREATE]];
            }
            // Marked before the call (2026-10-20): a run killed mid-create holds the row instead of creating
            // it twice; settling the action replaces the mark either way.
            if ($action->getKey() !== null) {
                Action::query()->whereKey($action->getKey())->update(['last_error' => self::UNCERTAIN_CREATE]);
            }
            try {
                $created = $this->airtable->create($unit['table'], $unit['fields']);
            } catch (ConnectionException $error) {
                return [...$unit, 'outcome' => 'failed', 'problems' => [...$unit['problems'], self::neverSent($error) ? $error->getMessage() : self::UNCERTAIN_CREATE]];
            }
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
     * blocked or skipped unit leaves it unsynced with its reason kept, so a lasting blocker shows
     * outside a dry run too, but counts no attempt: retrying cannot help until it is fixed.
     *
     * @param  list<array{actionId: int, resource: ?string, actionType: string, recordId: ?string, outcome: string, table: ?string, airtableId: ?string, fields: array<string, mixed>, problems: list<string>}>  $units
     */
    private function settle(Action $action, array $units): void
    {
        if ($units === []) {
            return;
        }
        $done = array_filter($units, static fn (array $unit): bool => in_array($unit['outcome'], self::LANDED, true));
        if (count($done) === count($units)) {
            Action::query()->whereKey($action->getKey())->update(['synced_at' => Carbon::now(), 'last_error' => null]);

            return;
        }

        // Anything short of landing is a try (2026-10-20), so the scheduled run backs off a blocked or
        // skipped row too, instead of planning it again every minute.
        $waiting = array_values(array_filter($units, static fn (array $unit): bool => ! in_array($unit['outcome'], self::LANDED, true)));
        $reasons = array_values(array_unique(array_merge(...array_map(static fn (array $unit): array => $unit['problems'], $waiting))));
        // The lost-create hold leads, so a long message can never cut it off and re-arm a blind create.
        usort($reasons, static fn (string $a, string $b): int => ($b === self::UNCERTAIN_CREATE) <=> ($a === self::UNCERTAIN_CREATE));
        Action::query()->whereKey($action->getKey())->update([
            'attempts' => DB::raw('LEAST(attempts + 1, '.self::MAX_ATTEMPTS.')'),
            'attempted_at' => Carbon::now(),
            'last_error' => $reasons === [] ? null : mb_substr(implode(' | ', $reasons), 0, 1000),
        ]);
    }

    // cURL could not resolve, reach or shake hands with the host: the request never left, so nothing landed.
    private static function neverSent(ConnectionException $error): bool
    {
        $previous = $error->getPrevious();
        $errno = $previous instanceof ConnectException ? ($previous->getHandlerContext()['errno'] ?? null) : null;

        return in_array($errno, [6, 7, 35], true);
    }

    /**
     * Another unsynced action on this row holds a lost create: creating now could put a second record
     * beside one that may already be there, so this waits for --retry-creates too (2026-10-20).
     *
     * @param  array{sql_table: string}  $map
     */
    private function heldOnRow(Action $action, array $map, int $rowId): bool
    {
        $resources = array_values(array_filter(
            AirtableSyncMap::resources(),
            static fn (string $resource): bool => (AirtableSyncMap::for($resource)['sql_table'] ?? null) === $map['sql_table'],
        ));

        return Action::query()
            ->where('product', CoreLedger::PRODUCT_PORTAL)
            ->whereNull('synced_at')
            ->when($action->getKey() !== null, fn ($query) => $query->whereKeyNot($action->getKey()))
            ->where('last_error', 'like', '%'.self::UNCERTAIN_CREATE.'%')
            ->where(fn ($query) => $query
                ->where(fn ($row) => $row->whereIn('resource', $resources)->where('record_id', (string) $rowId))
                ->when($action->recycle_key !== null, fn ($row) => $row->orWhere('recycle_key', $action->recycle_key)))
            ->exists();
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
     * The columns an action changed: a flat `fields` map, the keys of its `changes` diff, or a plain
     * list of column names. The list is how a private column is named: the ledger strips government
     * and bank keys from every payload, so their values never reach it, and the sync reads SQL anyway.
     *
     * @param  list<string>  $keys
     * @return list<string>
     */
    private function changedColumns(Action $action, array $keys): array
    {
        $payload = $this->payload($action);
        foreach ($keys as $key) {
            $value = $payload[$key] ?? null;
            if (is_array($value)) {
                return array_map('strval', array_is_list($value) ? $value : array_keys($value));
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
     * @param  array{sql_table: string, airtable_table: string, fields: array<string, string>, bracketed?: array<string, string>}  $map
     * @return array<string, mixed>
     */
    private function fields(array $map, object $row, bool $creating): array
    {
        $fields = [];
        foreach ($map['fields'] as $column => $field) {
            $value = $row->{$column} ?? null;
            // A column sent with another in brackets: "Management (MIS)" for a department's own row.
            $suffix = isset($map['bracketed'][$column]) ? trim((string) ($row->{$map['bracketed'][$column]} ?? '')) : '';
            if ($suffix !== '' && is_string($value)) {
                $value .= ' ('.$suffix.')';
            }
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
     * On a changed-only update ($changed names what the action changed) only a link the action
     * changed is sent, and then even empty, since the portal itself removed it. Otherwise every link
     * goes, and never empty: SQL's junctions may not hold every link Airtable has.
     *
     * @param  array{links?: array<string, array{junction?: string, local?: string, foreign?: string, table: string, where?: array<string, string>, matches?: string, from?: string, column?: string}>}  $map
     * @param  list<string>|null  $changed
     * @return array{0: array<string, list<string>>, 1: list<string>}
     */
    private function links(array $map, object $row, ?array $changed = null): array
    {
        $fields = [];
        $problems = [];
        foreach ($map['links'] ?? [] as $field => $link) {
            $column = $link['column'] ?? $link['from'] ?? null;
            if ($changed !== null && ($column === null || ! in_array($column, $changed, true))) {
                continue;
            }
            $from = $link['from'] ?? null;
            if ($from !== null) {
                $source = $row->{$from} ?? null;
                // Nothing named, nothing to link; an empty value must never match a blank column.
                if (! is_string($source) || trim($source) === '') {
                    if ($changed !== null) {
                        $fields[$field] = [];
                    }

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
            if ($ids !== [] || $changed !== null) {
                $fields[$field] = $ids;
            }
        }

        return [$fields, $problems];
    }

    /**
     * Airtable fetches each file from its URL, so a receipt must be a public link -- Cloudinary's.
     *
     * Sent on a create, or when an unsent action on the row carried the file's key: re-sending on every
     * update would make Airtable fetch each file again, and replace a good copy if the link went stale.
     * Every pending action counts, since the first one pushes the row and covers the later ones.
     *
     * @param  array{attachments?: array<string, array{table_name: string, field_name: string, payload_key?: string, changed_key?: string}>}  $map
     * @return array<string, list<array{url: string, filename: string}>>
     */
    private function attachments(array $map, object $row, Action $action, bool $creating = true): array
    {
        $fields = [];
        $pending = $creating || ($map['attachments'] ?? []) === [] ? [] : $this->pendingKeys($action, (int) $row->id);
        foreach ($map['attachments'] ?? [] as $field => $source) {
            $key = $source['changed_key'] ?? $source['payload_key'] ?? null;
            $changedHere = ! $creating && $key !== null && isset($pending[$key]);
            if (! $creating && ! $changedHere) {
                continue;
            }
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
            // Sent empty only when the portal itself removed the file: a slot the action changed, or a
            // receipt the ledger shows it held. SQL did not import every file Airtable holds, so an
            // empty list alone is no evidence of a removal.
            $removed = isset($source['payload_key'])
                ? $this->portalRemoved($action, (int) $row->id, $source['payload_key'])
                : $changedHere;
            if ($list !== [] || $removed) {
                $fields[$field] = $list;
            }
        }

        return $fields;
    }

    /**
     * Every payload key the row's unsent actions carry, this one included.
     *
     * @return array<string, true>
     */
    private function pendingKeys(Action $action, int $rowId): array
    {
        $keys = array_fill_keys(array_keys($this->payload($action)), true);
        $pending = Action::query()
            ->where('product', CoreLedger::PRODUCT_PORTAL)
            ->where('resource', $action->resource)
            ->where('record_id', (string) $rowId)
            ->whereNull('synced_at')
            ->get(['parameters']);
        foreach ($pending as $entry) {
            $keys += array_fill_keys(array_keys($this->payload($entry)), true);
        }

        return $keys;
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
            // A blank is no value: Airtable refuses '' for a number, date or choice, and clears on null.
            if ($value === '' && in_array($type, ['number', 'currency', 'percent', 'date', 'singleSelect', 'multipleSelects', 'phoneNumber', 'email', 'url'], true)) {
                $fields[$name] = null;
            } elseif (in_array($type, ['number', 'currency', 'percent'], true) && is_numeric($value)) {
                $fields[$name] = $value + 0;
            } elseif ($type === 'date' && is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}/', $value) === 1) {
                $fields[$name] = substr($value, 0, 10);
            } elseif ($type === 'checkbox') {
                $fields[$name] = in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'y'], true);
            } elseif ($type === 'multipleSelects' && is_string($value)) {
                $fields[$name] = array_values(array_filter(array_map('trim', explode(',', $value)), static fn (string $part): bool => $part !== ''));
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
            if ($choices === null) {
                continue;
            }
            if (is_string($value)) {
                $fields[$name] = $this->fitChoice($value, $choices, $aliases[$name] ?? []);
            } elseif (is_array($value)) {
                $fields[$name] = array_map(fn (mixed $part): mixed => is_string($part) ? $this->fitChoice($part, $choices, $aliases[$name] ?? []) : $part, $value);
            }
        }

        return $fields;
    }

    /**
     * @param  list<string>  $choices
     * @param  array<string, string>  $aliases
     */
    private function fitChoice(string $value, array $choices, array $aliases): string
    {
        foreach ($aliases as $from => $to) {
            if ($this->comparable($from) === $this->comparable($value)) {
                $value = $to;
                break;
            }
        }
        $exact = array_values(array_filter($choices, fn (string $choice): bool => $this->comparable($choice, false) === $this->comparable($value, false)));
        $loose = array_values(array_filter($choices, fn (string $choice): bool => $this->comparable($choice) === $this->comparable($value)));

        return count($exact) === 1 ? $exact[0] : (count($loose) === 1 ? $loose[0] : $value);
    }

    // Case, spacing and (loosely) a leading index -- "01 ", "3.", "2)" -- are presentation, not meaning.
    // A plain number is part of the label: "2 Day" is not "1 Day", nor "3D Scan" "2D Scan".
    private function comparable(string $label, bool $dropNumbering = true): string
    {
        if ($dropNumbering) {
            $label = (string) preg_replace('/^\s*(?:\d+\s*[.)\-]\s*|0\d+\s+)/', '', $label);
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
            if (in_array($field['type'], self::COMPUTED, true)) {
                $problems[] = '"'.$name.'" is computed in Airtable ('.$field['type'].') and cannot be written.';

                continue;
            }
            if ($field['choices'] === null || $value === null) {
                continue;
            }
            foreach (is_array($value) ? $value : [$value] as $part) {
                if (! in_array((string) $part, $field['choices'], true)) {
                    $problems[] = '"'.$name.'" has no option "'.$part.'" (Airtable offers: '.implode(', ', $field['choices']).').';
                }
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
