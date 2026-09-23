<?php

namespace App\Support\Airtable;

use App\Modules\Core\Models\Action;
use App\Support\Core\CoreActionType;
use App\Support\Core\CoreLedger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Replays unsynced portal ledger rows into Airtable, oldest first.
 *
 * A dry run reads the live Airtable schema and every mapped row but writes nothing, anywhere --
 * not to Airtable, not to the ledger. A sent run creates or patches each record, writes a new
 * record's id back to the SQL row so the next edit updates instead of duplicating, and stamps
 * synced_at. A row that fails keeps synced_at empty and records why, so the next run retries it.
 */
final class PortalAirtableSync
{
    public function __construct(private readonly AirtableClient $airtable) {}

    /**
     * @return array{
     *     sent: bool,
     *     counts: array{create: int, update: int, discard: int, synced: int, covered: int, blocked: int, failed: int, skipped: int},
     *     items: list<array{actionId: int, resource: ?string, actionType: string, recordId: ?string, outcome: string, table: ?string, airtableId: ?string, fields: array<string, mixed>, problems: list<string>}>
     * }
     */
    public function run(bool $send): array
    {
        $schema = $this->airtable->tables();
        $counts = ['create' => 0, 'update' => 0, 'discard' => 0, 'synced' => 0, 'covered' => 0, 'blocked' => 0, 'failed' => 0, 'skipped' => 0];
        $items = [];
        // The first action on a row pushes that row's current state, which already holds every later one.
        $firstByRow = [];

        $actions = Action::query()
            ->where('product', CoreLedger::PRODUCT_PORTAL)
            ->whereNull('synced_at')
            ->orderBy('id')
            ->get();

        foreach ($actions as $action) {
            $map = AirtableSyncMap::for($action->resource);
            $rowKey = $map === null ? null : $map['sql_table'].'#'.$action->record_id;

            if ($rowKey !== null && isset($firstByRow[$rowKey])) {
                $item = $this->covered($action, $firstByRow[$rowKey], $send);
            } else {
                $item = $this->plan($action, $schema);
                if ($send && in_array($item['outcome'], ['create', 'update', 'discard'], true)) {
                    $item = $this->push($action, $item);
                }
                if ($rowKey !== null && $item['outcome'] !== 'skipped') {
                    $firstByRow[$rowKey] = $item;
                }
            }

            $counts[$this->bucket($item['outcome'])]++;
            $items[] = $item;
        }

        return ['sent' => $send, 'counts' => $counts, 'items' => $items];
    }

    /**
     * A later action on a row this run already handled: it shares that outcome, and is stamped
     * synced only when the first one actually landed.
     *
     * @param  array{actionId: int, resource: ?string, actionType: string, recordId: ?string, outcome: string, table: ?string, airtableId: ?string, fields: array<string, mixed>, problems: list<string>}  $first
     * @return array{actionId: int, resource: ?string, actionType: string, recordId: ?string, outcome: string, table: ?string, airtableId: ?string, fields: array<string, mixed>, problems: list<string>}
     */
    private function covered(Action $action, array $first, bool $send): array
    {
        $landed = in_array($first['outcome'], ['synced', 'discarded'], true);
        $pending = in_array($first['outcome'], ['create', 'update', 'discard'], true);

        if ($send && $landed) {
            $this->stampSynced($action);
        }

        return [
            'actionId' => (int) $action->getKey(),
            'resource' => $action->resource,
            'actionType' => (string) $action->action_type,
            'recordId' => $action->record_id,
            'outcome' => $landed || $pending ? 'covered' : $first['outcome'],
            'table' => $first['table'],
            'airtableId' => $first['airtableId'],
            'fields' => [],
            'problems' => [$landed || $pending
                ? 'Same row as #'.$first['actionId'].', which already carries its current state.'
                : 'Same row as #'.$first['actionId'].', which did not go through; retried with it next run.'],
        ];
    }

    /**
     * What this action would do, decided without writing anything.
     *
     * @param  array<string, array<string, array{type: string, choices: list<string>|null}>>  $schema
     * @return array{actionId: int, resource: ?string, actionType: string, recordId: ?string, outcome: string, table: ?string, airtableId: ?string, fields: array<string, mixed>, problems: list<string>}
     */
    private function plan(Action $action, array $schema): array
    {
        $item = [
            'actionId' => (int) $action->getKey(),
            'resource' => $action->resource,
            'actionType' => (string) $action->action_type,
            'recordId' => $action->record_id,
            'outcome' => 'skipped',
            'table' => null,
            'airtableId' => null,
            'fields' => [],
            'problems' => [],
        ];

        $map = AirtableSyncMap::for($action->resource);
        if ($map === null) {
            $item['problems'][] = 'Not mapped to an Airtable table yet; left for a later run.';

            return $item;
        }
        $item['table'] = $map['airtable_table'];

        if ($action->action_type === CoreActionType::DELETE) {
            $item['problems'][] = 'Deletes are not synced yet; left for a later run.';

            return $item;
        }

        $row = DB::connection('portal')->table($map['sql_table'])->where('id', $action->record_id)->first();
        if ($row === null) {
            $item['outcome'] = 'failed';
            $item['problems'][] = 'The SQL row '.$map['sql_table'].'#'.$action->record_id.' no longer exists.';

            return $item;
        }

        $airtableId = is_string($row->airtable_record_id ?? null) && $row->airtable_record_id !== ''
            ? $row->airtable_record_id
            : null;
        $item['airtableId'] = $airtableId;

        // Withdrawn by the member: it was never really filed, so Airtable should not hold it at all.
        $discard = $map['discard_when'] ?? null;
        if ($discard !== null && is_string($row->{$discard['column']} ?? null)
            && $this->comparable($row->{$discard['column']}) === $this->comparable($discard['value'])) {
            $item['outcome'] = 'discard';
            $item['problems'][] = $airtableId === null
                ? 'Cancelled before it reached Airtable; it will never be sent.'
                : 'Cancelled after it reached Airtable; its record there will be deleted.';

            return $item;
        }
        $item['fields'] = $this->fitChoices(
            $map['airtable_table'],
            $this->fields($map, $row, $airtableId === null),
            $schema,
        );
        $item['problems'] = $this->problems($map['airtable_table'], $item['fields'], $schema);
        $item['outcome'] = $item['problems'] !== [] ? 'blocked' : ($airtableId === null ? 'create' : 'update');

        return $item;
    }

