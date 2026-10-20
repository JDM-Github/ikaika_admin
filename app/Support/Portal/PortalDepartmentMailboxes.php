<?php

namespace App\Support\Portal;

use App\Modules\Portal\Models\Employee;

/**
 * A department's own From (2026-10-19, user): an administrator of MIS may send as dev@, one of Engineering
 * as engineering@. The address is always read from the sender's own department, never from the request,
 * which keeps the bridge's tenant-wide Graph Mail.Send permission to these two fixed mailboxes.
 */
final class PortalDepartmentMailboxes
{
    // Department, as PortalPreview::department lower-cases it => its mailbox and the name an inbox shows.
    private const MAILBOXES = [
        'mis' => ['name' => 'IKAIKA MIS', 'address' => 'dev@ikaikabim.com'],
        'engineering' => ['name' => 'IKAIKA Engineering', 'address' => 'engineering@ikaikabim.com'],
    ];

    /**
     * @return array{name: string, address: string}|null
     */
    public static function for(Employee $employee): ?array
    {
        if (! PortalRole::isAdmin($employee->role ?? null, $employee->role_level ?? null)) {
            return null;
        }

        return self::MAILBOXES[PortalPreview::department($employee->department ?? null)] ?? null;
    }
}
