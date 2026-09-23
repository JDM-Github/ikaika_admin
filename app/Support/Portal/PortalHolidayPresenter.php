<?php

namespace App\Support\Portal;

/**
 * Skinny Calendar / Holidays payload. The estimator's scheduling columns and its earn-code
 * multipliers never leave this class; the portal marks a day closed, it does not price one.
 */
final class PortalHolidayPresenter
{
    private const UNNAMED = 'Holiday';

    /**
     * @return array{id: string, date: string, name: string, type: string}
     */
    public static function holiday(string $id, string $date, ?string $name, string $type): array
    {
        return [
            'id' => $id,
            'date' => substr($date, 0, 10),
            'name' => self::name($name),
            'type' => $type,
        ];
    }

    /**
     * Source names carry trailing newlines from the export and drift in spelling between years,
     * so the date is the identity and this is only ever a label.
     */
    private static function name(?string $value): string
    {
        $trimmed = trim((string) $value);

        return $trimmed !== '' ? $trimmed : self::UNNAMED;
    }
}
