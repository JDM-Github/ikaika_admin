-- ============================================================================
-- Users & Projects — Airtable base converted to a MySQL-compatible schema
-- Works for both the TEST base (app8DvnFZErPZT5Az) and the PRODUCTION base
-- (app8jGTzRDQt4yPIa) — they share identical table/field structure.
-- Tested against MySQL 8.0 / MariaDB 10.x syntax. Requires InnoDB (default
-- in modern MySQL) for foreign key enforcement.
-- ============================================================================
--
-- CHANGES FROM THE POSTGRESQL VERSION (read this if diffing the two files)
--
-- 1. `SERIAL PRIMARY KEY` → `INT AUTO_INCREMENT PRIMARY KEY`. Postgres SERIAL
--    is just an auto-incrementing INTEGER; MySQL's closest native equivalent
--    is INT AUTO_INCREMENT (MySQL also has a literal "SERIAL" type, but it
--    maps to BIGINT UNSIGNED, which is wider than we need here).
--
-- 2. Inline `col INTEGER REFERENCES tbl(col) ON DELETE CASCADE` (valid
--    Postgres shorthand) does NOT create an enforced foreign key in MySQL —
--    MySQL silently accepts the syntax but only creates a real constraint
--    from an explicit table-level `FOREIGN KEY (col) REFERENCES tbl(col)`
--    clause. Every junction table below has been rewritten with explicit
--    FOREIGN KEY clauses so the relationships are actually enforced.
--
-- 3. Every table gets `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4` — InnoDB is
--    required for foreign keys; utf8mb4 avoids truncation/mangling of any
--    non-ASCII text (names, addresses, remarks) that came from Airtable.
--
-- 4. BOOLEAN, NUMERIC(p,s), TEXT, VARCHAR(n), DATE, TIMESTAMP are all valid
--    as-is in MySQL (BOOLEAN is an alias for TINYINT(1), NUMERIC an alias
--    for DECIMAL) — no changes needed there.
--
-- All other design notes (junction-table consolidation, orphan links,
-- computed-field omissions, attachments-as-placeholder) are unchanged from
-- the Postgres version — see users_projects_schema.sql for the full
-- rationale if you need it.
-- ============================================================================

DROP DATABASE IF EXISTS test_portal_database;
CREATE DATABASE test_portal_database;
USE test_portal_database;

-- ============================================================================
-- SHARED / SUPPORT TABLES
-- ============================================================================

