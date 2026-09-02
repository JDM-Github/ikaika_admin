<?php

namespace App\Support\Workspace;

/**
 * Workspace column policy: what never leaves PHP, and what only an admin may read.
 *
 * This is the security boundary of a browser that can point at any table in any of
 * the three databases, so it lives in code rather than config -- a mis-set config
 * key would silently publish bcrypt hashes and bank numbers to every signed-in
 * employee. Matching is by column name, because the grid is driven by
 * information_schema and has no model to ask.
 */
final class WorkspaceRedactor
{
    /**
     * Never selected, never listed as a field. estimator.accounts.password_hash holds
     * 18 live bcrypt hashes and invite_code is a live credential.
     *
     * @var list<string>
     */
    private const SECRET = [
        'password', 'password_hash', 'remember_token', 'invite_code',
        'api_key', 'api_token', 'secret', 'access_token', 'refresh_token',
    ];

    /**
     * Selected only for an admin. Government IDs, bank details and home contact
     * details on portal.employees -- an ordinary member browsing the roster has no
     * business reading them.
     *
     * @var list<string>
     */
    private const PRIVATE_COLUMNS = [
        'tax_identification_no', 'philhealth_no', 'sss_no', 'hdmf_no',
        'bank_account_number', 'bank_swift_code', 'bank_name',
        'date_of_birth', 'address', 'personal_email', 'phone_number', 'blood_type',
        'emergency_contact_name', 'emergency_contact_number',
        'emergency_contact_relationship', 'emergency_contact_address',
    ];

    /**
     * Substrings that mark a secret whatever the rest of the name is.
     *
     * @var list<string>
     */
    private const SECRET_FRAGMENTS = ['password', 'secret', 'bcrypt'];

    public static function isSecret(string $column): bool
    {
        $name = strtolower($column);

        if (in_array($name, self::SECRET, true)) {
            return true;
        }

        foreach (self::SECRET_FRAGMENTS as $fragment) {
            if (str_contains($name, $fragment)) {
                return true;
            }
        }

        return false;
    }

    public static function isPrivate(string $column): bool
    {
        return in_array(strtolower($column), self::PRIVATE_COLUMNS, true);
    }

    /**
     * True when this column must not be read by the signed-in employee.
     */
    public static function isHidden(string $column, bool $isAdmin): bool
    {
        if (self::isSecret($column)) {
            return true;
        }

        return ! $isAdmin && self::isPrivate($column);
    }

    /**
     * Drop every column the actor may not read. Keys are column names.
     *
     * @param  list<string>  $columns
     * @return list<string>
     */
    public static function allow(array $columns, bool $isAdmin): array
    {
        $kept = [];
        foreach ($columns as $column) {
            if (! self::isHidden($column, $isAdmin)) {
                $kept[] = $column;
            }
        }

        return $kept;
    }
}
