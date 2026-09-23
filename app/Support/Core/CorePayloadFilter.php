<?php

namespace App\Support\Core;

/**
 * Strips credentials and government / bank identifiers from a payload before it is stored.
 *
 * Shared by the activity log and the core ledger: the ledger is what the Airtable sync reads,
 * so a secret that survives here leaves the building.
 */
final class CorePayloadFilter
{
    private const BLOCKED = [
        'password', 'token', 'authorization', 'secret', 'api_key', 'apikey',
        'access_token', 'refresh_token', 'jwt', 'bank_account_number', 'bank_swift_code',
        'tax_identification_no', 'sss_no', 'philhealth_no', 'hdmf_no',
    ];

    /**
     * @param  array<array-key, mixed>  $payload
     * @return array<array-key, mixed>
     */
    public static function clean(array $payload): array
    {
        $clean = [];
        foreach ($payload as $key => $value) {
            // A grid edit nests the column name as a key under changes/fields, so walk every level.
            if (is_string($key) && in_array(strtolower($key), self::BLOCKED, true)) {
                continue;
            }
            $clean[$key] = is_array($value) ? self::clean($value) : $value;
        }

        return $clean;
    }
}
