-- ============================================================================
-- Users & Projects (TEST)  —  Airtable base converted to a PostgreSQL schema
-- Source base ID: app8DvnFZErPZT5Az
-- ============================================================================
--
-- DESIGN NOTES (read this before using the schema)
--
-- 1. Every table gets a surrogate `id SERIAL PRIMARY KEY` plus an
--    `airtable_record_id VARCHAR(20) UNIQUE` column, so every row can be
--    traced back to the exact Airtable record it came from.
--
-- 2. Airtable "multipleRecordLinks" fields become proper many-to-many
--    junction tables (Airtable links are inherently many-to-many even when
--    a field is only ever used to point at one record).
--
-- 3. Airtable often works around its own link-field limits by creating
--    near-duplicate fields ("Project/s 2", "Project/s 3", "Employees copy",
--    etc). Those have been consolidated into ONE junction table per real
--    relationship rather than one table per duplicate field.
--
-- 4. Computed fields (formula, rollup, multipleLookupValues, aiText,
--    createdTime, lastModifiedTime) are NOT stored as columns — they are
--    derived data in Airtable. They're listed in comments so you know they
--    existed, and can be rebuilt later as SQL VIEWs or GENERATED columns.
--
-- 5. All `multipleAttachments` fields (Photo, QR Code, ID scans, receipts,
--    etc.) are consolidated into one shared `attachments` table instead of
--    a column-per-file-field. Filter by `table_name` + `field_name`.
--
-- 6. `singleSelect` / `multipleSelects` fields are stored as VARCHAR / a
--    junction table rather than native ENUM types, since Airtable option
--    lists change often — add CHECK constraints later if you want to lock
--    them down.
--
-- 7. A few link fields point at data that isn't a table in this base
--    (e.g. "Tasks & Sprints" is referenced from several tables but no such
--    table exists here — it may live in another base, or the field is
--    unused/orphaned). These are flagged with -- ORPHAN LINK comments and
--    have no FK/junction table generated.
--
-- ============================================================================

DROP DATABASE IF EXISTS test_portal_database;
CREATE DATABASE test_portal_database;
USE test_portal_database;

-- ============================================================================
-- SHARED / SUPPORT TABLES
-- ============================================================================

CREATE TABLE attachments (
    id              SERIAL PRIMARY KEY,
    table_name      VARCHAR(100) NOT NULL,   -- e.g. 'employees'
    record_id       INTEGER      NOT NULL,   -- id of the row in that table
    field_name      VARCHAR(100) NOT NULL,   -- e.g. 'photo', 'qr_code'
    file_url        VARCHAR(1000),
    file_name       VARCHAR(500),
    file_size_bytes BIGINT,
    mime_type       VARCHAR(150)
);
CREATE INDEX idx_attachments_owner ON attachments(table_name, record_id, field_name);


-- ============================================================================
-- 1. EMPLOYEES  (Airtable table: Employees / tblxk72TlMeFDOl8J)
-- ============================================================================

CREATE TABLE employees (
    id                              SERIAL PRIMARY KEY,
    airtable_record_id              VARCHAR(20) UNIQUE,
    id_no                           VARCHAR(100),
    first_name                      VARCHAR(255),
    last_name                       VARCHAR(255),
    middle_name                     VARCHAR(255),
    job_title                       VARCHAR(255),   -- singleSelect
    nickname                        TEXT,
    email                           VARCHAR(255),
    start_date                      DATE,
    end_date                        DATE,
    status                          VARCHAR(100),   -- singleSelect
    employment_status               VARCHAR(100),   -- singleSelect
    date_of_birth                   DATE,
    blood_type                      VARCHAR(50),    -- singleSelect
    address                         VARCHAR(500),
    personal_email                  VARCHAR(255),
    tax_identification_no           VARCHAR(100),
    philhealth_no                   VARCHAR(100),
    sss_no                          VARCHAR(100),
    hdmf_no                         VARCHAR(100),
    phone_number                    VARCHAR(50),
    sl_credits                      NUMERIC(10,2),
    remaining_sl                    NUMERIC(10,2),
    vl_credits                      NUMERIC(10,2),
    remaining_vl                    NUMERIC(10,2),
    profile_folder_url              VARCHAR(500),
    role                            VARCHAR(100),   -- singleSelect
    department                      VARCHAR(100),   -- singleSelect
    location                        VARCHAR(100),   -- singleSelect
    emergency_contact_person_name   VARCHAR(255),
    emergency_contact_number        VARCHAR(50),
    emergency_contact_address       TEXT,
    bank_name                       VARCHAR(150),   -- singleSelect
    bank_account_number             VARCHAR(100),
    bank_swift_code                 VARCHAR(50),
    role_level                      VARCHAR(100)    -- singleSelect
    -- Attachments (see `attachments` table, field_name = ...):
    --   photo, qr_code, tax_identification, philhealth_id, ss_id, hdmf_id,
    --   bank_certificate, employment_agreement
    -- Computed / not stored: name (formula), 3rd_month_evaluation (formula),
    --   5th_month_evaluation (formula)
    -- Relationships: see junction tables below
    -- ORPHAN LINK: "Tasks & Sprints" — no matching table in this base
);


