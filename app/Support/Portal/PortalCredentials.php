<?php

namespace App\Support\Portal;

/**
 * The generated secrets and slugs a public link is made of, in one place: a share's password and
 * code and a registration's password and code differ in nothing, and a slug means the same both
 * times. One owner for the alphabet, the length, the random code and the reserved words.
 */
final class PortalCredentials
{
    public const PASSWORD_LENGTH = 12;

    // No 0/O/1/l/I, so a password can be read out loud without a second attempt.
    public const PASSWORD_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';

    private const SLUG = '/^[a-z0-9][a-z0-9-]{1,62}[a-z0-9]$/';

    // share/session and register/session are routes of their own, so no link may answer there.
    private const RESERVED = ['session'];

    public static function password(): string
    {
        $characters = str_split(self::PASSWORD_ALPHABET);
        $password = '';
        for ($index = 0; $index < self::PASSWORD_LENGTH; $index++) {
            $password .= $characters[random_int(0, count($characters) - 1)];
        }

        return $password;
    }

    // 24 random hex characters (96 bits); the caller checks it against its own table.
    public static function code(): string
    {
        return bin2hex(random_bytes(12));
    }

    // The typed slug, normalised, or null when nothing was typed. Refuses anything else.
    public static function slug(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $slug = strtolower(trim($value));
        if (preg_match(self::SLUG, $slug) !== 1) {
            abort(422, 'A link slug is 3-64 characters: lowercase letters, numbers and dashes.');
        }
        if (in_array($slug, self::RESERVED, true)) {
            abort(422, 'That link slug is reserved.');
        }

        return $slug;
    }
}
