<?php

namespace App\Support\Portal;

use App\Modules\Portal\Models\Employee;

class PortalEmployeePresenter
{
    /**
     * Fields the portal session is allowed to see. Bank and identity documents stay off the wire.
     *
     * @return array<string, mixed>
     */
    public static function publicEmployee(Employee $employee): array
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
        ];
    }
}
