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
    private const REQUEST_FIELDS = [
        'request_date' => 'Date',
        'name' => 'Name',
        'reason' => 'Reason',
        'status' => 'Status',
        'type' => 'Type',
        'approver_remarks' => 'Approver Remarks',
    ];

    private const WITHDRAWN = ['column' => 'status', 'value' => 'Cancelled'];

    // Airtable links a request to its projects; SQL keeps that in the projects_requests junction.
    private const REQUEST_PROJECTS = [
        'Project' => ['junction' => 'projects_requests', 'local' => 'request_id', 'foreign' => 'project_id', 'table' => 'projects'],
    ];

    /**
     * `discard_when` names a column value meaning the member withdrew the row: it is never sent,
     * and a copy Airtable already holds is removed, since the request was never really filed.
     * `links` resolve through a junction to the linked rows' own Airtable ids. `attachments` send
     * the stored file URLs, which Airtable copies in. `sample` narrows which row --check tries
     * when several resources share one table. `aliases` translate a value that means the same thing
     * under another word, which label matching alone cannot know. `changed_only` names the payload
     * keys listing an action's changed columns; only those are sent. `deleted_id` is the payload
     * key a single-row delete keeps its Airtable id under.
     *
     * @var array<string, array{sql_table: string, airtable_table: string, fields: array<string, string>, discard_when?: array{column: string, value: string}, sample?: array{column: string, value: string}, links?: array<string, array{junction: string, local: string, foreign: string, table: string}>, attachments?: array<string, array{table_name: string, field_name: string, payload_key?: string}>, aliases?: array<string, array<string, string>>, rows?: array{lines: string, removed: string, deleted: string}, changed_only?: list<string>, deleted_id?: string}>
     */
    private const RESOURCES = [
        // Airtable's Request table stores the member as plain text, so leave needs no linked record.
        'requests.leave' => [
            'sql_table' => 'requests',
            'sample' => ['column' => 'type', 'value' => 'Leave'],
            'airtable_table' => 'Request',
            'discard_when' => self::WITHDRAWN,
            'fields' => [...self::REQUEST_FIELDS, 'category' => 'Category'],
        ],
        'requests.overtime' => [
            'sql_table' => 'requests',
            'sample' => ['column' => 'type', 'value' => 'Overtime'],
            'airtable_table' => 'Request',
            'discard_when' => self::WITHDRAWN,
            'fields' => [...self::REQUEST_FIELDS, 'no_of_hours' => 'No. of Hours'],
            'links' => self::REQUEST_PROJECTS,
        ],
        'requests.offset' => [
            'sql_table' => 'requests',
            'sample' => ['column' => 'type', 'value' => 'Offset'],
            'airtable_table' => 'Request',
            'discard_when' => self::WITHDRAWN,
            'fields' => [
                ...self::REQUEST_FIELDS,
                'no_of_hours' => 'No. of Hours',
                'original_work_day' => 'Original Work Day',
                'offset_work_day' => 'Offset Work Day',
                'offset_hrs' => 'Offset Hrs',
            ],
            'links' => self::REQUEST_PROJECTS,
        ],
        'requests.reimbursement' => [
            'sql_table' => 'reimbursements',
            'airtable_table' => 'Reimbursements',
            'discard_when' => self::WITHDRAWN,
            'fields' => [
                'reimb_date' => 'Date',
                'item' => 'Item',
                'cost' => 'Cost',
                'qty' => 'QTY',
                'purpose' => 'Purpose',
                'employee_name_input' => 'Employee Name (Input your name so you can filter the IDs in "Reimburse To" field)',
                'team' => 'Team',
                'status' => 'Status',
                'approver_remarks' => 'Approver Remarks',
            ],
            'links' => [
                'Reimburse To' => ['junction' => 'employees_reimbursements', 'local' => 'reimbursement_id', 'foreign' => 'employee_id', 'table' => 'employees'],
            ],
            // A dropped item's snapshot carries the Airtable id, so the sync can delete that record.
            'deleted_id' => 'airtableRecordId',
            // The portal says Approved; this base calls a settled claim Completed, and so does the old portal.
            'aliases' => ['Status' => ['Approved' => 'Completed']],
            'attachments' => [
                // `receipt` is the payload key that says whether the member kept or removed it.
                'Receipts (If Applicable)' => ['table_name' => 'reimbursements', 'field_name' => 'receipts', 'payload_key' => 'receipt'],
                'Reimbursement Receipt' => ['table_name' => 'reimbursements', 'field_name' => 'payout_receipt'],
            ],
        ],
        // One filing is one user_reports row per line. The ledger names the lines rather than the
        // row: `lines` lists the rows an add or edit left, `removed` the Airtable ids of lines an
        // edit dropped, and `deleted` the snapshot lines a whole-report delete took with it.
        'reports.submitted' => [
            'sql_table' => 'user_reports',
            'airtable_table' => 'User Reports',
            'rows' => ['lines' => 'lineIds', 'removed' => 'removedAirtableIds', 'deleted' => 'lines'],
            // Only what the report form writes. Late-submission approval, IsSupport, Actual Work
            // Date, End date, Duration and Approver Remarks are Airtable's to keep: the form never
            // sets them, so sending the empty SQL value would erase them from a record an edit reuses.
            'fields' => [
                'report_date' => 'Date',
                'hours_rendered' => 'Hours Rendered',
                'change_in_elements' => 'Change in Elements (+/-)',
                'remarks' => 'Remarks',
                'late_submission' => 'LateSubmission',
                'approval' => 'Approval',
            ],
            'links' => [
                'Employee No.' => ['junction' => 'employees_user_reports', 'local' => 'user_report_id', 'foreign' => 'employee_id', 'table' => 'employees'],
                'Project' => ['junction' => 'projects_user_reports', 'local' => 'user_report_id', 'foreign' => 'project_id', 'table' => 'projects'],
                'Activity' => ['junction' => 'user_reports_activity_codes', 'local' => 'user_report_id', 'foreign' => 'activity_code_id', 'table' => 'activity_codes'],
                'Earn Code' => ['junction' => 'user_reports_earn_codes', 'local' => 'user_report_id', 'foreign' => 'earn_code_id', 'table' => 'earn_codes'],
            ],
        ],
        // An admin's late-submission decision is not a filing: reports.submitted is what sends the
        // report itself, so this resource carries only the two columns the decision changed, and only
        // on the lines it decided. Unmapped before 2026-10-07, which left every decision skipped.
        'reports.late' => [
            'sql_table' => 'user_reports',
            'airtable_table' => 'User Reports',
            'rows' => ['lines' => 'userReportIds', 'removed' => 'removedAirtableIds', 'deleted' => 'lines'],
            'changed_only' => ['fields', 'changes'],
            'fields' => [
                'late_submission_approval' => 'LateSubmission Approval',
                'approver_remarks' => 'Approver Remarks',
            ],
        ],
        // The portal's own Add/Edit Project screens. A project the portal adds is created from every
        // mapped column, since Airtable holds no earlier state to protect; an edit sends only the
        // columns the action changed, so a PM's own Airtable work is never re-sent over. Deletes stay
        // unsynced: the portal soft-deletes into the recycle bin, and the record is Airtable's.
        'projects' => [
            'sql_table' => 'projects',
            'airtable_table' => 'Project/s',
            'changed_only' => ['columns', 'fields', 'changes'],
            'create_full' => true,
            'fields' => [
                'project_name' => 'Project Name',
                'department' => 'Department',
                'type_of_job' => 'Type of Job',
                'area_sqft' => 'Area (in S.F.)',
                'status' => 'Status',
                'project_lead_email' => 'Project Lead Email',
                'lod' => 'LOD',
                'ar_lod' => 'AR LOD',
                'st_lod' => 'ST LOD',
                'md_lod' => 'MD LOD',
                'el_lod' => 'EL LOD',
                'pl_lod' => 'PL LOD',
                'fp_lod' => 'FP LOD',
                'forma_link' => 'Forma Link',
                'msteams_project_link' => 'MSTeams Project Link',
                'ms_planner_link' => 'MS Planner Link',
                'panoramic_link' => 'Panoramic Link',
                'ms_loop_link' => 'MS Loop Link',
                'google_drive_folder_path' => 'Google Drive Folder Path',
                'project_creation_status' => 'ProjectCreationStatus',
                'project_creation_approver' => 'ProjectCreationApprover',
                'project_approver_email' => 'Project Approver Email',
                'project_creation_approver_remarks' => 'ProjectCreationApproverRemarks',
            ],
            'links' => [
                // A project names its lead by email, and SQL keeps no lead row to walk: the match is
                // that email against the employee's own.
                'PM ID' => ['table' => 'employees', 'matches' => 'email', 'from' => 'project_lead_email'],
                'Client' => ['junction' => 'projects_clients', 'local' => 'project_id', 'foreign' => 'client_id', 'table' => 'clients'],
                // One junction, three fields: the role on it is what tells members from support.
                'Project Members' => ['junction' => 'employees_projects', 'local' => 'project_id', 'foreign' => 'employee_id', 'table' => 'employees', 'where' => ['role_on_project' => 'member']],
                'Support Members' => ['junction' => 'employees_projects', 'local' => 'project_id', 'foreign' => 'employee_id', 'table' => 'employees', 'where' => ['role_on_project' => 'support']],
            ],
        ],
        // The admin grid ledgers an edit under the bare table name. Only the columns it changed are
        // sent, and only to a record Airtable already has, since the grid edits rows in place.
        'requests' => [
            'sql_table' => 'requests',
            'airtable_table' => 'Request',
            'changed_only' => ['fields', 'changes'],
            'fields' => [
                ...self::REQUEST_FIELDS,
                'category' => 'Category',
                'no_of_hours' => 'No. of Hours',
                'original_work_day' => 'Original Work Day',
                'offset_work_day' => 'Offset Work Day',
                'offset_hrs' => 'Offset Hrs',
            ],
        ],
        'reimbursements' => [
            'sql_table' => 'reimbursements',
            'airtable_table' => 'Reimbursements',
            'changed_only' => ['fields', 'changes'],
            'aliases' => ['Status' => ['Approved' => 'Completed']],
            'fields' => [
                'reimb_date' => 'Date',
                'item' => 'Item',
                'cost' => 'Cost',
                'qty' => 'QTY',
                'purpose' => 'Purpose',
                'employee_name_input' => 'Employee Name (Input your name so you can filter the IDs in "Reimburse To" field)',
                'team' => 'Team',
                'status' => 'Status',
                'approver_remarks' => 'Approver Remarks',
            ],
        ],
        // An admin may correct any report column from the grid, including the ones the form leaves
        // to Airtable; changed-only means a correction reaches Airtable without touching the rest.
        'user_reports' => [
            'sql_table' => 'user_reports',
            'airtable_table' => 'User Reports',
            'changed_only' => ['fields', 'changes'],
            'fields' => [
                'report_date' => 'Date',
                'hours_rendered' => 'Hours Rendered',
                'change_in_elements' => 'Change in Elements (+/-)',
                'remarks' => 'Remarks',
                'late_submission' => 'LateSubmission',
                'late_submission_approval' => 'LateSubmission Approval',
                'approval' => 'Approval',
                'approver_remarks' => 'Approver Remarks',
                'actual_work_date' => 'Actual Work Date',
                'is_support' => 'IsSupport',
                'end_date' => 'End date',
                'duration' => 'Duration',
            ],
        ],
        // Only the columns the portal itself changes (the role screen and the admin grid's plain
        // profile fields); government ids, bank details and scans stay Airtable's to maintain.
        // HR still edits this table in Airtable, so an update sends only what the action changed.
        'employees' => [
            'sql_table' => 'employees',
            'airtable_table' => 'Employees',
            'changed_only' => ['fields', 'changes'],
            'fields' => [
                'id_no' => 'ID No.',
                'first_name' => 'First Name',
                'last_name' => 'Last Name',
                'middle_name' => 'Middle Name',
                'nickname' => 'Nickname',
                'email' => 'Email',
                'job_title' => 'Job Title',
                'status' => 'Status',
                'employment_status' => 'Employment Status',
                'role' => 'Role',
                'role_level' => 'Role Level',
                'department' => 'Department',
                'location' => 'Location',
            ],
        ],
    ];

    /**
     * Resources that never reach Airtable, by design rather than for lack of a mapping. Their actions
     * settle as "nothing to send" so they stop reappearing as skipped on every run.
     *
     * @var array<string, string>
     */
    private const PORTAL_ONLY = [
        'reports.flagged' => 'Flag decisions are portal-only (separate.sql); Airtable has no field for them.',
    ];

    public static function portalOnly(?string $resource): ?string
    {
        return $resource === null ? null : (self::PORTAL_ONLY[$resource] ?? null);
    }

    /**
     * @return array{sql_table: string, airtable_table: string, fields: array<string, string>, discard_when?: array{column: string, value: string}, sample?: array{column: string, value: string}, links?: array<string, array{junction: string, local: string, foreign: string, table: string}>, attachments?: array<string, array{table_name: string, field_name: string, payload_key?: string}>, aliases?: array<string, array<string, string>>, rows?: array{lines: string, removed: string, deleted: string}, changed_only?: list<string>, deleted_id?: string}|null
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
