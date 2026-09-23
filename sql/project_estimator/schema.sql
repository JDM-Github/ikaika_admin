-- ============================================================================
-- Project Estimator — Airtable base converted to a PostgreSQL schema
-- Source base ID: appgh0Mki2uHDLAQY
-- ============================================================================
--
-- DESIGN NOTES
--
-- 1. Same conventions as the Users & Projects schema: every table gets a
--    surrogate `id SERIAL PRIMARY KEY` + `airtable_record_id VARCHAR(20)
--    UNIQUE`; multipleRecordLinks become junction tables; computed fields
--    (formula/rollup/lookup) are NOT stored as columns, just noted in
--    comments; attachments go in a shared `attachments` table.
--
-- 2. This base has TWO earn-code-like tables that are NOT duplicates of each
--    other despite similar shape:
--      - `earn_codes` (Airtable "Earn Codes", tbl4UQN80qdFBWZUt) — used by
--        User Reports.
--      - `earn_codes_v2` (Airtable "Grid view 2", tblFPgnet8MP7mwBQ) — this
--        is the table the Holidays table's link fields actually point into
--        (verified by matching Airtable record IDs). Despite the generic
--        Airtable view name, it is a real, separate table of earn-code
--        definitions with "Did Work" multipliers, not a duplicate view.
--
-- 3. `Accounts` contains bcrypt password hashes (per user instruction,
--    included as-is — do not treat this table as safe to expose beyond
--    trusted systems).
--
-- 4. `Holidays` is a genuine holiday calendar (2025-2026 Philippine regular
--    and special holidays) with dates, unlike the Earn Codes tables in the
--    Users & Projects base which only classify pay-rule *types* without
--    dates.
--
-- 5. `Bug Reports` had 0 records at export time; table is still created for
--    completeness.
--
-- 6. Several link fields have ambiguous targets in the source base (the
--    Projects "Label" field links to what appear to be date-stamped records
--    of unclear origin, and "Activity Scope" is not resolvable to a single
--    table with confidence) — flagged as ORPHAN LINK, no FK/junction
--    generated for these.
-- ============================================================================

DROP DATABASE IF EXISTS test_estimator_database;
CREATE DATABASE test_estimator_database;
USE test_estimator_database;

-- ============================================================================
-- SHARED / SUPPORT TABLES
-- ============================================================================

CREATE TABLE attachments (
    id              SERIAL PRIMARY KEY,
    table_name      VARCHAR(100) NOT NULL,
    record_id       INTEGER      NOT NULL,
    field_name      VARCHAR(100) NOT NULL,
    file_url        VARCHAR(1000),
    file_name       VARCHAR(500),
    file_size_bytes BIGINT,
    mime_type       VARCHAR(150)
);
CREATE INDEX idx_pe_attachments_owner ON attachments(table_name, record_id, field_name);


-- ============================================================================
-- 1. EMPLOYEES  (Airtable table: "Grid view - Active Status" / tbltDzOjQKkh7IsQf)
-- ============================================================================

CREATE TABLE employees (
    id                              SERIAL PRIMARY KEY,
    airtable_record_id              VARCHAR(20) UNIQUE,
    id_no                           VARCHAR(100),
    first_name                      VARCHAR(255),
    last_name                       VARCHAR(255),
    middle_name                     VARCHAR(255),
    job_title                       VARCHAR(255),   -- singleSelect
    role_level                      VARCHAR(100),   -- singleSelect
    nickname                        TEXT,
    email                           VARCHAR(255),
    start_date                      DATE,
    end_date                        DATE,
    status                          VARCHAR(100),   -- singleSelect
    employment_status               VARCHAR(100),   -- singleSelect
    date_of_birth                   DATE,
    address                         VARCHAR(500),
    personal_email                  VARCHAR(255),
    tax_identification_no           VARCHAR(100),
    philhealth_no                   VARCHAR(100),
    sss_no                          VARCHAR(100),
    hdmf_no                         VARCHAR(100),
    phone_number                    VARCHAR(50),
    emergency_contact_person_name   VARCHAR(255),
    emergency_contact_number        VARCHAR(50),
    emergency_contact_address       TEXT,
    bank_name                       VARCHAR(150),   -- singleSelect
    bank_account_number             VARCHAR(100),
    bank_swift_code                 VARCHAR(50),
    sl_credits                      NUMERIC(10,2),
    remaining_sl                    NUMERIC(10,2),
    vl_credits                      NUMERIC(10,2),
    remaining_vl                    NUMERIC(10,2),
    profile_folder_url              VARCHAR(1000),
    role                            VARCHAR(100),   -- singleSelect
    department                      VARCHAR(100),   -- singleSelect
    location                        VARCHAR(100),   -- singleSelect
    third_month_evaluation          DATE,
    fifth_month_evaluation          DATE
    -- Attachments: photo, qr_code, employment_agreement, tax_identification,
    --   philhealth_id, ss_id, hdmf_id, bank_certificate
    -- Computed / not stored: name (multilineText mirror of first+last)
);