-- ============================================================================
-- 2. NEW EMPLOYEE DATA  (Airtable table: New Employee Data / tblTl8CXCc8Dqhffz)
-- Appears to be an onboarding/staging mirror of Employees.
-- ============================================================================

CREATE TABLE new_employee_data (
    id                              SERIAL PRIMARY KEY,
    airtable_record_id              VARCHAR(20) UNIQUE,
    id_no                           VARCHAR(100),
    first_name                      VARCHAR(255),
    last_name                       VARCHAR(255),
    middle_name                     VARCHAR(255),
    job_title                       VARCHAR(255),
    nickname                        TEXT,
    email                           VARCHAR(255),
    start_date                      DATE,
    end_date                        DATE,
    status                          VARCHAR(100),
    employment_status               VARCHAR(100),
    date_of_birth                   DATE,
    address                         VARCHAR(500),
    personal_email                  VARCHAR(255),
    tax_identification_no           VARCHAR(100),
    philhealth_no                   VARCHAR(100),
    sss_no                          VARCHAR(100),
    hdmf_no                         VARCHAR(100),
    phone_number                    VARCHAR(50),
    sl_credits                      NUMERIC(10,2),
    remaining_sl                    NUMERIC(10,2),
    vl_credits                      NUMERIC(10,2),
    remaining_vl                    NUMERIC(10,2),
    profile_folder_url              VARCHAR(500),
    role                            VARCHAR(100),
    department                      VARCHAR(100),
    emergency_contact_person_name   VARCHAR(255),
    emergency_contact_number        VARCHAR(50),
    emergency_contact_address       TEXT,
    bank_name                       VARCHAR(150),
    bank_account_number             VARCHAR(100),
    bank_swift_code                 VARCHAR(50),
    data_approval                   VARCHAR(100)    -- singleSelect
    -- Attachments: photo, qr_code, tax_identification, philhealth_id,
    --   ss_id, hdmf_id, bank_certificate
    -- Computed / not stored: name, 3rd_month_evaluation, 5th_month_evaluation
    -- ORPHAN LINK: "Tasks & Sprints"
);


-- ============================================================================
-- 3. CLIENTS  (Airtable table: Clients / tblaUeddS3nOJrXHX)
-- ============================================================================

CREATE TABLE clients (
    id                  SERIAL PRIMARY KEY,
    airtable_record_id  VARCHAR(20) UNIQUE,
    name                VARCHAR(255),
    client_id           VARCHAR(100),
    notes               TEXT,
    assignee_email      VARCHAR(255),   -- singleCollaborator, store as email/identifier
    status              VARCHAR(100)    -- singleSelect
    -- Attachments: attachments
    -- ORPHAN LINK: "Tasks & Sprints"
);


-- ============================================================================
-- 4. PROJECTS  (Airtable table: Project/s / tblM1YUKzuiMqISmC)
-- ============================================================================

