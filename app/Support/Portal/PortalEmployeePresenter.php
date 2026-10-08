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
            // In Developer mode only: what Default would allow, so the portal can warn before an export past it.
            'default_rules' => PortalDeveloperMode::isOn($employee, $request) ? array_keys(PortalRules::activeInDefault($employee, $request)) : null,
            'photo_url' => self::photoUrl($employee),
        ];
    }

    /**
     * The profile photo as a small square thumbnail: the avatar is at most 48px, and the stored
     * original is a full-size upload. Null when the employee has none, so the portal shows initials.
     */
    public static function photoUrl(Employee $employee): ?string
    {
        $id = (int) $employee->getKey();

        return self::photoUrls([$id])[$id] ?? null;
    }

    /**
     * Photo URLs for many employees in one read of the attachments table, keyed by employee id.
     * A photo that is missing, blank or not https is simply absent, so the portal shows initials.
     *
     * @param  list<int>  $employeeIds
     * @return array<int, string>
     */
    public static function photoUrls(array $employeeIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $employeeIds)));
        if ($ids === []) {
            return [];
        }

        // Newest first, so the first photo seen per employee is the current one.
        $rows = DB::connection('portal')
            ->table('attachments')
            ->where('table_name', 'employees')
            ->where('field_name', 'photo')
            ->whereIn('record_id', $ids)
            ->orderByDesc('id')
            ->get(['record_id', 'file_url', 'mime_type']);

        $urls = [];
        foreach ($rows as $row) {
            $id = (int) $row->record_id;
            if (isset($urls[$id]) || ! is_string($row->file_url) || ! str_starts_with($row->file_url, 'https://')) {
                continue;
            }
            $urls[$id] = app(PortalCloudinary::class)->thumbUrl($row->file_url, (string) ($row->mime_type ?? ''));
        }

        return $urls;
    }

    /**
     * Employee ids keyed by their full "first last" name, for the places a payload carries only a
     * name (a trail actor). A name that matches nobody is absent.
     *
     * @return array<string, int>
     */
    public static function idsByFullName(): array
    {
        $rows = DB::connection('portal')
            ->table('employees')
            ->selectRaw("id, TRIM(CONCAT(COALESCE(first_name, ''), ' ', COALESCE(last_name, ''))) as full_name")
            ->get();

        $ids = [];
        foreach ($rows as $row) {
            $name = trim((string) $row->full_name);
            if ($name !== '') {
                $ids[$name] = (int) $row->id;
            }
        }

        return $ids;
    }
}
