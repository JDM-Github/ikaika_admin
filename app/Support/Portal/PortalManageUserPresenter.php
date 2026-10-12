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
            'is_developer',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * One person's profile: who they are to anyone who may open them, and their private details only
     * when the viewer may see them (null otherwise, never an empty block that hints at what is there).
     *
     * @return array<string, mixed>
     */
    public static function profile(Employee $employee, ?string $photoUrl, bool $withPrivate): array
    {
        $text = static fn (mixed $value): ?string => is_string($value) && trim($value) !== '' ? trim($value) : null;
        $date = static fn (mixed $value): ?string => $value === null || $value === '' ? null : substr((string) $value, 0, 10);
        $role = $employee->role;
        $roleLevel = $employee->role_level;

        return [
            'id' => (string) $employee->getKey(),
            'idNo' => $text($employee->id_no),
            'name' => PortalAccessGraph::name($employee),
            'nickname' => $text($employee->nickname),
            'email' => $text($employee->email),
            'jobTitle' => $text($employee->job_title),
            'department' => $text($employee->department),
            'location' => $text($employee->location),
            'status' => $text($employee->status),
            'employmentStatus' => $text($employee->employment_status),
            'startDate' => $date($employee->start_date),
            'isAdmin' => PortalRole::isAdmin($role, $roleLevel),
            'isExecutive' => PortalRole::isExecutive($role, $roleLevel),
            'photoUrl' => $photoUrl,
            'information' => ! $withPrivate ? null : [
                'dateOfBirth' => $date($employee->date_of_birth),
                'bloodType' => $text($employee->blood_type),
                'address' => $text($employee->address),
                'personalEmail' => $text($employee->personal_email),
                'phoneNumber' => $text($employee->phone_number),
                'taxIdentificationNo' => $text($employee->tax_identification_no),
                'sssNo' => $text($employee->sss_no),
                'philhealthNo' => $text($employee->philhealth_no),
                'hdmfNo' => $text($employee->hdmf_no),
                'bankName' => $text($employee->bank_name),
                'bankAccountNumber' => $text($employee->bank_account_number),
                'bankSwiftCode' => $text($employee->bank_swift_code),
                'emergencyContactName' => $text($employee->emergency_contact_person_name),
                'emergencyContactNumber' => $text($employee->emergency_contact_number),
                'emergencyContactAddress' => $text($employee->emergency_contact_address),
            ],
        ];
    }

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