CREATE TABLE projects (
    id                                  SERIAL PRIMARY KEY,
    airtable_record_id                  VARCHAR(20) UNIQUE,
    project_number                      VARCHAR(100),
    project_name                        TEXT,
    department                          VARCHAR(100),   -- singleSelect
    is_renamed_with_acc_number          BOOLEAN,
    is_ledger_moved_to_for_submission   BOOLEAN,
    is_ledger_details_updated           BOOLEAN,
    status                              VARCHAR(100),   -- singleSelect
    progress_pct                        NUMERIC(5,2),
    type_of_job                         VARCHAR(100),   -- singleSelect
    area_sqft                           NUMERIC(12,2),
    lod                                 VARCHAR(50),    -- singleSelect
    ar_lod                              VARCHAR(50),
    st_lod                              VARCHAR(50),
    md_lod                              VARCHAR(50),
    el_lod                              VARCHAR(50),
    pl_lod                              VARCHAR(50),
    fp_lod                              VARCHAR(50),
    estimated_num_elements              TEXT,
    num_elements                        NUMERIC(12,2),
    file_size_mb                        NUMERIC(12,2),
    scan_file_size_gb                   TEXT,
    date_done                           TIMESTAMP,
    date_closed                         TIMESTAMP,
    due_date                            DATE,
    forma_link                          TEXT,
    msteams_project_link                VARCHAR(1000),
    ms_planner_link                     VARCHAR(1000),
    panoramic_link                      VARCHAR(1000),
    ms_loop_link                        VARCHAR(1000),
    google_drive_folder_path            VARCHAR(1000),
    project_lead_email                  VARCHAR(255),
    total_elements                      NUMERIC(12,2),
    project_creation_status             VARCHAR(100),   -- singleSelect
    project_creation_approver           VARCHAR(255),
    project_approver_email              VARCHAR(255),
    project_creation_approver_remarks   TEXT,
    close_project_requester             VARCHAR(255),
    done_project_requester              VARCHAR(255),
    date_modified                       TIMESTAMP       -- lastModifiedTime
    -- Attachments: (none directly listed besides links above)
    -- Computed / not stored: ave_lod, productivity_kpi, total_noe, ar_noe,
    --   st_noe, el_noe, fp_noe, md_noe, mp_noe, pl_noe, mc_noe, qc_noe,
    --   total_noh, tot_hrs, tot_hrs_internal, total_noh_ot, ar_hrs, st_hrs,
    --   el_hrs (+copy), fp_hrs, mp_hrs, pl_hrs, mc_hrs, md_hrs, qc_hrs,
    --   ds_hrs, date_started, pm_name, project_member_names,
    --   support_member_name, creator_name, last_modified_by_name,
    --   last_report_date, project_creator_email (all rollup/lookup/formula)
    -- Relationships: see junction tables below
    -- ORPHAN LINK: "Tasks & Sprints"
);


-- ============================================================================
-- 5. PROJECT SCOPES  (Airtable table: Project Scopes / tblbakHaKGLu4FecT)
-- ============================================================================

CREATE TABLE project_scopes (
    id                  SERIAL PRIMARY KEY,
    airtable_record_id  VARCHAR(20) UNIQUE,
    id_no               INTEGER,        -- autoNumber
    progress_pct        NUMERIC(5,2)
    -- Computed / not stored: project_name, scope_name_t3, scope_name_t4,
    --   assigned_to_employee_name (lookups)
    -- Relationships: see junction tables below
);


-- ============================================================================
-- 6. PROJECT SCOPE SOURCE — T1 PROCESSES  (tbly6jy78GWm9tilj)
-- ============================================================================

CREATE TABLE project_scope_t1_processes (
    id                  SERIAL PRIMARY KEY,
    airtable_record_id  VARCHAR(20) UNIQUE,
    wbs_code            TEXT,
    process_index       VARCHAR(100),
    level               VARCHAR(100),   -- singleSelect
    type                VARCHAR(100),   -- singleSelect
    name                VARCHAR(255),
    process_wbs         VARCHAR(255),
    sop_reference        VARCHAR(255),
    time_trackable      BOOLEAN,
    department          VARCHAR(100),
    assignee_email      VARCHAR(255),   -- singleCollaborator
    status              VARCHAR(100)    -- singleSelect
    -- Attachments: attachments, attachment_summary
    -- Computed / not stored: sop_name_from_process_wbs, name_from_table_2
);


-- ============================================================================
-- 7. PROJECT SCOPE SOURCE — T2 PROCEDURES  (tblAHKe9S2qNdQFHe)
-- ============================================================================