-- ============================================================================
-- 2. PROJECTS  (Airtable table: "Projects" / tblGcioPZTKFkuH5q)
-- ============================================================================

CREATE TABLE projects (
    id                                  SERIAL PRIMARY KEY,
    airtable_record_id                  VARCHAR(20) UNIQUE,
    project_name                        TEXT,
    client_name                         VARCHAR(255),   -- plain text field here, not a link
    department                          VARCHAR(100),   -- singleSelect
    is_renamed_with_acc_number          BOOLEAN,
    is_ledger_moved_to_for_submission   BOOLEAN,
    is_ledger_details_updated           BOOLEAN,
    status                              VARCHAR(100),   -- singleSelect
    progress_pct                        NUMERIC(5,2),
    type_of_job                         VARCHAR(100),   -- singleSelect
    area_sqft                           NUMERIC(12,2),
    lod                                 VARCHAR(50),    -- singleSelect
    ave_lod                             VARCHAR(50),    -- stored as text here, not formula
    ar_lod                              VARCHAR(50),
    st_lod                              VARCHAR(50),
    md_lod                              VARCHAR(50),
    el_lod                              VARCHAR(50),
    pl_lod                              VARCHAR(50),
    fp_lod                              VARCHAR(50),
    estimated_num_elements              TEXT,
    productivity_kpi                    VARCHAR(50),    -- stored as text here
    total_noe                           NUMERIC(12,2),
    ar_noe                              NUMERIC(12,2),
    st_noe                              NUMERIC(12,2),
    el_noe                              NUMERIC(12,2),
    fp_noe                              NUMERIC(12,2),
    md_noe                              NUMERIC(12,2),
    mp_noe                              NUMERIC(12,2),
    pl_noe                              NUMERIC(12,2),
    mc_noe                              NUMERIC(12,2),
    qc_noe                              NUMERIC(12,2),
    total_noh                           NUMERIC(12,2),
    tot_hrs                             NUMERIC(12,2),
    tot_hrs_internal                    NUMERIC(12,2),
    total_noh_ot                        NUMERIC(12,2),
    ar_hrs                              NUMERIC(12,2),
    st_hrs                              NUMERIC(12,2),
    el_hrs                              NUMERIC(12,2),
    el_hrs_copy                         NUMERIC(12,2),
    fp_hrs                              NUMERIC(12,2),
    mp_hrs                              NUMERIC(12,2),
    pl_hrs                              NUMERIC(12,2),
    mc_hrs                              NUMERIC(12,2),
    md_hrs                              NUMERIC(12,2),
    qc_hrs                              NUMERIC(12,2),
    ds_hrs                              NUMERIC(12,2),
    num_elements                        NUMERIC(12,2),
    file_size_mb                        NUMERIC(12,2),
    scan_file_size_gb                   TEXT,
    date_started                        DATE,
    date_done                           TIMESTAMP,
    date_closed                         TIMESTAMP,
    due_date                            DATE,
    acc_project_link                    TEXT,
    msteams_project_link                VARCHAR(1000),
    ms_planner_link                     VARCHAR(1000),
    panoramic_link                      VARCHAR(1000),
    google_drive_folder_path            VARCHAR(1000),
    pm_name                             VARCHAR(255),   -- text mirror; PM ID link in junction
    project_member_names                VARCHAR(1000),  -- text mirror; link in junction
    support_member_name                 VARCHAR(1000),  -- text mirror; link in junction
    total_elements                      NUMERIC(12,2),
    creator_name                        VARCHAR(255),
    last_modified_by_name               VARCHAR(255),
    date_modified                       TIMESTAMP,
    project_creation_status             VARCHAR(100),   -- singleSelect
    project_creation_approver           VARCHAR(255),
    project_approver_email              VARCHAR(255),
    project_creation_approver_remarks   TEXT,
    last_report_date                    VARCHAR(100),   -- stored as text here
    close_project_requester             VARCHAR(255),
    done_project_requester              VARCHAR(255),
    project_lead_email                  VARCHAR(255),
    ms_loop_link                        VARCHAR(1000),
    budgeted_hrs                        NUMERIC(12,2),
    project_efficiency                  NUMERIC(12,2)
    -- Relationships: see junction tables below (Activity Scope, Label,
    --   PM ID, Project Members, Support Members, User Reports, createdBy,
    --   lastModifiedBy)
    -- ORPHAN LINK: "Label" — links to records of unclear/mixed origin
    --   (observed values look like date-stamped User-Report-style records);
    --   not confidently mapped to a single table, no FK generated.
);


