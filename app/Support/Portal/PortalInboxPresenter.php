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
            'payload',
            'read_at',
            'created_at',
        ];
    }

    /**
     * An unread notice as the shell's navigation marks read it: what kind, which record, and where
     * it links. Nothing a mark does not need.
     *
     * @return array{id: string, type: string, recordId: ?string, linkPath: ?string}
     */
    public static function unread(PortalNotification $row): array
    {
        $linkPath = trim((string) $row->link_path);

        return [
            'id' => (string) $row->getKey(),
            'type' => trim((string) $row->type),
            'recordId' => self::recordId($row->payload),
            'linkPath' => $linkPath !== '' ? $linkPath : null,
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
            // Which record the notice is about, so opening it can land on that row. The rest of the
            // payload stays on the server.
            'recordId' => self::recordId($row->payload),
            'isRead' => $row->read_at !== null,
            'createdAt' => $createdAt->copy()->utc()->toIso8601String(),
            'createdAtLabel' => $local->format('l, F j, Y \a\t g:i A'),
        ];
    }

    private static function recordId(mixed $payload): ?string
    {
        if (! is_array($payload)) {
            return null;
        }
        $value = $payload['recordId'] ?? null;
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    private static function instant(mixed $value): Carbon
    {
        if ($value instanceof Carbon) {
            return $value->copy();
        }

        return Carbon::parse((string) $value);
    }
}
