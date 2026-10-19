<?php

namespace App\Support\Portal;

/**
 * A person's name written the way the company writes it (2026-10-20, user): "JAYCRIS  PAMINTUAN " is
 * "Jaycris Pamintuan". Spaces are trimmed and collapsed; a word typed all in capitals or all in small
 * letters takes a capital first letter, each part of it after a hyphen or apostrophe too ("Dela
 * Cruz-Santos", "O'Neil"); a word typed in mixed case ("McDonald", "DeGuzman") is kept as typed, and a
 * generational suffix (II, III, IV) stays in capitals.
 */
final class PortalNameCase
{
    private const SUFFIXES = ['ii', 'iii', 'iv'];

    public static function proper(?string $name): ?string
    {
        if ($name === null) {
            return null;
        }
        $words = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($words === []) {
            return null;
        }

        return implode(' ', array_map(self::word(...), $words));
    }

    private static function word(string $word): string
    {
        if (in_array(rtrim(mb_strtolower($word), '.'), self::SUFFIXES, true)) {
            return mb_strtoupper($word);
        }
        $parts = preg_split('/([\-\'’])/u', $word, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$word];

        return implode('', array_map(self::part(...), $parts));
    }

    // Mixed case is someone's own spelling ("McDonald"); only a part in one case is corrected.
    private static function part(string $part): string
    {
        $lower = mb_strtolower($part);
        if ($part === '' || ($part !== mb_strtoupper($part) && $part !== $lower)) {
            return $part;
        }

        return mb_strtoupper(mb_substr($lower, 0, 1)).mb_substr($lower, 1);
    }
}