-- ============================================================================
-- 3. ACTIVITY CODES  (Airtable table: "Activity Codes" / tbl2pBn659tBJOopl)
-- ============================================================================

CREATE TABLE activity_codes (
    id                  SERIAL PRIMARY KEY,
    airtable_record_id  VARCHAR(20) UNIQUE,
    name                VARCHAR(255),
    id_no               VARCHAR(100),
    department          VARCHAR(100)    -- singleSelect
    -- Relationships: see junction tables below (Project/s, User Reports, Request)
);


-- ============================================================================
-- 4. EARN CODES  (Airtable table: "Earn Codes" / tbl4UQN80qdFBWZUt)
-- ============================================================================

CREATE TABLE earn_codes (
    id                  SERIAL PRIMARY KEY,
    airtable_record_id  VARCHAR(20) UNIQUE,
    description         TEXT,
    did_not_work        NUMERIC(10,2),
    did_work            NUMERIC(10,2)
    -- Relationships: see junction tables below (User Reports)
);


-- ============================================================================
-- 5. EARN CODES V2  (Airtable table: "Grid view 2" / tblFPgnet8MP7mwBQ)
--    See design note #2 above — this is the table Holidays actually links to.
-- ============================================================================

CREATE TABLE earn_codes_v2 (
    id                  SERIAL PRIMARY KEY,
    airtable_record_id  VARCHAR(20) UNIQUE,
    description         VARCHAR(255),
    did_not_work        NUMERIC(10,2),
    did_work            NUMERIC(10,2)
);


-- ============================================================================
-- 6. ACCOUNTS  (Airtable table: "Accounts" / tbls00i5EQPpgRPb9)
--    Contains bcrypt password hashes — see design note #3 above.
-- ============================================================================

CREATE TABLE accounts (
    id                  SERIAL PRIMARY KEY,
    airtable_id_no      INTEGER UNIQUE,   -- Airtable autoNumber field, not the surrogate PK
    airtable_record_id  VARCHAR(20) UNIQUE,
    name                VARCHAR(255),
    username             VARCHAR(255),
    password_hash        VARCHAR(255),    -- bcrypt hash
    invite_code           VARCHAR(50),
    invited_by            VARCHAR(255)     -- free-text "Name::code" mirror, not an FK
);


-- ============================================================================
-- 7. PROJECT ESTIMATES  (Airtable table: "Project Estimates" / tbly18RtAuy8Vyn8t)
-- ============================================================================

CREATE TABLE project_estimates (
    id                  SERIAL PRIMARY KEY,
    airtable_record_id  VARCHAR(20) UNIQUE,
    name                VARCHAR(255),
    notes               TEXT,            -- free text, observed format "[owner:recXXXX]"
    assignee_email      VARCHAR(255),    -- singleCollaborator
    status               VARCHAR(100),    -- singleSelect
    scopes               VARCHAR(255),
    scope                VARCHAR(100),    -- singleSelect: Global / Local
    date_modified_iso     VARCHAR(50),     -- app-maintained ISO 8601 string, see Airtable field description
    project_start         DATE,
    project_end           DATE,
    member_trades         TEXT,            -- JSON: {"name": "trade_code", ...}
    member_emails         TEXT,            -- JSON: {"name": "email", ...}
    booking_schedule       TEXT             -- JSON: {"start","end","attendees":[...]}
    -- Attachments: attachments (estimate.json snapshot file), attachment_summary
);


-- ============================================================================
-- 8. HOLIDAYS  (Airtable table: "Holidays" / tblhGH9QUfJ61BVyg)
--    Genuine holiday calendar with dates (PH regular/special holidays 2025-2026).
-- ============================================================================

CREATE TABLE holidays (
    id                      SERIAL PRIMARY KEY,
    airtable_record_id      VARCHAR(20) UNIQUE,
    holiday_date            DATE,
    name                    VARCHAR(255),
    special_holiday_type    VARCHAR(50)     -- singleSelect, mostly "N/A"
    -- Relationships: see junction tables below (Earn Code / OT / ND / ND+OT
    --   links, all pointing into earn_codes_v2)
);