CREATE TABLE attachments (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    table_name      VARCHAR(100) NOT NULL,
    record_id       INT          NOT NULL,
    field_name      VARCHAR(100) NOT NULL,
    file_url        VARCHAR(1000),
    file_name       VARCHAR(500),
    file_size_bytes BIGINT,
    mime_type       VARCHAR(150),
    INDEX idx_attachments_owner (table_name, record_id, field_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ============================================================================
-- 1. EMPLOYEES
-- ============================================================================

CREATE TABLE employees (
    id                              INT AUTO_INCREMENT PRIMARY KEY,
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
    blood_type                      VARCHAR(50),
    address                         TEXT,
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
    location                        VARCHAR(100),
    emergency_contact_person_name   VARCHAR(255),
    emergency_contact_number        VARCHAR(50),
    emergency_contact_address       TEXT,
    bank_name                       VARCHAR(150),
    bank_account_number             VARCHAR(100),
    bank_swift_code                 VARCHAR(50),
    role_level                      VARCHAR(100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ============================================================================
-- 2. NEW EMPLOYEE DATA
-- ============================================================================

CREATE TABLE new_employee_data (
    id                              INT AUTO_INCREMENT PRIMARY KEY,
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
    address                         TEXT,
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
    data_approval                   VARCHAR(100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ============================================================================
-- 3. CLIENTS
-- ============================================================================

CREATE TABLE clients (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    airtable_record_id  VARCHAR(20) UNIQUE,
    name                VARCHAR(255),
    client_id           VARCHAR(100),
    notes               TEXT,
    assignee_email      VARCHAR(255),
    status              VARCHAR(100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ============================================================================
-- 4. PROJECTS
-- ============================================================================

CREATE TABLE projects (
    id                                  INT AUTO_INCREMENT PRIMARY KEY,
    airtable_record_id                  VARCHAR(20) UNIQUE,
    project_number                      VARCHAR(100),
    project_name                        TEXT,
    department                          VARCHAR(100),
    is_renamed_with_acc_number          BOOLEAN,
    is_ledger_moved_to_for_submission   BOOLEAN,
    is_ledger_details_updated           BOOLEAN,
    status                              VARCHAR(100),
    progress_pct                        NUMERIC(5,2),
    type_of_job                         VARCHAR(100),
    area_sqft                           NUMERIC(12,2),
    lod                                 VARCHAR(50),
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
    date_done                           TIMESTAMP NULL,
    date_closed                         TIMESTAMP NULL,
    due_date                            DATE,
    forma_link                          TEXT,
    msteams_project_link                VARCHAR(1000),
    ms_planner_link                     VARCHAR(1000),
    panoramic_link                      VARCHAR(1000),
    ms_loop_link                        VARCHAR(1000),
    google_drive_folder_path            VARCHAR(1000),
    project_lead_email                  VARCHAR(255),
    total_elements                      NUMERIC(12,2),
    project_creation_status             VARCHAR(100),
    project_creation_approver           VARCHAR(255),
    project_approver_email              VARCHAR(255),
    project_creation_approver_remarks   TEXT,
    close_project_requester             VARCHAR(255),
    done_project_requester              VARCHAR(255),
    date_modified                       TIMESTAMP NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ============================================================================
-- 5. PROJECT SCOPES
-- ============================================================================

CREATE TABLE project_scopes (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    airtable_record_id  VARCHAR(20) UNIQUE,
    id_no               INT,
    progress_pct        NUMERIC(5,2)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ============================================================================
-- 6. PROJECT SCOPE SOURCE — T1 PROCESSES
-- ============================================================================

CREATE TABLE project_scope_t1_processes (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    airtable_record_id  VARCHAR(20) UNIQUE,
    wbs_code            TEXT,
    process_index       VARCHAR(100),
    level               VARCHAR(100),
    type                VARCHAR(100),
    name                VARCHAR(255),
    process_wbs         VARCHAR(255),
    sop_reference       VARCHAR(255),
    time_trackable      BOOLEAN,
    department          VARCHAR(100),
    assignee_email      VARCHAR(255),
    status              VARCHAR(100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ============================================================================
-- 7. PROJECT SCOPE SOURCE — T2 PROCEDURES
-- ============================================================================

CREATE TABLE project_scope_t2_procedures (
    id                      INT AUTO_INCREMENT PRIMARY KEY,
    airtable_record_id      VARCHAR(20) UNIQUE,
    wbs_code                TEXT,
    sequence_index          INT,
    level                   VARCHAR(100),
    type                    VARCHAR(100),
    name                    VARCHAR(255),
    process                 VARCHAR(255),
    activity_wbs            VARCHAR(255),
    sop_reference           VARCHAR(255),
    time_trackable          BOOLEAN,
    department              VARCHAR(100),
    assignee_email          VARCHAR(255),
    status                  VARCHAR(100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ============================================================================
-- 8. PROJECT SCOPE SOURCE — T3 ACTIVITIES
-- ============================================================================

CREATE TABLE project_scope_t3_activities (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    airtable_record_id  VARCHAR(20) UNIQUE,
    wbs_code            TEXT,
    activity_index      VARCHAR(100),
    level               VARCHAR(100),
    type                VARCHAR(100),
    name                TEXT,
    procedure_wbs       VARCHAR(255),
    `procedure`           VARCHAR(255),
    process_wbs         VARCHAR(255),
    process             VARCHAR(255),
    sop_reference       VARCHAR(255),
    time_trackable      BOOLEAN,
    department          VARCHAR(100),
    assignee_email      VARCHAR(255),
    status              VARCHAR(100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ============================================================================
-- 9. PROJECT SCOPE SOURCE — T4 TASKS
-- ============================================================================

CREATE TABLE project_scope_t4_tasks (
    id                              INT AUTO_INCREMENT PRIMARY KEY,
    airtable_record_id              VARCHAR(20) UNIQUE,
    wbs_code                        TEXT,
    task_index                      VARCHAR(100),
    task_name                       TEXT,
    task_notes                      TEXT,
    task                            TEXT,
    activity_name                   TEXT,
    activity                        TEXT,
    procedure_name                  VARCHAR(255),
    `procedure`                       TEXT,
    description                     TEXT,
    assigned_to_email               VARCHAR(255),
    start_date                      DATE,
    due_date                        DATE,
    time_spent_hrs                  NUMERIC(10,2),
    status                          VARCHAR(100),
    priority                        VARCHAR(50)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ============================================================================
-- 10. ACTIVITY CODE/S
-- ============================================================================

CREATE TABLE activity_codes (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    airtable_record_id  VARCHAR(20) UNIQUE,
    name                VARCHAR(255),
    id_no               VARCHAR(100),
    department          VARCHAR(100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ============================================================================
-- 11. EARN CODES
-- ============================================================================

CREATE TABLE earn_codes (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    airtable_record_id  VARCHAR(20) UNIQUE,
    description         TEXT,
    did_not_work        NUMERIC(10,2),
    did_work            NUMERIC(10,2)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ============================================================================
-- 12. JOB TYPE ALLOWED ACTIVITIES
-- ============================================================================

CREATE TABLE job_type_allowed_activities (
    id                          INT AUTO_INCREMENT PRIMARY KEY,
    airtable_record_id          VARCHAR(20) UNIQUE,
    job_type                    VARCHAR(255),
    activity_code_id_initials   TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ============================================================================
-- 13. USER REPORTS
-- ============================================================================

CREATE TABLE user_reports (
    id                          INT AUTO_INCREMENT PRIMARY KEY,
    airtable_record_id          VARCHAR(20) UNIQUE,
    report_date                 DATE,
    hours_rendered              NUMERIC(10,2),
    change_in_elements          NUMERIC(12,2),
    progress_per_activity_pct   NUMERIC(5,2),
    remarks                     TEXT,
    late_submission             VARCHAR(50),
    late_submission_approval    VARCHAR(50),
    date_created                TIMESTAMP NULL,
    approval                    VARCHAR(50),
    approver_remarks            TEXT,
    actual_work_date            DATE,
    is_support                  VARCHAR(50),
    end_date                    DATE,
    project_test_summary        TEXT,
    project_test_summary_2      TEXT,
    duration                    NUMERIC(10,2),
    INDEX idx_user_reports_date (report_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ============================================================================
-- 14. USER REPORTS — PROJECT SCOPE PROGRESS
-- ============================================================================

CREATE TABLE user_reports_scope_progress (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    airtable_record_id  VARCHAR(20) UNIQUE,
    report_date         DATE,
    scope_progress_pct  NUMERIC(5,2),
    reported_by         VARCHAR(255)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ============================================================================
-- 15. REQUEST
-- ============================================================================

CREATE TABLE requests (
    id                      INT AUTO_INCREMENT PRIMARY KEY,
    airtable_record_id      VARCHAR(20) UNIQUE,
    request_date            DATE,
    name                    VARCHAR(255),
    no_of_hours             NUMERIC(10,2),
    original_work_day       DATE,
    offset_work_day         DATE,
    offset_hrs              NUMERIC(10,2),
    reason                  TEXT,
    status                  VARCHAR(100),
    approver_remarks        TEXT,
    date_created            TIMESTAMP NULL,
    linked_report_ids       TEXT,
    type                    VARCHAR(100),
    category                VARCHAR(100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ============================================================================
-- 16. REIMBURSEMENTS
-- ============================================================================

CREATE TABLE reimbursements (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    airtable_record_id  VARCHAR(20) UNIQUE,
    reimb_date          DATE,
    item                VARCHAR(500),
    cost                NUMERIC(12,2),
    qty                 NUMERIC(10,2),
    purpose             TEXT,
    employee_name_input VARCHAR(255),
    team                VARCHAR(100),
    status              VARCHAR(100),
    approver_remarks    TEXT,
    date_created        TIMESTAMP NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ============================================================================
-- 17. WARNINGS
-- ============================================================================

CREATE TABLE warnings (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    airtable_record_id  VARCHAR(20) UNIQUE,
    warning_date        DATE,
    description         TEXT,
    status              VARCHAR(100),
    violation_type      VARCHAR(100),
    date_created        TIMESTAMP NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ============================================================================
-- 18. ACHIEVEMENTS AND MILESTONES
-- ============================================================================

CREATE TABLE achievements_milestones (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    airtable_record_id  VARCHAR(20) UNIQUE,
    achievement_date    DATE,
    project_name        VARCHAR(255),
    description         TEXT,
    links               VARCHAR(1000)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ============================================================================
-- 19. BIM FORM
-- ============================================================================

CREATE TABLE bim_form (
    id                              INT AUTO_INCREMENT PRIMARY KEY,
    airtable_record_id              VARCHAR(20) UNIQUE,
    project_number                  VARCHAR(100),
    project_name                    VARCHAR(255),
    project_address                 VARCHAR(500),
    project_size_sqft               NUMERIC(12,2),
    assignee_email                  VARCHAR(255),
    status                          VARCHAR(100),
    attachment_summary              TEXT,
    no_of_floors                    NUMERIC(6,0),
    client_name                     VARCHAR(255),
    poc_name                        VARCHAR(255),
    poc_phone                       VARCHAR(50),
    purpose_of_scan_to_bim          VARCHAR(255),
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE bim_form_elements_included (
    bim_form_id     INT,
    element_name    VARCHAR(255),
    PRIMARY KEY (bim_form_id, element_name),
    FOREIGN KEY (bim_form_id) REFERENCES bim_form(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ============================================================================
-- 20. PROJECT ACTION HISTORY
-- ============================================================================

CREATE TABLE project_action_history (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    airtable_record_id  VARCHAR(20) UNIQUE,
    name                INT,
    action_name         VARCHAR(255),
    created_by          VARCHAR(255),
    remarks             TEXT,
    created_at          TIMESTAMP NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ============================================================================
-- JUNCTION TABLES (many-to-many relationships)
-- All foreign keys below use explicit table-level FOREIGN KEY clauses,
-- since MySQL does not enforce inline column-level REFERENCES clauses.
-- ============================================================================

CREATE TABLE employees_projects (
    employee_id     INT,
    project_id      INT,
    role_on_project VARCHAR(50),
    PRIMARY KEY (employee_id, project_id, role_on_project),
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
    FOREIGN KEY (project_id)  REFERENCES projects(id)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE employee_hierarchy (
    manager_id  INT,
    report_id   INT,
    PRIMARY KEY (manager_id, report_id),
    FOREIGN KEY (manager_id) REFERENCES employees(id) ON DELETE CASCADE,
    FOREIGN KEY (report_id)  REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE employees_reimbursements (
    employee_id      INT,
    reimbursement_id INT,
    PRIMARY KEY (employee_id, reimbursement_id),
    FOREIGN KEY (employee_id)      REFERENCES employees(id)      ON DELETE CASCADE,
    FOREIGN KEY (reimbursement_id) REFERENCES reimbursements(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE employees_project_scopes (
    employee_id      INT,
    project_scope_id INT,
    PRIMARY KEY (employee_id, project_scope_id),
    FOREIGN KEY (employee_id)      REFERENCES employees(id)      ON DELETE CASCADE,
    FOREIGN KEY (project_scope_id) REFERENCES project_scopes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE employees_warnings (
    employee_id INT,
    warning_id  INT,
    PRIMARY KEY (employee_id, warning_id),
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
    FOREIGN KEY (warning_id)  REFERENCES warnings(id)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE employees_user_reports (
    employee_id    INT,
    user_report_id INT,
    PRIMARY KEY (employee_id, user_report_id),
    FOREIGN KEY (employee_id)    REFERENCES employees(id)    ON DELETE CASCADE,
    FOREIGN KEY (user_report_id) REFERENCES user_reports(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE projects_clients (
    project_id INT,
    client_id  INT,
    PRIMARY KEY (project_id, client_id),
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    FOREIGN KEY (client_id)  REFERENCES clients(id)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE projects_project_scopes (
    project_id       INT,
    project_scope_id INT,
    PRIMARY KEY (project_id, project_scope_id),
    FOREIGN KEY (project_id)       REFERENCES projects(id)       ON DELETE CASCADE,
    FOREIGN KEY (project_scope_id) REFERENCES project_scopes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE projects_user_reports (
    project_id     INT,
    user_report_id INT,
    PRIMARY KEY (project_id, user_report_id),
    FOREIGN KEY (project_id)     REFERENCES projects(id)     ON DELETE CASCADE,
    FOREIGN KEY (user_report_id) REFERENCES user_reports(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE projects_requests (
    project_id INT,
    request_id INT,
    PRIMARY KEY (project_id, request_id),
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    FOREIGN KEY (request_id) REFERENCES requests(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE projects_user_reports_scope_progress (
    project_id                      INT,
    user_reports_scope_progress_id  INT,
    PRIMARY KEY (project_id, user_reports_scope_progress_id),
    FOREIGN KEY (project_id)                     REFERENCES projects(id)                     ON DELETE CASCADE,
    FOREIGN KEY (user_reports_scope_progress_id) REFERENCES user_reports_scope_progress(id)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE projects_action_history (
    project_id        INT,
    project_action_id INT,
    PRIMARY KEY (project_id, project_action_id),
    FOREIGN KEY (project_id)        REFERENCES projects(id)               ON DELETE CASCADE,
    FOREIGN KEY (project_action_id) REFERENCES project_action_history(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Target table ambiguous in source base; most likely Project Scope Source T3 Activities
CREATE TABLE projects_activity_scope (
    project_id  INT,
    activity_id INT,
    PRIMARY KEY (project_id, activity_id),
    FOREIGN KEY (project_id)  REFERENCES projects(id)                    ON DELETE CASCADE,
    FOREIGN KEY (activity_id) REFERENCES project_scope_t3_activities(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE project_scopes_t3_activities (
    project_scope_id INT,
    t3_activity_id   INT,
    PRIMARY KEY (project_scope_id, t3_activity_id),
    FOREIGN KEY (project_scope_id) REFERENCES project_scopes(id)             ON DELETE CASCADE,
    FOREIGN KEY (t3_activity_id)   REFERENCES project_scope_t3_activities(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE project_scopes_t4_tasks (
    project_scope_id INT,
    t4_task_id       INT,
    PRIMARY KEY (project_scope_id, t4_task_id),
    FOREIGN KEY (project_scope_id) REFERENCES project_scopes(id)         ON DELETE CASCADE,
    FOREIGN KEY (t4_task_id)       REFERENCES project_scope_t4_tasks(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE project_scopes_assigned_to (
    project_scope_id INT,
    employee_id      INT,
    PRIMARY KEY (project_scope_id, employee_id),
    FOREIGN KEY (project_scope_id) REFERENCES project_scopes(id) ON DELETE CASCADE,
    FOREIGN KEY (employee_id)      REFERENCES employees(id)      ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE t3_activities_t4_tasks (
    t3_activity_id INT,
    t4_task_id     INT,
    PRIMARY KEY (t3_activity_id, t4_task_id),
    FOREIGN KEY (t3_activity_id) REFERENCES project_scope_t3_activities(id) ON DELETE CASCADE,
    FOREIGN KEY (t4_task_id)     REFERENCES project_scope_t4_tasks(id)     ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE t4_task_dependencies (
    task_id            INT,
    depends_on_task_id INT,
    PRIMARY KEY (task_id, depends_on_task_id),
    FOREIGN KEY (task_id)            REFERENCES project_scope_t4_tasks(id) ON DELETE CASCADE,
    FOREIGN KEY (depends_on_task_id) REFERENCES project_scope_t4_tasks(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE t1_processes_t2_procedures (
    t1_process_id   INT,
    t2_procedure_id INT,
    PRIMARY KEY (t1_process_id, t2_procedure_id),
    FOREIGN KEY (t1_process_id)   REFERENCES project_scope_t1_processes(id)  ON DELETE CASCADE,
    FOREIGN KEY (t2_procedure_id) REFERENCES project_scope_t2_procedures(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE user_reports_activity_codes (
    user_report_id   INT,
    activity_code_id INT,
    PRIMARY KEY (user_report_id, activity_code_id),
    FOREIGN KEY (user_report_id)   REFERENCES user_reports(id)   ON DELETE CASCADE,
    FOREIGN KEY (activity_code_id) REFERENCES activity_codes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE user_reports_earn_codes (
    user_report_id INT,
    earn_code_id   INT,
    PRIMARY KEY (user_report_id, earn_code_id),
    FOREIGN KEY (user_report_id) REFERENCES user_reports(id) ON DELETE CASCADE,
    FOREIGN KEY (earn_code_id)   REFERENCES earn_codes(id)   ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE user_report_dependencies (
    user_report_id       INT,
    depends_on_report_id INT,
    PRIMARY KEY (user_report_id, depends_on_report_id),
    FOREIGN KEY (user_report_id)       REFERENCES user_reports(id) ON DELETE CASCADE,
    FOREIGN KEY (depends_on_report_id) REFERENCES user_reports(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE user_reports_scope_progress_t3_activities (
    user_reports_scope_progress_id INT,
    t3_activity_id                 INT,
    PRIMARY KEY (user_reports_scope_progress_id, t3_activity_id),
    FOREIGN KEY (user_reports_scope_progress_id) REFERENCES user_reports_scope_progress(id)  ON DELETE CASCADE,
    FOREIGN KEY (t3_activity_id)                 REFERENCES project_scope_t3_activities(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE user_reports_scope_progress_t4_tasks (
    user_reports_scope_progress_id INT,
    t4_task_id                     INT,
    PRIMARY KEY (user_reports_scope_progress_id, t4_task_id),
    FOREIGN KEY (user_reports_scope_progress_id) REFERENCES user_reports_scope_progress(id) ON DELETE CASCADE,
    FOREIGN KEY (t4_task_id)                     REFERENCES project_scope_t4_tasks(id)      ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE requests_activity_codes (
    request_id       INT,
    activity_code_id INT,
    PRIMARY KEY (request_id, activity_code_id),
    FOREIGN KEY (request_id)       REFERENCES requests(id)       ON DELETE CASCADE,
    FOREIGN KEY (activity_code_id) REFERENCES activity_codes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE requests_earn_codes (
    request_id   INT,
    earn_code_id INT,
    PRIMARY KEY (request_id, earn_code_id),
    FOREIGN KEY (request_id)   REFERENCES requests(id)   ON DELETE CASCADE,
    FOREIGN KEY (earn_code_id) REFERENCES earn_codes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ============================================================================
-- INDEXES for live portal reads
-- user_reports.report_date is indexed in CREATE TABLE above
-- (idx_user_reports_date). Existing databases: sql/portal/indexes.sql.
-- employees_user_reports already has PRIMARY (employee_id, user_report_id) plus
-- a FOREIGN KEY index on user_report_id. Do not add a second copy of that.
-- ============================================================================
