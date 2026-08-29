<?php

namespace App\Modules\Portal;

use App\Modules\Portal\Models\ActivityCode;
use App\Modules\Portal\Models\Client;
use App\Modules\Portal\Models\Employee;
use App\Modules\Portal\Models\LeaveRequest;
use App\Modules\Portal\Models\Project;
use App\Modules\Portal\Models\ProjectActionHistory;
use App\Modules\Portal\Models\Reimbursement;
use App\Modules\Portal\Models\UserReport;
use App\Modules\Portal\Models\Warning;
use App\Modules\Support\ProductModule;

class PortalModule implements ProductModule
{
    public function key(): string
    {
        return 'portal';
    }

    public function name(): string
    {
        return 'Employee Portal';
    }

    public function resources(): array
    {
        return [
            'employees' => [
                'model' => Employee::class,
                'label' => 'Employees',
                'with' => ['projects'],
                'searchable' => ['first_name', 'last_name', 'email', 'id_no', 'nickname'],
            ],
            'projects' => [
                'model' => Project::class,
                'label' => 'Projects',
                'with' => ['clients', 'employees'],
                'searchable' => ['project_name', 'project_lead_email', 'status'],
            ],
            'clients' => [
                'model' => Client::class,
                'label' => 'Clients',
                'with' => ['projects'],
                'searchable' => ['name', 'client_id'],
            ],
            'reports' => [
                'model' => UserReport::class,
                'label' => 'User Reports',
                'with' => ['employees', 'projects'],
                'searchable' => ['remarks', 'approval'],
            ],
            'requests' => [
                'model' => LeaveRequest::class,
                'label' => 'Requests',
                'with' => ['projects'],
                'searchable' => ['name', 'reason', 'status', 'type'],
            ],
            'reimbursements' => [
                'model' => Reimbursement::class,
                'label' => 'Reimbursements',
                'with' => ['employees'],
                'searchable' => ['item', 'employee_name_input', 'status'],
            ],
            'warnings' => [
                'model' => Warning::class,
                'label' => 'Warnings',
                'with' => ['employees'],
                'searchable' => ['description', 'status', 'violation_type'],
            ],
            'activity-codes' => [
                'model' => ActivityCode::class,
                'label' => 'Activity Codes',
                'searchable' => ['name', 'id_no', 'department'],
            ],
            'action-history' => [
                'model' => ProjectActionHistory::class,
                'label' => 'Project Action History',
                'with' => ['projects'],
                'searchable' => ['action_name', 'created_by', 'remarks'],
            ],
        ];
    }
}
