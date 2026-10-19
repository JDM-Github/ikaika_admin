<?php

namespace App\Support\Portal;

use App\Modules\Portal\Models\Employee;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Who is never located (location_exemptions, 2026-10-19, user): their writes need no X-Portal-Location,
 * their records store the place as Unknown with no coordinates, they set no home pin, and Manage / Map
 * never shows them. Their IP is still kept, for security.
 */
final class PortalLocationExemptions
{
    public const UNKNOWN = 'Unknown';

    public static function covers(Employee|int|null $employee): bool
    {
        $id = $employee instanceof Employee ? (int) $employee->getKey() : $employee;
        if ($id === null || $id <= 0) {
            return false;
        }

        return in_array($id, self::ids(), true);
    }

    /**
     * @return list<int>
     */
    public static function ids(): array
    {
        try {
            return DB::connection('portal')->table('location_exemptions')->pluck('employee_id')->map(static fn (mixed $id): int => (int) $id)->all();
        } catch (Throwable) {
            // Before the migration runs there is no table, so nobody is exempt.
            return [];
        }
    }
}
