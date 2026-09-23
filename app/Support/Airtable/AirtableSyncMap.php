<?php

namespace App\Support\Airtable;

/**
 * Which portal ledger resource lands in which Airtable table, column by column.
 *
 * The sync pushes the row as it stands now, not the payload the action carried: the database is
 * the truth, so several actions on one row converge on its current state however they were
 * shaped. A resource missing here is left unsynced -- never failed -- until it is mapped.
 */
final class AirtableSyncMap
{
    /**
     * `discard_when` names a column value meaning the member withdrew the row: it is never sent,
     * and a copy Airtable already holds is removed, since the request was never really filed.
     *
     * @var array<string, array{sql_table: string, airtable_table: string, fields: array<string, string>, discard_when?: array{column: string, value: string}}>
     */
    private const RESOURCES = [
        // Airtable's Request table stores the member as plain text, so leave needs no linked record.
        'requests.leave' => [
            'sql_table' => 'requests',
            'airtable_table' => 'Request',
            'discard_when' => ['column' => 'status', 'value' => 'Cancelled'],
            'fields' => [
                'request_date' => 'Date',
                'name' => 'Name',
                'reason' => 'Reason',
                'status' => 'Status',
                'category' => 'Category',
                'type' => 'Type',
                'approver_remarks' => 'Approver Remarks',
            ],
        ],
    ];

    /**
     * @return array{sql_table: string, airtable_table: string, fields: array<string, string>, discard_when?: array{column: string, value: string}}|null
     */
    public static function for(?string $resource): ?array
    {
        return $resource === null ? null : (self::RESOURCES[$resource] ?? null);
    }

    /**
     * @return list<string>
     */
    public static function resources(): array
    {
        return array_keys(self::RESOURCES);
    }
}