    /**
     * @param  array{actionId: int, resource: ?string, actionType: string, recordId: ?string, outcome: string, table: ?string, airtableId: ?string, fields: array<string, mixed>, problems: list<string>}  $item
     * @return array{actionId: int, resource: ?string, actionType: string, recordId: ?string, outcome: string, table: ?string, airtableId: ?string, fields: array<string, mixed>, problems: list<string>}
     */
    private function push(Action $action, array $item): array
    {
        $map = AirtableSyncMap::for($action->resource);
        if ($map === null || $item['table'] === null) {
            return $item;
        }

        try {
            if ($item['outcome'] === 'discard') {
                if ($item['airtableId'] !== null) {
                    $this->airtable->delete($item['table'], $item['airtableId']);
                    DB::connection('portal')->table($map['sql_table'])
                        ->where('id', $item['recordId'])
                        ->update(['airtable_record_id' => null]);
                }
                $this->stampSynced($action);
                $item['outcome'] = 'discarded';

                return $item;
            }

            if ($item['airtableId'] === null) {
                $item['airtableId'] = $this->airtable->create($item['table'], $item['fields']);
                // Written back at once, so the next action on this row patches rather than duplicates.
                DB::connection('portal')->table($map['sql_table'])
                    ->where('id', $item['recordId'])
                    ->update(['airtable_record_id' => $item['airtableId']]);
            } else {
                $this->airtable->update($item['table'], $item['airtableId'], $item['fields']);
            }

            $this->stampSynced($action);
            $item['outcome'] = 'synced';
        } catch (Throwable $error) {
            Action::query()->whereKey($action->getKey())->update([
                'attempts' => DB::raw('attempts + 1'),
                'last_error' => mb_substr($error->getMessage(), 0, 1000),
            ]);
            $item['outcome'] = 'failed';
            $item['problems'][] = $error->getMessage();
        }

        return $item;
    }

    /**
     * @param  array{sql_table: string, airtable_table: string, fields: array<string, string>, discard_when?: array{column: string, value: string}}  $map
     * @return array<string, mixed>
     */
    private function fields(array $map, object $row, bool $creating): array
    {
        $fields = [];
        foreach ($map['fields'] as $column => $field) {
            $value = $row->{$column} ?? null;
            if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}( |T|$)/', $value) === 1) {
                $value = substr($value, 0, 10);
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
     * Rewrites each select value to the option Airtable offers for it, matched against the live
     * base rather than a fixed table, so "01 Vacation Leave" still lands on "Vacation Leave" after
     * either side renames or renumbers its labels. A value with no counterpart is left as it was,
     * and problems() blocks it.
     *
     * @param  array<string, mixed>  $fields
     * @param  array<string, array<string, array{type: string, choices: list<string>|null}>>  $schema
     * @return array<string, mixed>
     */
    private function fitChoices(string $table, array $fields, array $schema): array
    {
        foreach ($fields as $name => $value) {
            $choices = $schema[$table][$name]['choices'] ?? null;
            if ($choices === null || ! is_string($value)) {
                continue;
            }
            foreach ($choices as $choice) {
                if ($this->comparable($choice) === $this->comparable($value)) {
                    $fields[$name] = $choice;
                    break;
                }
            }
        }

        return $fields;
    }

    // Case, spacing and a leading "01 " / "3." numbering are presentation, not meaning.
    private function comparable(string $label): string
    {
        $label = (string) preg_replace('/^\s*\d+\s*[.)\-]?\s*/', '', $label);

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

    private function stampSynced(Action $action): void
    {
        Action::query()->whereKey($action->getKey())->update(['synced_at' => Carbon::now(), 'last_error' => null]);
    }

    private function bucket(string $outcome): string
    {
        return match ($outcome) {
            'discarded' => 'synced',
            'create', 'update', 'discard', 'synced', 'covered', 'blocked', 'failed' => $outcome,
            default => 'skipped',
        };
    }
}
