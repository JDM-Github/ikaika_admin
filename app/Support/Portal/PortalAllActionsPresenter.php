<?php

namespace App\Support\Portal;

use App\Modules\Core\Models\Action;
use DateTimeZone;
use Illuminate\Support\Carbon;
use stdClass;

/**
 * Administration / All Actions rows: what a write actually touched, and who made it.
 */
final class PortalAllActionsPresenter
{
    /**
     * @return list<string>
     */
    public static function columns(): array
    {
        return [
            'id',
            'product',
            'database_target',
            'action_type',
            'resource',
            'record_id',
            'recycle_key',
            'actor_id',
            'actor_id_no',
            'as_developer',
            'synced_at',
            'created_at',
        ];
    }

    /**
     * @return list<string>
     */
    public static function detailColumns(): array
    {
        return [...self::columns(), 'parameters'];
    }

    /**
     * @return list<string>
     */
    public static function employeeColumns(): array
    {
        return ['id', 'first_name', 'last_name', 'id_no'];
    }

    /**
     * @param  array<int, array{name: string, idNo: ?string}>  $employees
     * @return array{
     *     id: string,
     *     product: string,
     *     databaseTarget: string,
     *     actionType: string,
     *     resource: ?string,
     *     recordId: ?string,
     *     recycleKey: ?string,
     *     employeeId: ?int,
     *     employeeIdNo: ?string,
     *     employeeName: string,
     *     syncedAt: ?string,
     *     syncedAtLabel: ?string,
     *     createdAt: string,
     *     dateLabel: string,
     *     timeLabel: string,
     *     createdAtLabel: string
     * }
     */
    public static function item(Action $row, array $employees, DateTimeZone $timezone, bool $viewerIsDeveloper = false): array
    {
        if ((bool) $row->as_developer && ! $viewerIsDeveloper) {
            // Nothing that points back at them: no id, no ID number, and not the key built from their id.
            return [
                ...self::item($row, [], $timezone, true),
                'recycleKey' => null,
                'employeeId' => null,
                'employeeIdNo' => null,
                'employeeName' => PortalAccessGraph::DEVELOPER_NAME_CAPITAL,
            ];
        }
        $createdAt = self::instant($row->created_at);
        $local = $createdAt->copy()->setTimezone($timezone);

        // actor_id is nullable -- CoreLedger::insertAction accepts ?int, so a system write has none.
        $employeeId = is_numeric($row->actor_id) ? (int) $row->actor_id : null;
        $known = $employeeId !== null ? ($employees[$employeeId] ?? null) : null;
        $idNo = is_string($row->actor_id_no) && $row->actor_id_no !== ''
            ? $row->actor_id_no
            : ($known['idNo'] ?? null);

        $resource = trim((string) $row->resource);
        $recordId = trim((string) $row->record_id);
        $recycleKey = trim((string) $row->recycle_key);

        return [
            'id' => (string) $row->getKey(),
            'product' => trim((string) $row->product),
            'databaseTarget' => trim((string) $row->database_target),
            'actionType' => strtolower(trim((string) $row->action_type)),
            'resource' => $resource !== '' ? $resource : null,
            'recordId' => $recordId !== '' ? $recordId : null,
            'recycleKey' => $recycleKey !== '' ? $recycleKey : null,
            'employeeId' => $employeeId,
            'employeeIdNo' => $idNo,
            'employeeName' => ($known['name'] ?? ($idNo ?? 'Member')).((bool) $row->as_developer ? PortalAccessGraph::DEVELOPER_MODE_SUFFIX : ''),
            'syncedAt' => $row->synced_at === null ? null : self::instant($row->synced_at)->utc()->toIso8601String(),
            'syncedAtLabel' => $row->synced_at === null
                ? null
                : self::instant($row->synced_at)->setTimezone($timezone)->format('l, F j, Y \a\t g:i A'),
            'createdAt' => $createdAt->copy()->utc()->toIso8601String(),
            'dateLabel' => $local->format('l, F j, Y'),
            'timeLabel' => $local->format('g:i A'),
            'createdAtLabel' => $local->format('l, F j, Y \a\t g:i A'),
        ];
    }

    /**
     * The list row plus what was written.
     *
     * `CoreLedger::insertAction` stores an envelope whose seven top-level keys are already columns
     * above, so the inner `parameters` is the only part that says anything new -- the fallback
     * covers a row not written through that method. Its shape is per-resource and deliberately
     * untyped: report entries, request fields, or a whole recycle snapshot for a delete.
     *
     * Plain array, deliberately not forced to an object here: this is what `showAll` hands to
     * `Cache::remember`, and the file cache's unserialize runs with `allowed_classes` off -- any
     * object comes back a broken `__PHP_Incomplete_Class` on every cache hit. The empty-payload
     * cast happens in the controller instead, after the cached value has been read.
     *
     * @param  array<int, array{name: string, idNo: ?string}>  $employees
     * @return array<string, mixed>
     */
    public static function detail(Action $row, array $employees, DateTimeZone $timezone, bool $viewerIsDeveloper = false): array
    {
        $blob = is_array($row->parameters) ? $row->parameters : [];
        $payload = $blob['parameters'] ?? $blob;

        return [
            ...self::item($row, $employees, $timezone, $viewerIsDeveloper),
            'payload' => is_array($payload) ? $payload : [],
        ];
    }

    /**
     * Call on a `showAll` result right before `response()->json(...)`, never before. An empty PHP
     * array has no keys to say map or list, so json_encode renders it as `[]` unless forced -- but
     * forcing it here, at the response boundary, is the one place safe to put an object: nothing
     * downstream of this hands the array back to `Cache::remember`.
     *
     * @param  array{section: string, resource: string, data: array<string, mixed>}  $result
     * @return array{section: string, resource: string, data: array<string, mixed>}
     */
    public static function withObjectPayload(array $result): array
    {
        if (($result['data']['payload'] ?? null) === []) {
            $result['data']['payload'] = new stdClass;
        }

        return $result;
    }

    private static function instant(mixed $value): Carbon
    {
        if ($value instanceof Carbon) {
            return $value->copy();
        }

        return Carbon::parse((string) $value);
    }
}
