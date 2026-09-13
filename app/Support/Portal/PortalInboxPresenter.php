<?php

namespace App\Support\Portal;

use App\Modules\Portal\Models\PortalNotification;
use DateTimeZone;
use Illuminate\Support\Carbon;

/**
 * Skinny inbox rows for the shell header.
 */
final class PortalInboxPresenter
{
    /**
     * @return list<string>
     */
    public static function columns(): array
    {
        return [
            'id',
            'type',
            'title',
            'message',
            'link_path',
            'link_label',
            'read_at',
            'created_at',
        ];
    }

    /**
     * @return array{
     *     id: string,
     *     type: string,
     *     title: string,
     *     message: string,
     *     linkPath: ?string,
     *     linkLabel: ?string,
     *     isRead: bool,
     *     createdAt: string,
     *     createdAtLabel: string
     * }
     */
    public static function item(PortalNotification $row, DateTimeZone $timezone): array
    {
        $createdAt = self::instant($row->created_at);
        $local = $createdAt->copy()->setTimezone($timezone);
        $linkPath = trim((string) $row->link_path);
        $linkLabel = trim((string) $row->link_label);

        return [
            'id' => (string) $row->getKey(),
            'type' => trim((string) $row->type),
            'title' => trim((string) $row->title),
            'message' => trim((string) $row->message),
            'linkPath' => $linkPath !== '' ? $linkPath : null,
            'linkLabel' => $linkLabel !== '' ? $linkLabel : null,
            'isRead' => $row->read_at !== null,
            'createdAt' => $createdAt->copy()->utc()->toIso8601String(),
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
