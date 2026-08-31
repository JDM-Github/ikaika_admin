<?php

namespace App\Support\Portal;

use App\Modules\Portal\Models\Employee;

/**
 * Skinny Manage / Users payload. Bank, tax, and identity fields never leave this class.
 */
final class PortalManageUserPresenter
{
    /**
     * @return list<string>
     */
    public static function rosterColumns(): array
    {
        return [
            'id',
            'id_no',
            'first_name',
            'last_name',
            'email',
            'department',
            'job_title',
            'status',
            'role',
            'role_level',
        ];
    }

    /**
     * Columns the JWT session needs. Same public set as the login presenter.
     *
     * @return list<string>
     */
    public static function sessionColumns(): array
    {
        return [
            'id',
            'id_no',
            'first_name',
            'last_name',
            'email',
            'role',
            'role_level',
            'job_title',
            'department',
            'status',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function rosterItem(Employee $employee): array
    {
        $role = $employee->role;
        $roleLevel = $employee->role_level;
        $isExecutive = PortalRole::isExecutive($role, $roleLevel);

        return [
            'id' => $employee->getKey(),
            'id_no' => $employee->id_no,
            'first_name' => $employee->first_name,
            'last_name' => $employee->last_name,
            'email' => $employee->email,
            'department' => $employee->department,
            'job_title' => $employee->job_title,
            'status' => $employee->status,
            'role' => $role,
            'role_level' => $roleLevel,
            'is_admin' => PortalRole::isAdmin($role, $roleLevel),
            'is_executive' => $isExecutive,
            'is_locked' => PortalRole::isLocked($role, $roleLevel),
        ];
    }

    /**
     * @return array{admins: int, members: int, active: int, inactive: int, total: int}
     */
    public static function counts(int $admins, int $active, int $total): array
    {
        return [
            'admins' => $admins,
            'members' => max(0, $total - $admins),
            'active' => $active,
            'inactive' => max(0, $total - $active),
            'total' => $total,
        ];
    }
}