-- ============================================================================
-- 9. BUG REPORTS  (Airtable table: "Bug Reports" / tbltXTfNs9mtOuHIl)
--    0 records at export time.
-- ============================================================================

CREATE TABLE bug_reports (
    id                  SERIAL PRIMARY KEY,
    airtable_record_id  VARCHAR(20) UNIQUE,
    title               VARCHAR(255),
    description         TEXT,
    severity            VARCHAR(50),    -- singleSelect
    status              VARCHAR(100),   -- singleSelect
    reporter            VARCHAR(255),
    client_version       VARCHAR(100),
    api_version           VARCHAR(100),
    device                VARCHAR(255)
);


-- ============================================================================
-- 10. USER REPORTS  (Airtable table: "User Reports" / tbliMrRnGYwhRIUqS)
-- ============================================================================

CREATE TABLE user_reports (
    id                          SERIAL PRIMARY KEY,
    airtable_record_id          VARCHAR(20) UNIQUE,
    report_date                 DATE,
    name                        VARCHAR(255),
    client                      VARCHAR(255),   -- free text mirror here, not a link
    hours_rendered              NUMERIC(10,2),
    change_in_elements          NUMERIC(12,2),
    hrs_computed                NUMERIC(10,2),  -- "*Hrs." field, stored as plain number here
    elements_per_8_hours        NUMERIC(12,2),
    progress_per_activity_pct   NUMERIC(5,2),
    remarks                     TEXT,
    late_submission             VARCHAR(50),    -- singleSelect
    late_submission_approval    VARCHAR(50),    -- singleSelect
    date_created                DATE,
    approval                    VARCHAR(50),    -- singleSelect
    approver_remarks            TEXT,
    actual_work_date            DATE,
    is_support                  VARCHAR(50),    -- singleSelect
    hours_multiplier            VARCHAR(50)     -- stored as text here (e.g. "1.000")
    -- Computed / not stored: role_level_from_employees, employment_status_from_employees,
    --   department_from_employees, type_of_job_from_project (multipleSelects lookups)
    -- Relationships: see junction tables below (Employee No., Project, Activity, Earn Code)
);


-- ============================================================================
-- JUNCTION TABLES
-- ============================================================================

-- Employees <-> Projects (Project Members, Support Members, PM ID on Projects)
CREATE TABLE employees_projects (
    employee_id     INTEGER REFERENCES employees(id) ON DELETE CASCADE,
    project_id      INTEGER REFERENCES projects(id)  ON DELETE CASCADE,
    role_on_project VARCHAR(50), -- 'member' | 'support' | 'pm'
    PRIMARY KEY (employee_id, project_id, role_on_project)
);

-- User Reports <-> Employees ("Employee No." on User Reports)
CREATE TABLE employees_user_reports (
    employee_id     INTEGER REFERENCES employees(id) ON DELETE CASCADE,
    user_report_id  INTEGER REFERENCES user_reports(id) ON DELETE CASCADE,
    PRIMARY KEY (employee_id, user_report_id)
);

-- User Reports <-> Projects
CREATE TABLE projects_user_reports (
    project_id      INTEGER REFERENCES projects(id) ON DELETE CASCADE,
    user_report_id  INTEGER REFERENCES user_reports(id) ON DELETE CASCADE,
    PRIMARY KEY (project_id, user_report_id)
);

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

-- Activity Codes <-> Projects ("Project/s" on Activity Codes)
CREATE TABLE activity_codes_projects (
    activity_code_id INTEGER REFERENCES activity_codes(id) ON DELETE CASCADE,
    project_id        INTEGER REFERENCES projects(id) ON DELETE CASCADE,
    PRIMARY KEY (activity_code_id, project_id)
);

-- Holidays <-> Earn Codes V2 (four separate link fields: base / OT / ND / ND+OT)
CREATE TABLE holidays_earn_codes_v2 (
    holiday_id      INTEGER REFERENCES holidays(id) ON DELETE CASCADE,
    earn_code_v2_id INTEGER REFERENCES earn_codes_v2(id) ON DELETE CASCADE,
    variant         VARCHAR(20), -- 'base' | 'ot' | 'nd' | 'nd_ot'
    PRIMARY KEY (holiday_id, earn_code_v2_id, variant)
);


-- ============================================================================
-- INDEXES for portal holiday reads (GET /calendar/holidays orders by date).
-- Existing databases: sql/project_estimator/indexes.sql.
-- ============================================================================
CREATE INDEX idx_pe_holidays_date ON holidays (holiday_date);
