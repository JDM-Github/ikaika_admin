<?php

namespace App\Support\Portal;

use App\Modules\Portal\Models\PortalLog;
use DateTimeZone;
use Illuminate\Support\Carbon;

/**
 * Skinny User / Logs rows. Private request metadata never leaves the backend.
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
            'action',
            'resource',
            'record_id',
            'message',
            'created_at',
        ];
    }

    /**
     * @return array{
     *     id: string,
     *     action: string,
     *     resource: string,
     *     recordId: ?string,
     *     message: string,
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

        return [
            'id' => (string) $row->getKey(),
            'action' => strtoupper(trim((string) $row->action)),
            'resource' => trim((string) $row->resource),
            'recordId' => $recordId !== '' ? $recordId : null,
            'message' => trim((string) $row->message),
            'createdAt' => $createdAt->copy()->utc()->toIso8601String(),
            'dateLabel' => $local->format('l, F j, Y'),
            'timeLabel' => $local->format('g:i A'),
            'createdAtLabel' => $local->format('l, F j, Y \a\t g:i A'),
        ];
    }

    private static function instant(mixed $value): Carbon
    {
        if ($value instanceof Carbon) {
            return $value->copy();
        }

        return Carbon::parse((string) $value);
    }
}
