<?php

namespace App\Support\Portal;

use App\Modules\Portal\Models\PortalLog;
use DateTimeZone;
use Illuminate\Support\Carbon;
use stdClass;

/**
 * Skinny User / Logs rows with a readable request origin.
 */
final class PortalUserLogPresenter
{
    /**
     * @return list<string>
     */
    public static function columns(): array
    {
        return [
            'id',
            'employee_id',
            'action',
            'resource',
            'record_id',
            'message',
            'ip_address',
            'user_agent',
            'location_label',
            'location_source',
            'location_lat',
            'location_lng',
            'created_at',
        ];
    }

    /**
     * @return array{
     *     id: string,
     *     employeeId: string,
     *     employeeName: ?string,
     *     action: string,
     *     resource: string,
     *     recordId: ?string,
     *     message: string,
     *     ipAddress: ?string,
     *     deviceLabel: string,
     *     locationLabel: ?string,
     *     locationSource: ?string,
     *     locationLat: ?float,
     *     locationLng: ?float,
     *     createdAt: string,
     *     dateLabel: string,
     *     timeLabel: string,
     *     createdAtLabel: string
     * }
     */
    public static function item(PortalLog $row, DateTimeZone $timezone): array
    {
        $createdAt = self::instant($row->created_at);
        $local = $createdAt->copy()->setTimezone($timezone);
        $recordId = trim((string) $row->record_id);
        $ipAddress = trim((string) $row->ip_address);
        $userAgent = trim((string) $row->user_agent);
        $locationLabel = trim((string) $row->location_label);
        $locationSource = trim((string) $row->location_source);

        $employeeId = (int) $row->employee_id;
        $firstName = is_string($row->first_name ?? null) ? $row->first_name : null;
        $lastName = is_string($row->last_name ?? null) ? $row->last_name : null;
        $hasName = trim((string) $firstName) !== '' || trim((string) $lastName) !== '';

        return [
            'id' => (string) $row->getKey(),
            'employeeId' => (string) $employeeId,
            'employeeName' => $hasName ? PortalSubmittedReportPresenter::memberName($firstName, $lastName) : null,
            'action' => strtoupper(trim((string) $row->action)),
            'resource' => trim((string) $row->resource),
            'recordId' => $recordId !== '' ? $recordId : null,
            'message' => trim((string) $row->message),
            'ipAddress' => $ipAddress !== '' ? $ipAddress : null,
            'deviceLabel' => self::deviceLabel($userAgent),
            'locationLabel' => $locationLabel !== '' ? $locationLabel : null,
            'locationSource' => $locationSource !== '' ? $locationSource : null,
            'locationLat' => is_numeric($row->location_lat) ? (float) $row->location_lat : null,
            'locationLng' => is_numeric($row->location_lng) ? (float) $row->location_lng : null,
            'createdAt' => $createdAt->copy()->utc()->toIso8601String(),
            'dateLabel' => $local->format('l, F j, Y'),
            'timeLabel' => $local->format('g:i A'),
            'createdAtLabel' => $local->format('l, F j, Y \a\t g:i A'),
        ];
    }

    /**
     * The list row plus what actually happened -- the same structured detail the resource's own
     * screen already shows, carried through untouched from whatever `PortalAudit::record` wrote.
     *
     * Plain array, deliberately not forced to an object here: this return value is what
     * `PortalUserLogs::show`/`showAll` hand to `Cache::remember`, and the file cache's unserialize
     * runs with `allowed_classes` off -- any object, `stdClass` included, comes back a broken
     * `__PHP_Incomplete_Class` on every cache hit. The empty-payload-to-object cast happens in the
     * controller instead, on the response array, after the cached value has already been read.
     *
     * @return array<string, mixed>
     */
    public static function detail(PortalLog $row, DateTimeZone $timezone): array
    {
        return [
            ...self::item($row, $timezone),
            'payload' => is_array($row->payload) ? $row->payload : [],
        ];
    }

    /**
     * Call on a `show`/`showAll` result right before `response()->json(...)`, never before. An
     * empty PHP array has no keys to say map or list, so json_encode renders it as `[]` unless
     * forced -- but forcing it here, at the response boundary, is the one place safe to put an
     * object: nothing downstream of this hands the array back to `Cache::remember`.
     *
     * @param  array{section: string, resource: string, data: array<string, mixed>}  $result
     * @return array{section: string, resource: string, data: array<string, mixed>}
     */
    public static function withObjectPayload(array $result): array
    {
        if (($result['data']['payload'] ?? null) === []) {
            $result['data']['payload'] = new stdClass();
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

    private static function deviceLabel(string $userAgent): string
    {
        if ($userAgent === '') {
            return 'Unknown device';
        }

        $browser = match (true) {
            str_contains($userAgent, 'Edg') => 'Microsoft Edge',
            str_contains($userAgent, 'OPR/') => 'Opera',
            str_contains($userAgent, 'Chrome') || str_contains($userAgent, 'CriOS') => 'Google Chrome',
            str_contains($userAgent, 'Firefox') || str_contains($userAgent, 'FxiOS') => 'Firefox',
            str_contains($userAgent, 'MSIE') || str_contains($userAgent, 'Trident/') => 'Internet Explorer',
            str_contains($userAgent, 'Safari') => 'Safari',
            default => 'Unknown browser',
        };

        $device = match (true) {
            str_contains($userAgent, 'iPhone') => 'iPhone',
            str_contains($userAgent, 'iPad') => 'iPad',
            str_contains($userAgent, 'iPod') => 'iPod',
            str_contains($userAgent, 'Android') => 'Android',
            str_contains($userAgent, 'Windows') => 'Windows',
            str_contains($userAgent, 'Macintosh') || str_contains($userAgent, 'Mac OS X') => 'macOS',
            str_contains($userAgent, 'Linux') => 'Linux',
            default => 'Unknown device',
        };

        return $browser.' on '.$device;
    }
}