CREATE TABLE project_scope_t2_procedures (
    id                      SERIAL PRIMARY KEY,
    airtable_record_id      VARCHAR(20) UNIQUE,
    wbs_code                TEXT,
    sequence_index          INTEGER,
    level                   VARCHAR(100),
    type                    VARCHAR(100),
    name                    VARCHAR(255),
    process                 VARCHAR(255),   -- text mirror of link, see junction too
    activity_wbs            VARCHAR(255),
    sop_reference           VARCHAR(255),
    time_trackable          BOOLEAN,
    department              VARCHAR(100),
    assignee_email          VARCHAR(255),
    status                  VARCHAR(100)
    -- Attachments: attachments, attachment_summary
    -- Computed / not stored: name_from_activity_wbs
);


-- ============================================================================
-- 8. PROJECT SCOPE SOURCE — T3 ACTIVITIES  (tbldhjjQAV0pqKzIz)
-- ============================================================================

CREATE TABLE project_scope_t3_activities (
    id                  SERIAL PRIMARY KEY,
    airtable_record_id  VARCHAR(20) UNIQUE,
    wbs_code            TEXT,
    activity_index      VARCHAR(100),
    level               VARCHAR(100),   -- singleSelect
    type                VARCHAR(100),   -- singleSelect
    name                TEXT,
    procedure_wbs       VARCHAR(255),
    `procedure`         VARCHAR(255),
    process_wbs         VARCHAR(255),
    process              VARCHAR(255),
    sop_reference       VARCHAR(255),
    time_trackable      BOOLEAN,
    department          VARCHAR(100),
    assignee_email      VARCHAR(255),
    status              VARCHAR(100)
    -- Attachments: attachments
    -- Relationships: see junction tables below (T4 tasks, project scopes)
);


-- ============================================================================
-- 9. PROJECT SCOPE SOURCE — T4 TASKS  (tblipfyqQgXQ0dVjG)
-- ============================================================================

CREATE TABLE project_scope_t4_tasks (
    id                              SERIAL PRIMARY KEY,
    airtable_record_id              VARCHAR(20) UNIQUE,
    wbs_code                        TEXT,
    task_index                      VARCHAR(100),
    task_name                       TEXT,
    task_notes                      TEXT,
    task                            TEXT,
    activity_name                   TEXT,
    activity                        TEXT,
    procedure_name                  VARCHAR(255),
    `procedure`                     TEXT,
    description                     TEXT,
    assigned_to_email               VARCHAR(255),   -- singleCollaborator
    start_date                      DATE,
    due_date                        DATE,
    time_spent_hrs                  NUMERIC(10,2),
    status                          VARCHAR(100),   -- singleSelect
    priority                        VARCHAR(50)     -- singleSelect
    -- Computed / not stored: procedure_wbs_from_activity, process_wbs_from_activity,
    --   process_from_activity
    -- Relationships: see junction tables below (activity link, dependencies,
    --   project scopes)
);


-- ============================================================================
-- 10. ACTIVITY CODE/S  (tbl9ANPghOOi7toup)
-- ============================================================================

CREATE TABLE activity_codes (
    id                  SERIAL PRIMARY KEY,
    airtable_record_id  VARCHAR(20) UNIQUE,
    name                VARCHAR(255),
    id_no               VARCHAR(100),
    department          VARCHAR(100)    -- singleSelect
    -- Relationships: see junction tables below
);


-- ============================================================================
-- 11. EARN CODES  (tbl3ZjKB95yckcwcJ)
-- ============================================================================

CREATE TABLE earn_codes (
    id                  SERIAL PRIMARY KEY,
    airtable_record_id  VARCHAR(20) UNIQUE,
    description         TEXT,
    did_not_work        NUMERIC(10,2),
    did_work            NUMERIC(10,2)
    -- Relationships: see junction tables below
);


-- ============================================================================
-- 12. JOB TYPE ALLOWED ACTIVITIES  (tbl53is3fy1C7We0E)
-- ============================================================================

CREATE TABLE job_type_allowed_activities (
    id                          SERIAL PRIMARY KEY,
    airtable_record_id          VARCHAR(20) UNIQUE,
    job_type                    VARCHAR(255),
    activity_code_id_initials   TEXT
);


-- ============================================================================
-- 13. USER REPORTS  (Airtable table: User Reports / tblXUjJ26Rj95RPRl)
--     This is the timesheet / daily activity report table.
-- ============================================================================

