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

    // One list per mirrored table, shared by the portal's own resource and the admin grid's bare
    // table name, so the two never drift apart (2026-10-14).
    // Client Information has Name and Notes; the two-digit code is the portal's project-number key.
    private const CLIENT_FIELDS = ['name' => 'Name', 'notes' => 'Notes'];

    private const ACTIVITY_CODE_FIELDS = ['name' => 'Name', 'id_no' => 'IdNo', 'department' => 'Department'];

    private const WARNING_FIELDS = ['warning_date' => 'Date', 'description' => 'Description', 'status' => 'Status', 'violation_type' => 'Violation Type'];

    private const ALLOWED_FIELDS = ['job_type' => 'Job Type', 'activity_code_id_initials' => 'Activity Code ID Initials'];

    private const TRAIL_FIELDS = ['action_name' => 'ActionName', 'created_by' => 'CreatedBy', 'remarks' => 'Remarks'];

    private const TRAIL_PROJECT = [
        'Project' => ['junction' => 'projects_action_history', 'local' => 'project_action_id', 'foreign' => 'project_id', 'table' => 'projects'],
    ];

    // What a candidate submits on registration; Airtable's New Employee Data mirrors Employees' names.
    private const NEW_EMPLOYEE_FIELDS = [
        'id_no' => 'ID No.',
        'first_name' => 'First Name',
        'middle_name' => 'Middle Name',
        'last_name' => 'Last Name',
        'nickname' => 'Nickname',
        'email' => 'Email',
        'job_title' => 'Job Title',
        'start_date' => 'Start Date',
        'status' => 'Status',
        'date_of_birth' => 'Date of Birth',
        'address' => 'Address',
        'personal_email' => 'Personal E-Mail',
        'phone_number' => 'Phone Number',
        'tax_identification_no' => 'Tax Identification No.',
        'philhealth_no' => 'PhilHealth No.',
        'sss_no' => 'SSS No.',
        'hdmf_no' => 'HDMF No.',
        'role' => 'Role',
        'department' => 'Department',
        'emergency_contact_person_name' => 'Emergency Contact Person Name',
        'emergency_contact_number' => 'Emergency Contact Number',
        'emergency_contact_address' => 'Emergency Contact Address',
        'bank_name' => 'Bank Name',
        'bank_account_number' => 'Bank Account Number',
        'bank_swift_code' => 'Bank SWIFT Code',
        'data_approval' => 'Data Approval',
    ];

    // The candidate's own picks the base may not offer, left out with a note on both the portal's and the grid's path.
    private const NEW_EMPLOYEE_LENIENT = ['Job Title', 'Department', 'Bank Name', 'Role', 'Status', 'Data Approval'];

    private const T1_FIELDS = [
        'wbs_code' => 'WBS Code', 'process_index' => 'Process Index', 'level' => 'Level', 'type' => 'Type', 'name' => 'Name',
        'process_wbs' => 'Process WBS', 'sop_reference' => 'SOP Reference', 'time_trackable' => 'Time Trackable',
        'department' => 'Department', 'status' => 'Status',
    ];

    private const T2_FIELDS = [
        'wbs_code' => 'WBS Code', 'sequence_index' => 'Sequence Index', 'level' => 'Level', 'type' => 'Type', 'name' => 'Name',
        'process' => 'Process', 'activity_wbs' => 'Activity WBS', 'sop_reference' => 'SOP Reference', 'time_trackable' => 'Time Trackable',
        'department' => 'Department', 'status' => 'Status',
    ];

    private const T3_FIELDS = [
        'wbs_code' => 'WBS Code', 'activity_index' => 'Activity Index', 'level' => 'Level', 'type' => 'Type', 'name' => 'Name',
        'procedure_wbs' => 'Procedure WBS', 'procedure' => 'Procedure', 'process_wbs' => 'Process WBS', 'process' => 'Process',
        'sop_reference' => 'SOP Reference', 'time_trackable' => 'Time Trackable', 'department' => 'Department', 'status' => 'Status',
    ];

    private const T4_FIELDS = [
        'wbs_code' => 'WBS Code', 'task_index' => 'Task Index', 'task_name' => 'Task Name', 'task_notes' => 'Task Notes', 'task' => 'Task',
        'activity_name' => 'Activity Name', 'activity' => 'Activity', 'procedure_name' => 'Procedure Name', 'procedure' => 'Procedure',
        'description' => 'Description', 'start_date' => 'Start Date', 'due_date' => 'Due Date', 'time_spent_hrs' => 'Time Spent (hrs)',
        'status' => 'Status', 'priority' => 'Priority',
    ];

    private const EARN_CODE_FIELDS = ['description' => 'Description', 'did_not_work' => 'Did Not Work', 'did_work' => 'Did Work'];

    private const ACHIEVEMENT_FIELDS = ['achievement_date' => 'Date', 'project_name' => 'Project Name', 'description' => 'Description', 'links' => 'Links'];

    private const BIM_FORM_FIELDS = [
        'project_number' => 'Project Number', 'project_name' => 'Project Name', 'project_address' => 'Project Address',
        'project_size_sqft' => 'Project Size (sq. ft)', 'status' => 'Status', 'no_of_floors' => 'No. of Floors',
        'client_name' => 'Client Name', 'poc_name' => 'Point of Contact (POC)', 'poc_phone' => 'POC Phone #',
        'purpose_of_scan_to_bim' => 'Purpose of Scan to BIM Model', 'arch_lod' => 'ARCH LOD', 'struct_lod' => 'STRUCT LOD',
        'mech_lod' => 'MECH LOD', 'elect_lod' => 'ELECT LOD', 'plumb_lod' => 'PLUMB LOD', 'fp_lod' => 'FP LOD',
        'client_provides_point_cloud' => 'Client will provide point cloud scan', 'point_cloud_scan_format' => 'Point Cloud Scan Format',
        'client_has_existing_cad_rvt' => 'Client has existing CAD and RVT File', 'special_requests' => 'Special Requests',
        'insertion_point_strategy' => 'Insertion Point Strategy', 'preferred_workset' => 'Preffered Workset',
        'form_assigned_by' => 'Form Assigned By', 'filled_up_by' => 'Filled Up By',
    ];

    // The admin grid edits a row in place: only the columns it changed, only on a record Airtable has.
    private const GRID = ['changed_only' => ['columns', 'fields', 'changes']];

    // Airtable links a request to its projects, activities and earn code; SQL keeps them in one junction each.
    private const REQUEST_LINKS = [
        'Project' => ['junction' => 'projects_requests', 'local' => 'request_id', 'foreign' => 'project_id', 'table' => 'projects'],
        'Activity Scope' => ['junction' => 'requests_activity_codes', 'local' => 'request_id', 'foreign' => 'activity_code_id', 'table' => 'activity_codes'],
        'Earn Code' => ['junction' => 'requests_earn_codes', 'local' => 'request_id', 'foreign' => 'earn_code_id', 'table' => 'earn_codes'],
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
     * @var array<string, array{sql_table: string, airtable_table: string, base?: string, product?: string, connection?: string, record_id_column?: string, fields: array<string, string>, discard_when?: array{column: string, value: string}, sample?: array{column: string, value: string}, links?: array<string, array{junction?: string, local?: string, foreign?: string, table: string, where?: array<string, string>, matches?: string, from?: string, from_actor?: bool, query?: string, column?: string, id_column?: string}>, attachments?: array<string, array{table_name: string, field_name: string, payload_key?: string}>, aliases?: array<string, array<string, string>>, rows?: array{lines: string, removed: string, deleted: string}, changed_only?: list<string>, deleted_id?: string}>
     */
    private const RESOURCES = [
        // Airtable's Request table stores the member as plain text, so leave needs no linked record.
        'requests.leave' => [
            'sql_table' => 'requests',
            'sample' => ['column' => 'type', 'value' => 'Leave'],
            'airtable_table' => 'Request',
            'discard_when' => self::WITHDRAWN,
            // The base offers Sick and Vacation Leave only (2026-10-14): an Emergency, Bereavement or
            // Maternity request goes without its Category rather than being held back forever.
            'lenient' => ['Category'],
            'fields' => [...self::REQUEST_FIELDS, 'category' => 'Category'],
            'links' => ['Earn Code' => self::REQUEST_LINKS['Earn Code']],
        ],
        'requests.overtime' => [
            'sql_table' => 'requests',
            'sample' => ['column' => 'type', 'value' => 'Overtime'],
            'airtable_table' => 'Request',
            'discard_when' => self::WITHDRAWN,
            'fields' => [...self::REQUEST_FIELDS, 'no_of_hours' => 'No. of Hours'],
            'links' => self::REQUEST_LINKS,
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
            'links' => self::REQUEST_LINKS,
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
            // Teams come from References; a new office is left out with a note, never blocking the claim (2026-10-20).
            'lenient' => ['Team'],
            'attachments' => [
                // `receipt` is the payload key that says whether the member kept or removed it.
                'Receipts (If Applicable)' => ['table_name' => 'reimbursements', 'field_name' => 'receipts', 'payload_key' => 'receipt'],
                'Reimbursement Receipt' => ['table_name' => 'reimbursements', 'field_name' => 'payout_receipt', 'changed_key' => 'payoutReceipt'],
            ],
        ],
        // One filing is one user_reports row per line. The ledger names the lines rather than the
        // row: `lines` lists the rows an add or edit left, `removed` the Airtable ids of lines an
        // edit dropped, and `deleted` the snapshot lines a whole-report delete took with it.
        'reports.submitted' => [
            'sql_table' => 'user_reports',
            'airtable_table' => 'User Reports',
            'rows' => ['lines' => 'lineIds', 'removed' => 'removedAirtableIds', 'deleted' => 'lines'],
            // Only what the report form writes. IsSupport, End date, Duration and Approver Remarks are
            // Airtable's to keep: the form never sets them, so sending the empty SQL value would erase
            // them from a record an edit reuses. Actual Work Date is the report's own day (2026-10-22,
            // user), and Late-submission approval is the form's (Pending when filed, back to Pending on
            // an edit), so they go (2026-10-14).
            'fields' => [
                'report_date' => 'Date',
                'actual_work_date' => 'Actual Work Date',
                'hours_rendered' => 'Hours Rendered',
                'change_in_elements' => 'Change in Elements (+/-)',
                'remarks' => 'Remarks',
                'late_submission' => 'LateSubmission',
                'late_submission_approval' => 'LateSubmission Approval',
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
                'actual_work_date' => 'Actual Work Date',
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
            // The form offers LOD 100, which no LOD field in this base has (2026-10-14): left out with a
            // note rather than holding the whole project, and everything filed against it, back.
            // Department and Type of Job come from References lists anyone may grow (2026-10-20): a new one
            // is left out with a note, never blocking the project and everything filed against it.
            'lenient' => ['LOD', 'AR LOD', 'ST LOD', 'MD LOD', 'EL LOD', 'PL LOD', 'FP LOD', 'Department', 'Type of Job'],
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
                // The completion checklist closing a project asks for (2026-10-13).
                'is_renamed_with_acc_number' => 'Is the ACC project renamed with ACC latest project number?',
                'is_ledger_moved_to_for_submission' => 'Is the project ledger (MS Teams Planner) moved and updated to For Submission (completed status)?',
                'is_ledger_details_updated' => 'Are the details in the project ledger (MS Teams Planner) updated?',
            ],
            // `column` names the key an edit's `fields` map carries when it changed that link, so an
            // update sends a link only when the action changed it (an approval never re-sends people).
            'links' => [
                // A project names its lead by email, and SQL keeps no lead row to walk: the match is
                // that email against the employee's own.
                'PM ID' => ['table' => 'employees', 'matches' => 'email', 'from' => 'project_lead_email'],
                'Client' => ['junction' => 'projects_clients', 'local' => 'project_id', 'foreign' => 'client_id', 'table' => 'clients', 'column' => 'clientId'],
                // One junction, three fields: the role on it is what tells members from support.
                'Project Members' => ['junction' => 'employees_projects', 'local' => 'project_id', 'foreign' => 'employee_id', 'table' => 'employees', 'where' => ['role_on_project' => 'member'], 'column' => 'memberIds'],
                'Support Members' => ['junction' => 'employees_projects', 'local' => 'project_id', 'foreign' => 'employee_id', 'table' => 'employees', 'where' => ['role_on_project' => 'support'], 'column' => 'supportMemberIds'],
                // The portal's creator and the actor of every project action (2026-10-22, user).
                'createdBy' => ['table' => 'employees', 'matches' => 'id', 'from' => 'requested_by', 'column' => 'createdBy'],
                'lastModifiedBy' => ['table' => 'employees', 'from_actor' => true],
                // The type's allowed activity codes, less the ones this project took out.
                'Activity Scope' => ['table' => 'activity_codes', 'query' => 'activity_scope', 'column' => 'activityIds'],
            ],
        ],
        // One record per assigned scope task (2026-10-22, user): the portal owns these, so an
        // assignment that goes takes its Airtable record with it.
        'projects.scopes' => [
            'sql_table' => 'project_scopes',
            'airtable_table' => 'Project Scopes',
            'changed_only' => ['fields', 'changes'],
            'create_full' => true,
            'deleted_id' => 'airtableRecordId',
            'fields' => ['progress_pct' => 'Progress'],
            'links' => [
                'Project' => ['junction' => 'projects_project_scopes', 'local' => 'project_scope_id', 'foreign' => 'project_id', 'table' => 'projects', 'column' => 'projectIds'],
                'Scope - T3 Activities' => ['junction' => 'project_scopes_t3_activities', 'local' => 'project_scope_id', 'foreign' => 't3_activity_id', 'table' => 'project_scope_t3_activities', 'column' => 't3ActivityIds'],
                'Scope - T4 Tasks' => ['junction' => 'project_scopes_t4_tasks', 'local' => 'project_scope_id', 'foreign' => 't4_task_id', 'table' => 'project_scope_t4_tasks', 'column' => 't4TaskIds'],
                'AssignedTo' => ['junction' => 'project_scopes_assigned_to', 'local' => 'project_scope_id', 'foreign' => 'employee_id', 'table' => 'employees', 'column' => 'assigneeIds'],
            ],
        ],
        // The admin grid ledgers an edit under the bare table name. Only the columns it changed are
        // sent, and only to a record Airtable already has, since the grid edits rows in place.
        'requests' => [
            'sql_table' => 'requests',
            'airtable_table' => 'Request',
            'changed_only' => ['fields', 'changes'],
            // A grid edit to Cancelled, or to a leave kind the base lacks, acts as the portal's own path does (2026-10-20).
            'discard_when' => self::WITHDRAWN,
            'lenient' => ['Category'],
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
            'discard_when' => self::WITHDRAWN,
            'aliases' => ['Status' => ['Approved' => 'Completed']],
            // Teams come from References; a new office is left out with a note, never blocking the claim (2026-10-20).
            'lenient' => ['Team'],
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
        // HR still edits this table in Airtable, so an update sends only what the action changed. A
        // person the portal adds (Add user, onboarding Promote) is created whole (2026-10-14, user):
        // everything linking them would otherwise wait forever for an Airtable id. Job Title is a
        // select there; a title it does not offer is left out rather than holding the person back.
        'employees' => [
            'sql_table' => 'employees',
            'airtable_table' => 'Employees',
            'changed_only' => ['columns', 'fields', 'changes'],
            'create_full' => true,
            'lenient' => ['Job Title', 'Blood Type', 'Bank Name', 'Department', 'Location'],
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
                // Employee information (2026-10-14, user): the private details Manage / Users edits.
                'start_date' => 'Start Date',
                'date_of_birth' => 'Date of Birth',
                'blood_type' => 'Blood Type',
                'address' => 'Address',
                'personal_email' => 'Personal E-Mail',
                'phone_number' => 'Phone Number',
                'tax_identification_no' => 'Tax Identification No.',
                'sss_no' => 'SSS No.',
                'philhealth_no' => 'PhilHealth No.',
                'hdmf_no' => 'HDMF No.',
                'bank_name' => 'Bank Name',
                'bank_account_number' => 'Bank Account Number',
                'bank_swift_code' => 'Bank SWIFT Code',
                'emergency_contact_person_name' => 'Emergency Contact Person Name',
                'emergency_contact_number' => 'Emergency Contact Number',
                'emergency_contact_address' => 'Emergency Contact Address',
            ],
            // Their documents, sent when a person is created or the action changed that slot.
            'attachments' => [
                'Photo' => ['table_name' => 'employees', 'field_name' => 'photo', 'changed_key' => 'doc_photo'],
                'Tax Identification' => ['table_name' => 'employees', 'field_name' => 'tax_identification', 'changed_key' => 'doc_tax_identification'],
                'SS ID' => ['table_name' => 'employees', 'field_name' => 'ss_id', 'changed_key' => 'doc_ss_id'],
                'PhilHealth ID' => ['table_name' => 'employees', 'field_name' => 'philhealth_id', 'changed_key' => 'doc_philhealth_id'],
                'HDMF ID' => ['table_name' => 'employees', 'field_name' => 'hdmf_id', 'changed_key' => 'doc_hdmf_id'],
                'National ID' => ['table_name' => 'employees', 'field_name' => 'national_id', 'changed_key' => 'doc_national_id'],
                'Bank Certificate' => ['table_name' => 'employees', 'field_name' => 'bank_certificate', 'changed_key' => 'doc_bank_certificate'],
            ],
        ],
        // The daily-report review drives Approval (2026-10-14, user): a report waiting for review is
        // Pending, and the reviewer's decision sends Approved or Rejected on every line it decided.
        'reports.flagged' => [
            'sql_table' => 'user_reports',
            'airtable_table' => 'User Reports',
            'rows' => ['lines' => 'userReportIds', 'removed' => 'removedAirtableIds', 'deleted' => 'lines'],
            'changed_only' => ['fields', 'changes'],
            'fields' => [
                'approval' => 'Approval',
            ],
        ],
        // Flagging a member is a warning in Airtable too (2026-10-14, user); unflagging marks it Inactive.
        // The admin grid ledgers under this same bare name, so its in-place edits use this map too.
        'warnings' => [
            'sql_table' => 'warnings',
            'airtable_table' => 'Warnings',
            'changed_only' => ['fields', 'changes'],
            'create_full' => true,
            // Taking a flag off deletes the warning (2026-10-22, user), not leaves it Inactive.
            'deleted_id' => 'airtableRecordId',
            'fields' => self::WARNING_FIELDS,
            'links' => [
                'Employee' => ['junction' => 'employees_warnings', 'local' => 'warning_id', 'foreign' => 'employee_id', 'table' => 'employees', 'column' => 'employeeIds'],
            ],
        ],
        // Manage / References (2026-10-14, user): a client or an activity code the portal adds is
        // created whole, an edit sends what it changed, and a removal deletes Airtable's record.
        // Clients are writable in Client Information (2026-10-22, user); Users & Projects holds a
        // read-only synced copy, whose ids stay in airtable_record_id for project Client links.
        'references.clients' => [
            'sql_table' => 'clients',
            'airtable_table' => 'Clients',
            'base' => 'clients',
            'record_id_column' => 'client_info_airtable_record_id',
            'changed_only' => ['fields', 'changes'],
            'create_full' => true,
            'deleted_id' => 'airtableRecordId',
            'fields' => self::CLIENT_FIELDS,
        ],
        'references.activity_codes' => [
            'sql_table' => 'activity_codes',
            'airtable_table' => 'Activity Code/s',
            'changed_only' => ['fields', 'changes'],
            'create_full' => true,
            'deleted_id' => 'airtableRecordId',
            'fields' => self::ACTIVITY_CODE_FIELDS,
        ],
        // Earn Codes are editable in the Calendar base (2026-10-22, user); Users & Projects holds a
        // read-only synced copy, whose ids stay in airtable_record_id for report/request links.
        'references.earn_codes' => [
            'sql_table' => 'earn_codes',
            'airtable_table' => 'Earn Codes',
            'base' => 'calendar',
            'record_id_column' => 'calendar_airtable_record_id',
            'changed_only' => ['fields', 'changes'],
            'create_full' => true,
            'deleted_id' => 'airtableRecordId',
            'fields' => self::EARN_CODE_FIELDS,
        ],
        // The scope tree, one Airtable table per tier (2026-10-14, user); the portal writes only the
        // code, the name and (above tasks) the department.
        'references.scopes.t1' => [
            'sql_table' => 'project_scope_t1_processes',
            'airtable_table' => 'Project Scope Source - T1 Processes',
            'changed_only' => ['fields', 'changes'],
            'create_full' => true,
            'deleted_id' => 'airtableRecordId',
            'fields' => ['wbs_code' => 'WBS Code', 'name' => 'Name', 'department' => 'Department'],
        ],
        'references.scopes.t2' => [
            'sql_table' => 'project_scope_t2_procedures',
            'airtable_table' => 'Project Scope Source - T2 Procedures',
            'changed_only' => ['fields', 'changes'],
            'create_full' => true,
            'deleted_id' => 'airtableRecordId',
            'fields' => ['wbs_code' => 'WBS Code', 'name' => 'Name', 'department' => 'Department'],
        ],
        'references.scopes.t3' => [
            'sql_table' => 'project_scope_t3_activities',
            'airtable_table' => 'Project Scope Source - T3 Activities',
            'changed_only' => ['fields', 'changes'],
            'create_full' => true,
            'deleted_id' => 'airtableRecordId',
            'fields' => ['wbs_code' => 'WBS Code', 'name' => 'Name', 'department' => 'Department'],
        ],
        'references.scopes.t4' => [
            'sql_table' => 'project_scope_t4_tasks',
            'airtable_table' => 'Project Scope Source - T4 Tasks',
            'changed_only' => ['fields', 'changes'],
            'create_full' => true,
            'deleted_id' => 'airtableRecordId',
            'fields' => ['wbs_code' => 'WBS Code', 'task_name' => 'Task Name'],
        ],
        // A project type's allowed activities. Airtable keeps one row per Job Type and has no
        // Department, so a department's own list goes as "Management (MIS)" (2026-10-14, user).
        'references.allowed_activities' => [
            'sql_table' => 'job_type_allowed_activities',
            'airtable_table' => 'Job Type Allowed Activities',
            'changed_only' => ['fields', 'changes'],
            'create_full' => true,
            'deleted_id' => 'airtableRecordId',
            'bracketed' => ['job_type' => 'department'],
            'fields' => self::ALLOWED_FIELDS,
        ],
        // A candidate's registration (2026-10-14, user): the portal owns the row, so each action sends it
        // whole, and promoting writes its ID No. and approval. Its documents reach Employees on promotion.
        'onboarding.employee_data' => [
            'sql_table' => 'new_employee_data',
            'airtable_table' => 'New Employee Data',
            'deleted_id' => 'airtableRecordId',
            'lenient' => self::NEW_EMPLOYEE_LENIENT,
            'fields' => self::NEW_EMPLOYEE_FIELDS,
        ],
        // A project's closing writes one "Project Closed" row (2026-10-22, user). Only ever created.
        'projects.achievement' => [
            'sql_table' => 'achievements_milestones',
            'airtable_table' => 'Achievements and Milestones',
            'fields' => self::ACHIEVEMENT_FIELDS,
        ],
        // A member-created event goes to the Calendar base (2026-10-22, user), with the nine fields
        // whose values the portal already has.
        'calendar.events' => [
            'sql_table' => 'calendar_events',
            'airtable_table' => 'Events',
            'base' => 'calendar',
            'fields' => [
                'title' => 'Name',
                'details' => 'Details',
                'starts_at_full' => 'Start Date',
                'ends_at_full' => 'End Date',
                'category' => 'Type',
                'author' => 'Author',
                'audience' => 'Attendees',
                'employee_names' => 'Employee Names',
                'departments' => 'Dept',
            ],
            'aliases' => [
                'Type' => ['company_event' => 'Company Event', 'project' => 'Project'],
                'Attendees' => ['everyone' => 'Everyone', 'department' => 'Department', 'members' => 'Specific Members'],
            ],
            // The base offers Engineering, MIS, Purchasing, Executives and HR; another department is
            // left out with a note rather than blocking the event.
            'lenient' => ['Dept'],
        ],
        // Finance writes the transaction tracker itself (2026-10-22, user), so its four tables mirror
        // the rows the portal adds or edits. Money fields are sent as their SQL values; balances and
        // rollups stay Airtable's. Links name the ends the portal joined, and only those tables'
        // records are written -- never the reverse links Airtable fills in.
        'tracker.transactions' => [
            'sql_table' => 'transactions',
            'airtable_table' => 'Transactions',
            'base' => 'tracker',
            'connection' => 'tracker',
            'product' => 'transaction-tracker',
            // The tracker's SQL holds every link, imported ones included, so an empty one is real.
            'full_links' => true,
            'fields' => [
                'transaction_name' => 'Transaction Name',
                'transaction_date' => 'Date',
                'amount' => 'Amount',
                'type' => 'Type',
                'payor_payee' => 'Payor/Payee',
                'description' => 'Description',
                'approval_date' => 'Approval Date',
                'created_by' => 'Created By',
            ],
            'links' => [
                'Account' => ['junction' => 'transactions_accounts', 'local' => 'transaction_id', 'foreign' => 'account_id', 'table' => 'accounts'],
                'Envelope' => ['junction' => 'transactions_envelopes', 'local' => 'transaction_id', 'foreign' => 'envelope_id', 'table' => 'envelopes_budget'],
                'Budget Code' => ['junction' => 'transactions_budget_codes', 'local' => 'transaction_id', 'foreign' => 'budget_code_id', 'table' => 'budget_codes'],
                'Transfer Source Account' => ['junction' => 'transactions_transfer_source_accounts', 'local' => 'transaction_id', 'foreign' => 'account_id', 'table' => 'accounts'],
                'Transfer Destination Account' => ['junction' => 'transactions_transfer_destination_accounts', 'local' => 'transaction_id', 'foreign' => 'account_id', 'table' => 'accounts'],
            ],
            'attachments' => [
                'Receipt' => ['table_name' => 'transactions', 'field_name' => 'receipt', 'payload_key' => 'receipt'],
            ],
        ],
        'tracker.accounts' => [
            'sql_table' => 'accounts',
            'airtable_table' => 'Accounts',
            'base' => 'tracker',
            'connection' => 'tracker',
            'product' => 'transaction-tracker',
            'fields' => [
                'account_name' => 'Account Name',
                'account_type' => 'Account Type',
                'email' => 'Email',
                'institution' => 'Institution',
                'active' => 'Active',
            ],
            // The portal takes a free-text type; Airtable's list is a select. Leave an unlisted one out.
            'lenient' => ['Account Type'],
            'attachments' => [
                'QRCode' => ['table_name' => 'accounts', 'field_name' => 'qrcode'],
            ],
        ],
        'tracker.envelopes' => [
            'sql_table' => 'envelopes_budget',
            'airtable_table' => 'Envelopes - Budget',
            'base' => 'tracker',
            'connection' => 'tracker',
            'product' => 'transaction-tracker',
            'full_links' => true,
            'fields' => [
                'envelope_name' => 'Envelope Name',
                'budget_amount' => 'Budget Amount',
                'group_name' => 'Group',
                'time_period' => 'Time Period',
                'active' => 'Active',
                'budget_notes' => 'Budget Notes',
            ],
            'links' => [
                'Account' => ['junction' => 'envelopes_accounts', 'local' => 'envelope_id', 'foreign' => 'account_id', 'table' => 'accounts'],
                'Budget Code' => ['junction' => 'envelopes_budget_codes', 'local' => 'envelope_id', 'foreign' => 'budget_code_id', 'table' => 'budget_codes'],
            ],
        ],
        'tracker.budget_codes' => [
            'sql_table' => 'budget_codes',
            'airtable_table' => 'Budget Code',
            'base' => 'tracker',
            'connection' => 'tracker',
            'product' => 'transaction-tracker',
            'fields' => [
                'name' => 'Name',
                'description' => 'Desc',
                'transfer_date_received' => 'Transfer Date (Received)',
                'expected_amount_quotation' => 'Expected Amount (Quotation)',
            ],
            'attachments' => [
                'Attachments' => ['table_name' => 'budget_codes', 'field_name' => 'attachments'],
            ],
        ],
        // Every Trail history row the portal writes (2026-10-14, user), linked to its project. Only ever created.
        'projects.trail' => [
            'sql_table' => 'project_action_history',
            'airtable_table' => 'Project Action History',
            'fields' => self::TRAIL_FIELDS,
            'links' => self::TRAIL_PROJECT,
        ],
        // The admin grid's bare table names for every other mirrored table it can edit (2026-10-14, user).
        'clients' => [...self::GRID, 'sql_table' => 'clients', 'airtable_table' => 'Clients', 'base' => 'clients', 'record_id_column' => 'client_info_airtable_record_id', 'deleted_id' => 'airtableRecordId', 'fields' => self::CLIENT_FIELDS],
        'activity_codes' => [...self::GRID, 'sql_table' => 'activity_codes', 'airtable_table' => 'Activity Code/s', 'fields' => self::ACTIVITY_CODE_FIELDS],
        'job_type_allowed_activities' => [...self::GRID, 'sql_table' => 'job_type_allowed_activities', 'airtable_table' => 'Job Type Allowed Activities', 'bracketed' => ['job_type' => 'department'], 'fields' => self::ALLOWED_FIELDS],
        'new_employee_data' => [...self::GRID, 'sql_table' => 'new_employee_data', 'airtable_table' => 'New Employee Data', 'lenient' => self::NEW_EMPLOYEE_LENIENT, 'fields' => self::NEW_EMPLOYEE_FIELDS],
        'project_action_history' => [...self::GRID, 'sql_table' => 'project_action_history', 'airtable_table' => 'Project Action History', 'fields' => self::TRAIL_FIELDS],
        'project_scope_t1_processes' => [...self::GRID, 'sql_table' => 'project_scope_t1_processes', 'airtable_table' => 'Project Scope Source - T1 Processes', 'fields' => self::T1_FIELDS],
        'project_scope_t2_procedures' => [...self::GRID, 'sql_table' => 'project_scope_t2_procedures', 'airtable_table' => 'Project Scope Source - T2 Procedures', 'fields' => self::T2_FIELDS],
        'project_scope_t3_activities' => [...self::GRID, 'sql_table' => 'project_scope_t3_activities', 'airtable_table' => 'Project Scope Source - T3 Activities', 'fields' => self::T3_FIELDS],
        'project_scope_t4_tasks' => [...self::GRID, 'sql_table' => 'project_scope_t4_tasks', 'airtable_table' => 'Project Scope Source - T4 Tasks', 'fields' => self::T4_FIELDS],
        'earn_codes' => [...self::GRID, 'sql_table' => 'earn_codes', 'airtable_table' => 'Earn Codes', 'base' => 'calendar', 'record_id_column' => 'calendar_airtable_record_id', 'deleted_id' => 'airtableRecordId', 'fields' => self::EARN_CODE_FIELDS],
        'achievements_milestones' => [...self::GRID, 'sql_table' => 'achievements_milestones', 'airtable_table' => 'Achievements and Milestones', 'fields' => self::ACHIEVEMENT_FIELDS],
        'bim_form' => [...self::GRID, 'sql_table' => 'bim_form', 'airtable_table' => 'BIM Form', 'fields' => self::BIM_FORM_FIELDS],
    ];

    /**
     * Resources that never reach Airtable, by design rather than for lack of a mapping. Their actions
     * settle as "nothing to send" so they stop reappearing as skipped on every run.
     *
     * @var array<string, string>
     */
    private const PORTAL_ONLY = [
        // Issues & Requests live in the portal and Jira (2026-10-21): their Recycle Bin deletes and restores stop here.
        'bug-reports' => 'Issues & Requests live in the portal and Jira; Airtable has no table for them.',
    ];

    public static function portalOnly(?string $resource): ?string
    {
        return $resource === null ? null : (self::PORTAL_ONLY[$resource] ?? null);
    }

    /**
     * The Airtable base a resource lives in. `main` is Users & Projects; the others are configured
     * in `services.airtable.bases`.
     *
     * @param  array<string, mixed>|null  $map
     */
    public static function base(?array $map): string
    {
        return is_string($map['base'] ?? null) ? $map['base'] : 'main';
    }

    /**
     * The product whose ledger actions this resource accepts.
     *
     * @param  array<string, mixed>|null  $map
     */
    public static function product(?array $map): string
    {
        return is_string($map['product'] ?? null) ? $map['product'] : 'portal';
    }

    /**
     * The SQL connection the mapped table lives on.
     *
     * @param  array<string, mixed>|null  $map
     */
    public static function connection(?array $map): string
    {
        return is_string($map['connection'] ?? null) ? $map['connection'] : 'portal';
    }

    /**
     * The column holding this resource's Airtable record id. A row mirrored into a second base uses
     * its own column for that base.
     *
     * @param  array<string, mixed>|null  $map
     */
    public static function recordIdColumn(?array $map): string
    {
        return is_string($map['record_id_column'] ?? null) ? $map['record_id_column'] : 'airtable_record_id';
    }

    /**
     * @return array{sql_table: string, airtable_table: string, base?: string, product?: string, connection?: string, record_id_column?: string, fields: array<string, string>, discard_when?: array{column: string, value: string}, sample?: array{column: string, value: string}, links?: array<string, array{junction?: string, local?: string, foreign?: string, table: string, where?: array<string, string>, matches?: string, from?: string, from_actor?: bool, query?: string, column?: string, id_column?: string}>, attachments?: array<string, array{table_name: string, field_name: string, payload_key?: string}>, aliases?: array<string, array<string, string>>, rows?: array{lines: string, removed: string, deleted: string}, changed_only?: list<string>, deleted_id?: string}|null
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
