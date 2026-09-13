<?php

namespace App\Support\Portal;

/**
 * Skinny Calendar / Events payload. One row is one day on the Event Calendar: a holiday, a leave
 * day, or another request date. Times stay optional so an all-day leave is not forced to midnight.
 */
final class PortalCalendarEventPresenter
{
    /**
     * @return array{id: string, title: string, startsOn: string, startsAt: string|null, kind: string, detail: string|null, endsOn?: string}
     */
    public static function event(
        string $id,
        string $title,
        string $startsOn,
        string $kind,
        ?string $startsAt = null,
        ?string $detail = null,
        ?string $endsOn = null,
    ): array {
        $trimmedTitle = trim($title);
        $trimmedDetail = $detail === null ? null : trim($detail);
        $start = substr($startsOn, 0, 10);
        $end = $endsOn === null ? null : substr($endsOn, 0, 10);

        $payload = [
            'id' => $id,
            'title' => $trimmedTitle !== '' ? $trimmedTitle : 'Event',
            'startsOn' => $start,
            'startsAt' => $startsAt,
            'kind' => $kind,
            'detail' => $trimmedDetail !== '' ? $trimmedDetail : null,
        ];
        if ($end !== null && $end !== $start) {
            $payload['endsOn'] = $end;
        }

        return $payload;
    }
}
