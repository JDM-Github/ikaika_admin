<?php

namespace App\Support\Portal;

use App\Modules\Portal\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PortalEmployeePresenter
{
    /**
     * Fields the portal session is allowed to see. Bank and identity documents stay off the wire.
     *
     * @return array<string, mixed>
     */
    public static function publicEmployee(Employee $employee, Request $request): array
    {
        return [
            'id' => $employee->getKey(),
            'id_no' => $employee->id_no,
            'first_name' => $employee->first_name,
            'last_name' => $employee->last_name,
            'email' => $employee->email,
            'role' => $employee->role,
            'role_level' => $employee->role_level,
            'job_title' => $employee->job_title,
            'department' => $employee->department,
            'status' => $employee->status,
            // Portal-only (separate.sql): may switch Developer mode on; it grants nothing by itself.
            'is_developer' => (bool) ($employee->is_developer ?? false),
            'developer_mode' => PortalDeveloperMode::isOn($employee, $request),
            // Active rule => reach, so the portal hides what cannot be opened; the server still checks.
            'rules' => (object) PortalRules::active($employee, $request),
            'photo_url' => self::photoUrl($employee),
        ];
    }

    /**
     * The profile photo as a small square thumbnail: the avatar is at most 48px, and the stored
     * original is a full-size upload. Null when the employee has none, so the portal shows initials.
     */
    private static function photoUrl(Employee $employee): ?string
    {
        $photo = DB::connection($employee->getConnectionName())
            ->table('attachments')
            ->where('table_name', 'employees')
            ->where('field_name', 'photo')
            ->where('record_id', $employee->getKey())
            ->orderByDesc('id')
            ->first(['file_url', 'mime_type']);
        if ($photo === null || ! is_string($photo->file_url) || ! str_starts_with($photo->file_url, 'https://')) {
            return null;
        }

        return app(PortalCloudinary::class)->thumbUrl($photo->file_url, (string) ($photo->mime_type ?? ''));
    }
}
