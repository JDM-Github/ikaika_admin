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

    public function sections(): array
    {
        return [
            'home' => [
                'label' => 'Home',
                'requires_admin' => false,
                'resources' => [
                    'dashboard' => [
                        'label' => 'Dashboard',
                        'path' => 'home',
                        'requires_admin' => false,
                        'operations' => [
                            [
                                'method' => 'GET',
                                'path' => 'home',
                                'label' => 'Show',
                                'auth' => true,
                            ],
                        ],
                    ],
                ],
            ],
            'calendar' => [
                'label' => 'Calendar',
                'requires_admin' => false,
                'resources' => [
                    'holidays' => [
                        'label' => 'Holidays',
                        'path' => 'calendar/holidays',
                        'requires_admin' => false,
                        'operations' => [
                            [
                                'method' => 'GET',
                                'path' => 'calendar/holidays',
                                'label' => 'List',
                                'auth' => true,
                            ],
                        ],
                    ],
                    'events' => [
                        'label' => 'Events',
                        'path' => 'calendar/events',
                        'requires_admin' => false,
                        'operations' => [
                            [
                                'method' => 'GET',
                                'path' => 'calendar/events',
                                'label' => 'List',
                                'auth' => true,
                            ],
                            [
                                'method' => 'POST',
                                'path' => 'calendar/events',
                                'label' => 'Create',
                                'auth' => true,
                            ],
                            [
                                'method' => 'GET',
                                'path' => 'calendar/event-options',
                                'label' => 'Options',
                                'auth' => true,
                            ],
                        ],
                    ],
                ],
            ],
            'projects' => [
                'label' => 'Projects',
                'requires_admin' => false,
                'resources' => [
                    'board' => [
                        'label' => 'View Projects',
                        'path' => 'projects',
                        'requires_admin' => false,
                        'operations' => [
                            [
                                'method' => 'GET',
                                'path' => 'projects',
                                'label' => 'List',
                                'auth' => true,
                            ],
                        ],
                    ],
                ],
            ],
            'manage' => [
                'label' => 'Manage',
                'requires_admin' => true,
                'resources' => [
                    'users' => [
                        'label' => 'Users',
                        'path' => 'manage/users',
                        'requires_admin' => true,
                        'operations' => [
                            [
                                'method' => 'GET',
                                'path' => 'manage/users',
                                'label' => 'List',
                                'auth' => true,
                            ],
                            [
                                'method' => 'PATCH',
                                'path' => 'manage/users/{id}/role',
                                'label' => 'Set role',
                                'auth' => true,
                                'body' => ['role' => 'Admin'],
                            ],
                        ],
                    ],
                    'requests' => [
                        'label' => 'Requests',
                        'path' => 'manage/requests',
                        'requires_admin' => true,
                        'operations' => [
                            [
                                'method' => 'GET',
                                'path' => 'manage/requests',
                                'label' => 'List',
                                'auth' => true,
                            ],
                        ],
                    ],
                    'reports' => [
                        'label' => 'Reports',
                        'path' => 'manage/reports',
                        'requires_admin' => true,
                        'operations' => [
                            [
                                'method' => 'GET',
                                'path' => 'manage/reports',
                                'label' => 'List',
                                'auth' => true,
                            ],
                        ],
                    ],
                ],
            ],
            'reports' => [
                'label' => 'Reports',
                'requires_admin' => false,
                'resources' => [
                    'submitted' => [
                        'label' => 'Submitted Reports',
                        'path' => 'reports/submitted',
                        'requires_admin' => false,
                        'operations' => [
                            [
                                'method' => 'GET',
                                'path' => 'reports/submitted',
                                'label' => 'List',
                                'auth' => true,
                            ],
                            [
                                'method' => 'PATCH',
                                'path' => 'reports/submitted/{id}',
                                'label' => 'Replace',
                                'auth' => true,
                                'body' => [
                                    'entries' => [
                                        [
                                            'projectLabel' => '260005 IKAIKA Portal V2',
                                            'activityLabel' => '5000 - WEB APPLICATION DEVELOPMENT',
                                            'hoursRendered' => 8,
                                            'elementChange' => 0,
                                        ],
                                    ],
                                    'remarks' => null,
                                ],
                            ],
                            [
                                'method' => 'DELETE',
                                'path' => 'reports/submitted/{id}',
                                'label' => 'Delete',
                                'auth' => true,
                            ],
                        ],
                    ],
                ],
            ],
            'requests' => [
                'label' => 'Requests',
                'requires_admin' => false,
                'resources' => [
                    'leave' => [
                        'label' => 'Leave',
                        'path' => 'requests/leave',
                        'requires_admin' => false,
                        'operations' => [
                            [
                                'method' => 'GET',
                                'path' => 'requests/leave',
                                'label' => 'List',
                                'auth' => true,
                            ],
                            [
                                'method' => 'POST',
                                'path' => 'requests/leave',
                                'label' => 'File',
                                'auth' => true,
                                'body' => [
                                    'leaveType' => '02 Sick Leave',
                                    'startDate' => '2026-09-09',
                                    'endDate' => '2026-09-09',
                                    'reason' => 'Flu',
                                ],
                            ],
                        ],
                    ],
                    'overtime' => [
                        'label' => 'Overtime',
                        'path' => 'requests/overtime',
                        'requires_admin' => false,
                        'operations' => [
                            [
                                'method' => 'GET',
                                'path' => 'requests/overtime',
                                'label' => 'List',
                                'auth' => true,
                            ],
                            [
                                'method' => 'POST',
                                'path' => 'requests/overtime',
                                'label' => 'File',
                                'auth' => true,
                                'body' => [
                                    'requests' => [
                                        [
                                            'requestDate' => '2026-08-20',
                                            'reason' => 'Deadline',
                                            'entries' => [
                                                [
                                                    'projectLabel' => '260005 IKAIKA Portal V2',
                                                    'activityLabel' => '5000 - WEB APPLICATION DEVELOPMENT',
                                                    'hoursRendered' => 2,
                                                    'elementChange' => 0,
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                            [
                                'method' => 'PATCH',
                                'path' => 'requests/overtime/{id}',
                                'label' => 'Edit',
                                'auth' => true,
                            ],
                            [
                                'method' => 'POST',
                                'path' => 'requests/overtime/{id}/cancel',
                                'label' => 'Cancel',
                                'auth' => true,
                            ],
                        ],
                    ],
                    'offset' => [
                        'label' => 'Offset',
                        'path' => 'requests/offset',
                        'requires_admin' => false,
                        'operations' => [
                            [
                                'method' => 'GET',
                                'path' => 'requests/offset',
                                'label' => 'List',
                                'auth' => true,
                            ],
                            [
                                'method' => 'POST',
                                'path' => 'requests/offset',
                                'label' => 'File',
                                'auth' => true,
                                'body' => [
                                    'requests' => [
                                        [
                                            'workDate' => '2026-08-16',
                                            'dayOffDate' => '2026-08-18',
                                            'reason' => 'Saturday coverage',
                                            'entries' => [
                                                [
                                                    'projectLabel' => '260005 IKAIKA Portal V2',
                                                    'activityLabel' => '5000 - WEB APPLICATION DEVELOPMENT',
                                                    'hoursRendered' => 8,
                                                    'elementChange' => 0,
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                            [
                                'method' => 'PATCH',
                                'path' => 'requests/offset/{id}',
                                'label' => 'Edit',
                                'auth' => true,
                            ],
                            [
                                'method' => 'POST',
                                'path' => 'requests/offset/{id}/cancel',
                                'label' => 'Cancel',
                                'auth' => true,
                            ],
                        ],
                    ],
                    'reimbursement' => [
                        'label' => 'Reimbursement',
                        'path' => 'requests/reimbursement',
                        'requires_admin' => false,
                        'operations' => [
                            [
                                'method' => 'GET',
                                'path' => 'requests/reimbursement',
                                'label' => 'List',
                                'auth' => true,
                            ],
                            [
                                'method' => 'POST',
                                'path' => 'requests/reimbursement',
                                'label' => 'File',
                                'auth' => true,
                                'body' => [
                                    'requestDate' => '2026-08-04',
                                    'items' => [
                                        [
                                            'label' => 'Office Grocery',
                                            'cost' => 2095.6,
                                            'quantity' => 1,
                                            'teamLabel' => 'Angeles Pampanga Office',
                                            'purpose' => 'Office grocery and maintenance',
                                        ],
                                    ],
                                ],
                            ],
                            [
                                'method' => 'POST',
                                'path' => 'requests/reimbursement/receipts',
                                'label' => 'Upload receipt',
                                'auth' => true,
                            ],
                            [
                                'method' => 'PATCH',
                                'path' => 'requests/reimbursement/{id}',
                                'label' => 'Edit',
                                'auth' => true,
                            ],
                            [
                                'method' => 'POST',
                                'path' => 'requests/reimbursement/{id}/cancel',
                                'label' => 'Cancel',
                                'auth' => true,
                            ],
                        ],
                    ],
                ],
            ],
            'administration' => [
                'label' => 'Administration',
                'requires_admin' => false,
                'resources' => [
                    'recycle-bin' => [
                        'label' => 'Recycle Bin',
                        'path' => 'administration/recycle-bin',
                        'requires_admin' => false,
                        'operations' => [
                            [
                                'method' => 'GET',
                                'path' => 'administration/recycle-bin',
                                'label' => 'List',
                                'auth' => true,
                            ],
                            [
                                'method' => 'GET',
                                'path' => 'administration/recycle-bin/{id}',
                                'label' => 'Show',
                                'auth' => true,
                            ],
                            [
                                'method' => 'POST',
                                'path' => 'administration/recycle-bin/{id}/restore',
                                'label' => 'Restore',
                                'auth' => true,
                            ],
                        ],
                    ],
                    'all-logs' => [
                        'label' => 'All Logs',
                        'path' => 'administration/all-logs',
                        'requires_admin' => true,
                        'operations' => [
                            [
                                'method' => 'GET',
                                'path' => 'administration/all-logs',
                                'label' => 'List',
                                'auth' => true,
                            ],
                        ],
                    ],
                ],
            ],
            'user' => [
                'label' => 'User',
                'requires_admin' => false,
                'resources' => [
                    'logs' => [
                        'label' => 'Logs',
                        'path' => 'user/logs',
                        'requires_admin' => false,
                        'operations' => [
                            [
                                'method' => 'GET',
                                'path' => 'user/logs',
                                'label' => 'List',
                                'auth' => true,
                            ],
                        ],
                    ],
                    'notifications' => [
                        'label' => 'Notifications',
                        'path' => 'user/notifications',
                        'requires_admin' => false,
                        'operations' => [
                            [
                                'method' => 'GET',
                                'path' => 'user/notifications',
                                'label' => 'List',
                                'auth' => true,
                            ],
                            [
                                'method' => 'PATCH',
                                'path' => 'user/notifications/{id}/read',
                                'label' => 'Mark read',
                                'auth' => true,
                            ],
                            [
                                'method' => 'POST',
                                'path' => 'user/notifications/read-all',
                                'label' => 'Mark all read',
                                'auth' => true,
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    public function authOperations(): array
    {
        return [
            [
                'method' => 'POST',
                'path' => 'auth/login',
                'label' => 'Login',
                'body' => ['id_no' => ''],
            ],
            [
                'method' => 'POST',
                'path' => 'auth/login/microsoft',
                'label' => 'Microsoft login',
                'body' => [
                    'code' => '',
                    'code_verifier' => '',
                    'redirect_uri' => '',
                ],
            ],
            [
                'method' => 'GET',
                'path' => 'auth/me',
                'label' => 'Session',
                'auth' => true,
            ],
        ];
    }

    public function utilities(): array
    {
        return [];
    }
}