CREATE TABLE user_reports (
    id                          SERIAL PRIMARY KEY,
    airtable_record_id          VARCHAR(20) UNIQUE,
    report_date                 DATE,
    hours_rendered              NUMERIC(10,2),
    change_in_elements          NUMERIC(12,2),
    progress_per_activity_pct   NUMERIC(5,2),
    remarks                     VARCHAR(500),
    late_submission             VARCHAR(50),    -- singleSelect
    late_submission_approval    VARCHAR(50),    -- singleSelect
    date_created                TIMESTAMP,      -- createdTime
    approval                    VARCHAR(50),    -- singleSelect
    approver_remarks            TEXT,
    actual_work_date            DATE,
    is_support                  VARCHAR(50),    -- singleSelect
    end_date                    DATE,
    project_test_summary        VARCHAR(500),
    project_test_summary_2      VARCHAR(500),
    duration                    NUMERIC(10,2)
    -- Computed / not stored: name (lookup), role_level_from_employees,
    --   employment_status_from_employees, department_from_employees,
    --   type_of_job_from_project, hours_multiplier, *hrs (formula),
    --   elements_per_8_hours (formula), client (rollup)
    -- Relationships: see junction tables below (employee, project, activity,
    --   earn code, dependencies)
);


-- ============================================================================
-- 14. USER REPORTS — PROJECT SCOPE PROGRESS  (tbldfzazKRu33qcPu)
-- ============================================================================

CREATE TABLE user_reports_scope_progress (
    id                  SERIAL PRIMARY KEY,
    airtable_record_id  VARCHAR(20) UNIQUE,
    report_date         DATE,
    scope_progress_pct  NUMERIC(5,2),
    reported_by         VARCHAR(255)
    -- Computed / not stored: project_name, t3_activity_name, task_name (lookups)
    -- Relationships: see junction tables below
);


-- ============================================================================
-- 15. REQUEST  (tblNqKviuP8XaRPar)
--     (Leave/offset requests)
-- ============================================================================

CREATE TABLE requests (
    id                      SERIAL PRIMARY KEY,
    airtable_record_id      VARCHAR(20) UNIQUE,
    request_date            DATE,
    name                    VARCHAR(255),
    no_of_hours             NUMERIC(10,2),
    original_work_day       DATE,
    offset_work_day         DATE,
    offset_hrs              NUMERIC(10,2),
    reason                  TEXT,
    status                  VARCHAR(100),   -- singleSelect
    approver_remarks        TEXT,
    date_created            TIMESTAMP,      -- createdTime
    linked_report_ids       TEXT,
    type                    VARCHAR(100),
    category                VARCHAR(100)    -- singleSelect
    -- Relationships: see junction tables below (project, activity scope,
    --   earn code)
);


-- ============================================================================
-- 16. REIMBURSEMENTS  (tblLS2KumEan1DTUw)
-- ============================================================================

CREATE TABLE reimbursements (
    id                  SERIAL PRIMARY KEY,
    airtable_record_id  VARCHAR(20) UNIQUE,
    reimb_date          DATE,
    item                VARCHAR(500),
    cost                NUMERIC(12,2),
    qty                 NUMERIC(10,2),
    purpose             TEXT,
    employee_name_input VARCHAR(255),
    team                VARCHAR(100),   -- singleSelect
    status              VARCHAR(100),   -- singleSelect
    approver_remarks    TEXT,
    date_created        TIMESTAMP       -- createdTime
    -- Attachments: receipts, reimbursement_receipt
    -- Computed / not stored: name_from_reimburse_to, total_cost (formula)
    -- Relationships: see junction tables below (reimburse_to -> employees)
);


-- ============================================================================
-- 17. WARNINGS  (tbl0I6oTDN0cuLpA3)
-- ============================================================================

CREATE TABLE warnings (
    id                  SERIAL PRIMARY KEY,
    airtable_record_id  VARCHAR(20) UNIQUE,
    warning_date        DATE,
    description         TEXT,
    status              VARCHAR(100),   -- singleSelect
    violation_type      VARCHAR(100),   -- singleSelect
    date_created        TIMESTAMP       -- createdTime
    -- Computed / not stored: employee_name (lookup)
    -- Relationships: see junction tables below (employee)
);


-- ============================================================================
-- 18. ACHIEVEMENTS AND MILESTONES  (tblfItx4zc99afbDl)
-- ============================================================================

CREATE TABLE achievements_milestones (
    id                  SERIAL PRIMARY KEY,
    airtable_record_id  VARCHAR(20) UNIQUE,
    achievement_date    DATE,
    project_name        VARCHAR(255),
    description         TEXT,
    links               VARCHAR(1000)
    -- Attachments: attachments
);


