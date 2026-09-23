<?php

/**
 * Workspace: the Airtable-shaped data browser served at /.
 *
 * Everything here is the small curated layer that introspection cannot produce --
 * which table a chip labels itself with, what a table is called in the rail, and
 * which tables are plumbing. Field types, links and select options are inferred
 * from the live connection; see App\Support\Workspace\WorkspaceFieldType.
 */

return [

    'page_size' => 50,

    /*
     * Hard ceiling on rows per request. The grid pages; it never streams a whole table.
     */
    'max_page_size' => 200,

    /*
     * Chips rendered inside one linked-record cell before it collapses to "+N".
     */
    'chips_per_cell' => 3,

    'cache_ttl' => 300,

    /*
     * Databases an administrator may correct a value in from the grid. core keeps the
     * ledger those corrections are written to, so it is never a target of one.
     */
    'writable_products' => ['portal', 'project-estimator'],

    /*
     * Tables that are infrastructure rather than data. Junction tables are detected
     * by shape and hidden automatically, so they never need listing here.
     */
    'hidden_tables' => [
        'core' => [
            'cache', 'cache_locks', 'failed_jobs', 'job_batches', 'jobs',
            'migrations', 'password_reset_tokens', 'sessions', 'users',
        ],
    ],

    /*
     * The frozen first column and the label a chip shows when another table links here.
     * Inference picks a plausible column; these are the ones where it would pick wrong.
     * A list means compose the label from several columns.
     */
    'title_fields' => [
        'core' => [
            'actions' => 'recycle_key',
            'recycle' => 'recycle_key',
            'settings' => 'setting_key',
        ],
        'portal' => [
            'employees' => 'id_no',
            'projects' => 'project_name',
            'clients' => 'name',
            'user_reports' => ['report_date'],
            'requests' => ['request_date', 'name'],
            'reimbursements' => 'item',
            'warnings' => 'warning_date',
            'project_action_history' => 'action_name',
            'project_scopes' => 'id_no',
            'project_scope_t4_tasks' => 'task_name',
            'achievements_milestones' => 'project_name',
            'attachments' => ['table_name', 'field_name'],
            'new_employee_data' => ['first_name', 'last_name'],
            'logs' => 'action',
            'notifications' => 'title',
            'earn_codes' => 'description',
        ],
        'project-estimator' => [
            'employees' => 'id_no',
            'projects' => 'project_name',
            'user_reports' => ['report_date'],
            'holidays' => 'name',
            'accounts' => 'name',
            'earn_codes' => 'description',
            'earn_codes_v2' => 'description',
            'attachments' => ['table_name', 'field_name'],
        ],
    ],

    /*
     * Rail labels where the table name reads badly. Everything else is title-cased.
     */
    'table_labels' => [
        'project-estimator' => [
            'earn_codes_v2' => 'Earn Codes (Holidays)',
        ],
        'portal' => [
            'project_scope_t1_processes' => 'WBS: Processes',
            'project_scope_t2_procedures' => 'WBS: Procedures',
            'project_scope_t3_activities' => 'WBS: Activities',
            'project_scope_t4_tasks' => 'WBS: Tasks',
            'bim_form' => 'BIM Form',
        ],
    ],

    /*
     * A junction whose extra primary-key column is a qualifier becomes one link column
     * per value -- this is how Airtable showed PM ID, Project Members and Support
     * Members as three fields over a single employees_projects table.
     */
    'link_labels' => [
        'pm' => 'PM',
        'member' => 'Project Members',
        'support' => 'Support Members',
        'base' => 'Earn Code',
        'ot' => 'Earn Code (OT)',
        'nd' => 'Earn Code (ND)',
        'nd_ot' => 'Earn Code (ND+OT)',
    ],

];