-- ============================================================================
-- 19. BIM FORM  (tbl86gVHZNyAPPSFe)
-- ============================================================================

CREATE TABLE bim_form (
    id                              SERIAL PRIMARY KEY,
    airtable_record_id              VARCHAR(20) UNIQUE,
    project_number                  VARCHAR(100),
    project_name                    VARCHAR(255),
    project_address                 VARCHAR(500),
    project_size_sqft               NUMERIC(12,2),
    assignee_email                  VARCHAR(255),   -- singleCollaborator
    status                          VARCHAR(100),   -- singleSelect
    attachment_summary              TEXT,           -- aiText
    no_of_floors                    NUMERIC(6,0),
    client_name                     VARCHAR(255),
    poc_name                        VARCHAR(255),
    poc_phone                       VARCHAR(50),
    purpose_of_scan_to_bim          VARCHAR(255),   -- singleSelect
    arch_lod                        VARCHAR(50),
    struct_lod                      VARCHAR(50),
    mech_lod                        VARCHAR(50),
    elect_lod                       VARCHAR(50),
    plumb_lod                       VARCHAR(50),
    fp_lod                          VARCHAR(50),
    client_provides_point_cloud     VARCHAR(50),
    point_cloud_scan_format         VARCHAR(100),
    client_has_existing_cad_rvt     VARCHAR(50),
    special_requests                VARCHAR(255),
    insertion_point_strategy        VARCHAR(255),
    preferred_workset               VARCHAR(255),
    form_assigned_by                VARCHAR(255),
    filled_up_by                    VARCHAR(255)
    -- Attachments: attachments
    -- multipleSelects (junction table if needed): elements_included_in_model
);

CREATE TABLE bim_form_elements_included (
    bim_form_id     INTEGER REFERENCES bim_form(id) ON DELETE CASCADE,
    element_name    VARCHAR(255),
    PRIMARY KEY (bim_form_id, element_name)
);


-- ============================================================================
-- 20. PROJECT ACTION HISTORY  (tblH9hHFlvfZwVqJb)
-- ============================================================================

CREATE TABLE project_action_history (
    id                  SERIAL PRIMARY KEY,
    airtable_record_id  VARCHAR(20) UNIQUE,
    name                INTEGER,        -- autoNumber
    action_name         VARCHAR(255),
    created_by          VARCHAR(255),
    remarks             VARCHAR(500),
    created_at          TIMESTAMP       -- createdTime
    -- Computed / not stored: project_name (lookup)
    -- Relationships: see junction tables below (project)
);


-- ============================================================================
-- JUNCTION TABLES  (many-to-many relationships, consolidated from Airtable's
-- duplicate link fields)
-- ============================================================================

-- Employees <-> Projects  (from Employees."Project/s"..5 and Projects.
--   "Employees copy"..4, "Project Members", "Support Members", "PM ID")
CREATE TABLE employees_projects (
    employee_id INTEGER REFERENCES employees(id) ON DELETE CASCADE,
    project_id  INTEGER REFERENCES projects(id)  ON DELETE CASCADE,
    role_on_project VARCHAR(50), -- 'member' | 'support' | 'pm' (populate on migration)
    PRIMARY KEY (employee_id, project_id, role_on_project)
);

-- Employee hierarchy (manager -> direct reports), from Employees."Reports 3"
CREATE TABLE employee_hierarchy (
    manager_id  INTEGER REFERENCES employees(id) ON DELETE CASCADE,
    report_id   INTEGER REFERENCES employees(id) ON DELETE CASCADE,
    PRIMARY KEY (manager_id, report_id)
);

-- Employees <-> Reimbursements ("Reimburse To")
CREATE TABLE employees_reimbursements (
    employee_id     INTEGER REFERENCES employees(id) ON DELETE CASCADE,
    reimbursement_id INTEGER REFERENCES reimbursements(id) ON DELETE CASCADE,
    PRIMARY KEY (employee_id, reimbursement_id)
);

-- Employees <-> Project Scopes ("Project Scopes" on Employees)
CREATE TABLE employees_project_scopes (
    employee_id       INTEGER REFERENCES employees(id) ON DELETE CASCADE,
    project_scope_id  INTEGER REFERENCES project_scopes(id) ON DELETE CASCADE,
    PRIMARY KEY (employee_id, project_scope_id)
);

-- Employees <-> Warnings
CREATE TABLE employees_warnings (
    employee_id INTEGER REFERENCES employees(id) ON DELETE CASCADE,
    warning_id  INTEGER REFERENCES warnings(id)  ON DELETE CASCADE,
    PRIMARY KEY (employee_id, warning_id)
);

-- Employees <-> User Reports ("Employee No." on User Reports)
CREATE TABLE employees_user_reports (
    employee_id     INTEGER REFERENCES employees(id) ON DELETE CASCADE,
    user_report_id  INTEGER REFERENCES user_reports(id) ON DELETE CASCADE,
    PRIMARY KEY (employee_id, user_report_id)
);

-- Projects <-> Clients
CREATE TABLE projects_clients (
    project_id INTEGER REFERENCES projects(id) ON DELETE CASCADE,
    client_id  INTEGER REFERENCES clients(id)  ON DELETE CASCADE,
    PRIMARY KEY (project_id, client_id)
);

-- Projects <-> Project Scopes
CREATE TABLE projects_project_scopes (
    project_id       INTEGER REFERENCES projects(id) ON DELETE CASCADE,
    project_scope_id INTEGER REFERENCES project_scopes(id) ON DELETE CASCADE,
    PRIMARY KEY (project_id, project_scope_id)
);

-- Projects <-> User Reports
CREATE TABLE projects_user_reports (
    project_id      INTEGER REFERENCES projects(id) ON DELETE CASCADE,
    user_report_id  INTEGER REFERENCES user_reports(id) ON DELETE CASCADE,
    PRIMARY KEY (project_id, user_report_id)
);

-- Projects <-> Requests
CREATE TABLE projects_requests (
    project_id INTEGER REFERENCES projects(id) ON DELETE CASCADE,
    request_id INTEGER REFERENCES requests(id) ON DELETE CASCADE,
    PRIMARY KEY (project_id, request_id)
);

-- Projects <-> User Reports Scope Progress
CREATE TABLE projects_user_reports_scope_progress (
    project_id                  INTEGER REFERENCES projects(id) ON DELETE CASCADE,
    user_reports_scope_progress_id INTEGER REFERENCES user_reports_scope_progress(id) ON DELETE CASCADE,
    PRIMARY KEY (project_id, user_reports_scope_progress_id)
);

-- Projects <-> Project Action History
CREATE TABLE projects_action_history (
    project_id          INTEGER REFERENCES projects(id) ON DELETE CASCADE,
    project_action_id   INTEGER REFERENCES project_action_history(id) ON DELETE CASCADE,
    PRIMARY KEY (project_id, project_action_id)
);

-- Projects <-> Activity Scope (target table ambiguous in source base;
-- most likely Project Scope Source - T3 Activities)
CREATE TABLE projects_activity_scope (
    project_id  INTEGER REFERENCES projects(id) ON DELETE CASCADE,
    activity_id INTEGER REFERENCES project_scope_t3_activities(id) ON DELETE CASCADE,
    PRIMARY KEY (project_id, activity_id)
);

-- Project Scopes <-> Project (already covered by projects_project_scopes)
-- Project Scopes <-> T3 Activities
CREATE TABLE project_scopes_t3_activities (
    project_scope_id INTEGER REFERENCES project_scopes(id) ON DELETE CASCADE,
    t3_activity_id   INTEGER REFERENCES project_scope_t3_activities(id) ON DELETE CASCADE,
    PRIMARY KEY (project_scope_id, t3_activity_id)
);

-- Project Scopes <-> T4 Tasks
CREATE TABLE project_scopes_t4_tasks (
    project_scope_id INTEGER REFERENCES project_scopes(id) ON DELETE CASCADE,
    t4_task_id       INTEGER REFERENCES project_scope_t4_tasks(id) ON DELETE CASCADE,
    PRIMARY KEY (project_scope_id, t4_task_id)
);

-- Project Scopes <-> Employees ("AssignedTo") — duplicate of
-- employees_project_scopes above; kept for the explicit assignment semantics
CREATE TABLE project_scopes_assigned_to (
    project_scope_id INTEGER REFERENCES project_scopes(id) ON DELETE CASCADE,
    employee_id      INTEGER REFERENCES employees(id) ON DELETE CASCADE,
    PRIMARY KEY (project_scope_id, employee_id)
);

-- T3 Activities <-> T4 Tasks
CREATE TABLE t3_activities_t4_tasks (
    t3_activity_id INTEGER REFERENCES project_scope_t3_activities(id) ON DELETE CASCADE,
    t4_task_id     INTEGER REFERENCES project_scope_t4_tasks(id) ON DELETE CASCADE,
    PRIMARY KEY (t3_activity_id, t4_task_id)
);

-- T4 Tasks self-referencing dependencies
CREATE TABLE t4_task_dependencies (
    task_id           INTEGER REFERENCES project_scope_t4_tasks(id) ON DELETE CASCADE,
    depends_on_task_id INTEGER REFERENCES project_scope_t4_tasks(id) ON DELETE CASCADE,
    PRIMARY KEY (task_id, depends_on_task_id)
);

-- T2 Procedures <-> T1 Processes ("Table 2" field on T1, "Process WBS" on T2)
CREATE TABLE t1_processes_t2_procedures (
    t1_process_id     INTEGER REFERENCES project_scope_t1_processes(id) ON DELETE CASCADE,
    t2_procedure_id   INTEGER REFERENCES project_scope_t2_procedures(id) ON DELETE CASCADE,
    PRIMARY KEY (t1_process_id, t2_procedure_id)
);

-- User Reports <-> Projects (already covered by projects_user_reports)
-- User Reports <-> Activity Codes
CREATE TABLE user_reports_activity_codes (
    user_report_id   INTEGER REFERENCES user_reports(id) ON DELETE CASCADE,
    activity_code_id INTEGER REFERENCES activity_codes(id) ON DELETE CASCADE,
    PRIMARY KEY (user_report_id, activity_code_id)
);

-- User Reports <-> Earn Codes
CREATE TABLE user_reports_earn_codes (
    user_report_id INTEGER REFERENCES user_reports(id) ON DELETE CASCADE,
    earn_code_id   INTEGER REFERENCES earn_codes(id) ON DELETE CASCADE,
    PRIMARY KEY (user_report_id, earn_code_id)
);

-- User Reports self-referencing dependencies
CREATE TABLE user_report_dependencies (
    user_report_id      INTEGER REFERENCES user_reports(id) ON DELETE CASCADE,
    depends_on_report_id INTEGER REFERENCES user_reports(id) ON DELETE CASCADE,
    PRIMARY KEY (user_report_id, depends_on_report_id)
);

-- User Reports Scope Progress <-> Project / T3 Activity / T4 Task
CREATE TABLE user_reports_scope_progress_t3_activities (
    user_reports_scope_progress_id INTEGER REFERENCES user_reports_scope_progress(id) ON DELETE CASCADE,
    t3_activity_id                 INTEGER REFERENCES project_scope_t3_activities(id) ON DELETE CASCADE,
    PRIMARY KEY (user_reports_scope_progress_id, t3_activity_id)
);

CREATE TABLE user_reports_scope_progress_t4_tasks (
    user_reports_scope_progress_id INTEGER REFERENCES user_reports_scope_progress(id) ON DELETE CASCADE,
    t4_task_id                     INTEGER REFERENCES project_scope_t4_tasks(id) ON DELETE CASCADE,
    PRIMARY KEY (user_reports_scope_progress_id, t4_task_id)
);

-- Requests <-> Activity Codes / Earn Codes
CREATE TABLE requests_activity_codes (
    request_id       INTEGER REFERENCES requests(id) ON DELETE CASCADE,
    activity_code_id INTEGER REFERENCES activity_codes(id) ON DELETE CASCADE,
    PRIMARY KEY (request_id, activity_code_id)
);

CREATE TABLE requests_earn_codes (
    request_id   INTEGER REFERENCES requests(id) ON DELETE CASCADE,
    earn_code_id INTEGER REFERENCES earn_codes(id) ON DELETE CASCADE,
    PRIMARY KEY (request_id, earn_code_id)
);


-- ============================================================================
-- SUGGESTED INDEXES (uncomment / adapt once you know real query patterns)
-- ============================================================================
-- CREATE INDEX idx_user_reports_date ON user_reports(report_date);
-- CREATE INDEX idx_user_reports_employee ON employees_user_reports(employee_id);
-- CREATE INDEX idx_projects_status ON projects(status);
-- CREATE INDEX idx_employees_status ON employees(status);
