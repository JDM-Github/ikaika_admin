USE test_portal_database;

-- ============================================================================
-- PORTAL AUDIT — user tracking and in-app notifications
-- logs: every member action the portal backend records (no tokens / secrets).
-- as_developer: written while the actor was in Developer mode; All Logs names them as
-- "A developer" to anyone not in Developer mode, and with the mode to anyone who is.
-- notifications: inbox rows. link_path is the screen the UI opens on click;
-- payload holds ids and other extras for that screen. read_at is null until read.
-- ============================================================================

CREATE TABLE logs (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    employee_id   INT          NOT NULL,
    action        VARCHAR(64)  NOT NULL,
    resource      VARCHAR(191) NOT NULL,
    record_id     VARCHAR(191) NULL,
    message       TEXT         NULL,
    ip_address    VARCHAR(45)  NULL,
    user_agent    VARCHAR(255) NULL,
    location_label  VARCHAR(255) NULL,
    location_source VARCHAR(32) NULL,
    location_lat    DECIMAL(10,7) NULL,
    location_lng    DECIMAL(10,7) NULL,
    as_developer  TINYINT(1)   NOT NULL DEFAULT 0,
    payload       JSON         NULL,
    created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_logs_employee_created (employee_id, created_at),
    INDEX idx_logs_resource_created (resource, created_at),
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE notifications (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    employee_id   INT          NOT NULL,
    actor_id      INT          NULL,
    type          VARCHAR(64)  NOT NULL,
    title         VARCHAR(255) NOT NULL,
    message       TEXT         NOT NULL,
    link_path     VARCHAR(500) NULL,
    link_label    VARCHAR(100) NULL,
    payload       JSON         NULL,
    read_at       TIMESTAMP    NULL,
    created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_notifications_inbox (employee_id, read_at, created_at),
    INDEX idx_notifications_employee_created (employee_id, created_at),
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
    FOREIGN KEY (actor_id)    REFERENCES employees(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ============================================================================
-- EVENT CALENDAR — member-created company and project events
-- audience everyone is visible to every signed-in member. department rows
-- match employees.department. members rows name specific employee ids.
-- The creator always sees their own event. Existing databases: the Laravel
-- migration create_portal_calendar_events_tables.
-- ============================================================================

CREATE TABLE calendar_events (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    title        VARCHAR(255) NOT NULL,
    details      TEXT         NULL,
    starts_on    DATE         NOT NULL,
    starts_at    TIME         NULL,
    ends_on      DATE         NULL,
    ends_at      TIME         NULL,
    category     VARCHAR(32)  NOT NULL,
    audience     VARCHAR(32)  NOT NULL,
    created_by   INT          NOT NULL,
    date_created DATETIME     NULL,
    INDEX idx_calendar_events_starts_on (starts_on),
    INDEX idx_calendar_events_created_by (created_by),
    FOREIGN KEY (created_by) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE calendar_event_departments (
    event_id   INT          NOT NULL,
    department VARCHAR(100) NOT NULL,
    PRIMARY KEY (event_id, department),
    FOREIGN KEY (event_id) REFERENCES calendar_events(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE calendar_event_members (
    event_id    INT NOT NULL,
    employee_id INT NOT NULL,
    PRIMARY KEY (event_id, employee_id),
    INDEX idx_calendar_event_members_employee (employee_id),
    FOREIGN KEY (event_id)    REFERENCES calendar_events(id) ON DELETE CASCADE,
    FOREIGN KEY (employee_id) REFERENCES employees(id)      ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ============================================================================
-- ADMIN EMAIL — the Send Email page
-- email_blocks: what the composer reuses. kind 'template' carries a category and
-- subject; kind 'footer' is a signature or disclaimer appended under the body.
-- email_messages: the outbox, one row per send, holding a snapshot of the body
-- and footer it went out with, so editing a block never rewrites history.
-- audience_filter holds departments, roles and employee ids for that audience.
-- format is 'markup' (the toolbar's light markup, escaped) or 'html' (sent as written);
-- footer_format is the snapshot footer's own format.
-- Existing databases: the Laravel migrations create_portal_email_tables and
-- add_format_to_portal_email_tables.
-- ============================================================================

CREATE TABLE email_blocks (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    kind         VARCHAR(16)  NOT NULL,
    name         VARCHAR(191) NOT NULL,
    category     VARCHAR(32)  NULL,
    subject      VARCHAR(255) NULL,
    body         TEXT         NOT NULL,
    format       VARCHAR(8)   NOT NULL DEFAULT 'markup',
    created_by   INT          NOT NULL,
    date_created DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    date_updated DATETIME     NULL,
    INDEX idx_email_blocks_kind (kind, name),
    FOREIGN KEY (created_by) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE email_messages (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    category        VARCHAR(32)  NOT NULL,
    subject         VARCHAR(255) NOT NULL,
    body            TEXT         NOT NULL,
    format          VARCHAR(8)   NOT NULL DEFAULT 'markup',
    footer_id       INT          NULL,
    footer_body     TEXT         NULL,
    footer_format   VARCHAR(8)   NULL,
    audience        VARCHAR(32)  NOT NULL,
    audience_filter JSON         NULL,
    recipient_count INT          NOT NULL DEFAULT 0,
    sent_count      INT          NOT NULL DEFAULT 0,
    failed_count    INT          NOT NULL DEFAULT 0,
    failures        JSON         NULL,
    status          VARCHAR(16)  NOT NULL DEFAULT 'sent',
    sent_by         INT          NOT NULL,
    date_sent       DATETIME     NULL,
    date_created    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_email_messages_created (date_created),
    INDEX idx_email_messages_sent_by (sent_by),
    FOREIGN KEY (footer_id) REFERENCES email_blocks(id) ON DELETE SET NULL,
    FOREIGN KEY (sent_by)   REFERENCES employees(id)    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ============================================================================
-- REPORT FLAGGING — is_flag / flag_status / flag_remarks on employees_user_reports
-- A daily or late report filed by an employee who currently has an Active row in
-- `warnings` is still recorded exactly like anyone else's -- only this junction row
-- is marked for a reviewer's attention. is_flag is set once at submission time
-- (PortalSubmittedReports::insertLine) and never touched again: it is the permanent
-- fact that this report was filed while the employee was on a warning. flag_status
-- and flag_remarks are the reviewable part -- an approver's decision and note,
-- written together (PortalManageRequests::applyFlaggedReport), same as every other
-- request kind's status + approverRemarks pair.
--
-- Plain ADD COLUMN, not IF NOT EXISTS: MySQL never supported that clause on ADD
-- COLUMN (unlike CREATE TABLE/DATABASE), and reset.ps1 always runs this against a
-- table schema.sql just dropped and recreated, so there is nothing to retrofit.
-- ============================================================================

ALTER TABLE employees_user_reports
    ADD COLUMN is_flag      TINYINT(1)  NOT NULL DEFAULT 0,
    ADD COLUMN flag_status  VARCHAR(50) NOT NULL DEFAULT 'Pending',
    ADD COLUMN flag_remarks TEXT        NULL;


-- ============================================================================
-- DEVELOPER ACCESS — is_developer on employees
-- Portal-only, like the flags above: Airtable's Employees table has no such field and
-- the sync never sends it. A developer will sit above an admin (who has limits a
-- developer will not); nothing reads it yet. Read-only in the admin grid
-- (config/workspace.php readonly_columns), so an admin cannot grant it to themselves.
-- ============================================================================

ALTER TABLE employees
    ADD COLUMN is_developer TINYINT(1) NOT NULL DEFAULT 0;

-- The portal's developers. Matched on id_no, never on name: another employee carries
-- "Malabanan" as a middle name. Needs the employees data.sql loads, which is why
-- reset.ps1 runs this file after data.sql.
UPDATE employees
SET is_developer = 1
WHERE id_no IN (
    '260701-0020', -- John Dave Pega
    '260701-0019', -- Jhon Noriel Malabanan
    '260701-0018', -- Jolas Mendez
    '220101-0001', -- Don De Guzman, the president
    '220202-0002'  -- Nathaniel Tanglao, the MIS manager
);

-- Developer mode: is_developer alone grants nothing any more. A developer uses the portal
-- as their plain role until they switch Developer mode on in Settings with their own
-- password, and it lasts until they sign out (active_session is the signed-in token's
-- sid). No row yet, or a NULL password_hash, means the default password 123 still
-- stands and must be replaced on first use. Kept off employees so the hash never reaches
-- the admin grid or the Airtable sync.
CREATE TABLE IF NOT EXISTS developer_modes (
    employee_id    INT          NOT NULL PRIMARY KEY,
    password_hash  VARCHAR(255) NULL,
    active_session VARCHAR(64)  NULL,
    updated_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);



-- ============================================================================
-- FINE-GRAINED RULES — the Access page (FINE_GRAINED_RULES.md)
-- access_permissions: the rule catalog, one row per page, tab or action. parent_key
-- builds the tree; supports_reach marks a rule that shows other people's records
-- (own / department / all); min_tier is the lowest role that may hold it at all (NULL
-- means anyone), so a member is never given it; 'developer' means only a developer in Developer
-- mode, which no gift can reach; only_department limits an admin to holding it only while in that
-- department (executives and Developer mode always may); can_toggle 0 keeps a rule its holder may never switch
-- off (the Access page itself, or nobody could switch anything back on). A description
-- never says "you" doing something: {UserName|Your} reads "Your" on the Access page and
-- "Jolas Mendez's" on someone's Access Rule sheet, so each sentence fits both.
-- access_presets: what each role starts with. A developer (employees.is_developer)
-- takes the developer row, then executive (role_level), then admin (role), then
-- member; ProjectAdmin is a plain member. include_executives lets that rule show
-- executives' rows, which only developers and executives see, on All Logs only.
-- access_toggles: a person's own switched-off rules; no row means on. A toggle is
-- their convenience only: it never adds a power and never touches what they gave.
-- access_self_reach: how far a person narrowed a reach rule for themselves; no row means
-- as far as they hold it. It only ever narrows, and never touches what they gave.
-- access_grants: a power one person gave another. It counts only while the giver
-- may still manage them and still holds that power, and never wider than the
-- giver's own reach, so taking a power from someone silently ends everything they
-- gave from it (PortalAccessGraph).
-- access_limits: a power taken from someone, with everything under it, whatever
-- gave it to them. Counts under the same conditions as a grant.
-- access_personal: what one person starts with on top of their role (their own defaults), seeded
-- below by id_no like is_developer. Counts like their role preset: a limit still takes it away.
-- access_lists: a white list (allow: only these people) or black list (block: never these
-- people) one maker set on someone's rule, for a rule marked supports_list. Counts under the
-- same conditions as a limit; every black list hides and every white list must name a person.
-- A maker lists only people they can see themselves, and keeps one kind of list per rule. The
-- rule's ignore power (<key>.unlisted) skips every list, as Developer mode does.
-- as_developer on a grant, limit or list: made in Developer mode. Only those count as a
-- developer's; one made in Default is judged as the maker's role like anyone's, and a maker in
-- Default can see but never change what they made in Developer mode.
-- Portal-only: no airtable_record_id, never synced, read-only in the admin grid.
-- Pages switch over to their rules one at a time (FINE_GRAINED_RULES.md, Enforced so far).
-- ============================================================================

CREATE TABLE access_permissions (
    permission_key VARCHAR(191) PRIMARY KEY,
    parent_key     VARCHAR(191) NULL,
    label          VARCHAR(191) NOT NULL,
    description    TEXT         NOT NULL,
    supports_reach TINYINT(1)   NOT NULL DEFAULT 0,
    supports_list  TINYINT(1)   NOT NULL DEFAULT 0,
    can_toggle     TINYINT(1)   NOT NULL DEFAULT 1,
    min_tier       VARCHAR(16)  NULL,
    only_department VARCHAR(100) NULL,
    sort_order     INT          NOT NULL DEFAULT 0,
    INDEX idx_access_permissions_parent (parent_key, sort_order),
    FOREIGN KEY (parent_key) REFERENCES access_permissions(permission_key) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE access_presets (
    role               VARCHAR(16)  NOT NULL,
    permission_key     VARCHAR(191) NOT NULL,
    reach              VARCHAR(16)  NULL,
    include_executives TINYINT(1)   NOT NULL DEFAULT 0,
    PRIMARY KEY (role, permission_key),
    FOREIGN KEY (permission_key) REFERENCES access_permissions(permission_key) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE access_toggles (
    employee_id    INT          NOT NULL,
    permission_key VARCHAR(191) NOT NULL,
    created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (employee_id, permission_key),
    FOREIGN KEY (employee_id)    REFERENCES employees(id)                      ON DELETE CASCADE,
    FOREIGN KEY (permission_key) REFERENCES access_permissions(permission_key) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE access_self_reach (
    employee_id    INT          NOT NULL,
    permission_key VARCHAR(191) NOT NULL,
    reach          VARCHAR(16)  NOT NULL,
    updated_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (employee_id, permission_key),
    FOREIGN KEY (employee_id)    REFERENCES employees(id)                      ON DELETE CASCADE,
    FOREIGN KEY (permission_key) REFERENCES access_permissions(permission_key) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE access_grants (
    id                 INT AUTO_INCREMENT PRIMARY KEY,
    employee_id        INT          NOT NULL,
    permission_key     VARCHAR(191) NOT NULL,
    reach              VARCHAR(16)  NULL,
    include_executives TINYINT(1)   NOT NULL DEFAULT 0,
    granted_by         INT          NOT NULL,
    as_developer       TINYINT(1)   NOT NULL DEFAULT 0,
    created_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_access_grants (employee_id, permission_key, granted_by, as_developer),
    INDEX idx_access_grants_granted_by (granted_by),
    FOREIGN KEY (employee_id)    REFERENCES employees(id)                      ON DELETE CASCADE,
    FOREIGN KEY (granted_by)     REFERENCES employees(id)                      ON DELETE CASCADE,
    FOREIGN KEY (permission_key) REFERENCES access_permissions(permission_key) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE access_limits (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    employee_id    INT          NOT NULL,
    permission_key VARCHAR(191) NOT NULL,
    limited_by     INT          NOT NULL,
    as_developer   TINYINT(1)   NOT NULL DEFAULT 0,
    created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_access_limits (employee_id, permission_key, limited_by, as_developer),
    INDEX idx_access_limits_limited_by (limited_by),
    FOREIGN KEY (employee_id)    REFERENCES employees(id)                      ON DELETE CASCADE,
    FOREIGN KEY (limited_by)     REFERENCES employees(id)                      ON DELETE CASCADE,
    FOREIGN KEY (permission_key) REFERENCES access_permissions(permission_key) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE access_personal (
    employee_id    INT          NOT NULL,
    permission_key VARCHAR(191) NOT NULL,
    reach          VARCHAR(16)  NULL,
    PRIMARY KEY (employee_id, permission_key),
    FOREIGN KEY (employee_id)    REFERENCES employees(id)                      ON DELETE CASCADE,
    FOREIGN KEY (permission_key) REFERENCES access_permissions(permission_key) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE access_lists (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    employee_id    INT          NOT NULL,
    permission_key VARCHAR(191) NOT NULL,
    mode           VARCHAR(8)   NOT NULL,
    subject_id     INT          NOT NULL,
    set_by         INT          NOT NULL,
    as_developer   TINYINT(1)   NOT NULL DEFAULT 0,
    created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_access_lists (employee_id, permission_key, set_by, as_developer, subject_id),
    INDEX idx_access_lists_set_by (set_by),
    FOREIGN KEY (employee_id)    REFERENCES employees(id)                      ON DELETE CASCADE,
    FOREIGN KEY (subject_id)     REFERENCES employees(id)                      ON DELETE CASCADE,
    FOREIGN KEY (set_by)         REFERENCES employees(id)                      ON DELETE CASCADE,
    FOREIGN KEY (permission_key) REFERENCES access_permissions(permission_key) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Parents before children: parent_key must already exist when its child is inserted.
INSERT INTO access_permissions (permission_key, parent_key, label, description, supports_reach, can_toggle, sort_order) VALUES
('home', NULL, 'Home', 'Open the Home dashboard: {UserName|Your} hours, recent reports and followed projects.', 0, 1, 100),

('projects', NULL, 'Projects', 'Open the Projects section of the sidebar.', 0, 1, 200),
('projects.view', 'projects', 'View Projects', 'Browse projects and open each one''s dashboard, details and trail history.', 1, 1, 210),
('projects.view.edit', 'projects.view', 'Edit projects', 'Edit project details and manage scopes. A project''s lead can always edit their own project.', 0, 1, 211),
('projects.view.delete', 'projects.view', 'Delete projects', 'Move a project to the recycle bin. A project''s lead can always delete their own project.', 0, 1, 212),
('projects.create', 'projects', 'Add projects', 'Start a new project from Add New Project.', 0, 1, 220),
('projects.track', 'projects', 'Track Projects', 'Follow projects by status, year, month and department: the projects {UserName|You} work on, plus {UserName|Your} department''s or every department''s.', 1, 1, 230),
('projects.track.export', 'projects.track', 'Export', 'Download the tracked projects as PDF, XLSX, Word, CSV or JSON.', 0, 1, 231),

('reports', NULL, 'Reports', 'Open the Reports section of the sidebar.', 0, 1, 300),
('reports.daily', 'reports', 'Daily Report', 'File {UserName|Your} timesheet for today.', 0, 1, 310),
('reports.late', 'reports', 'Late Report', 'File {UserName|Your} timesheet for a missed past day.', 0, 1, 320),
('reports.submitted', 'reports', 'Submitted Reports', 'See {UserName|Your} filed reports on a calendar, with every entry.', 0, 1, 330),
('reports.submitted.edit', 'reports.submitted', 'Edit reports', 'Change one of {UserName|Your} reports filed in the last 7 days.', 0, 1, 331),
('reports.submitted.delete', 'reports.submitted', 'Delete reports', 'Delete one of {UserName|Your} reports filed in the last 7 days. It waits in {UserName|Your} recycle bin.', 0, 1, 332),
('reports.submitted.export', 'reports.submitted', 'Export', 'Download {UserName|Your} submitted reports.', 0, 1, 333),

('requests', NULL, 'Requests', 'Open the Requests section of the sidebar.', 0, 1, 400),
('requests.own', 'requests', 'User Requests', 'See {UserName|Your} leave, overtime, offset and reimbursement requests and flagged reports in one place.', 0, 1, 410),
('requests.own.edit', 'requests.own', 'Edit requests', 'Change one of {UserName|Your} requests while it is still pending.', 0, 1, 411),
('requests.own.cancel', 'requests.own', 'Cancel requests', 'Cancel one of {UserName|Your} requests while it is still pending.', 0, 1, 412),
('requests.own.export', 'requests.own', 'Export', 'Download {UserName|Your} requests.', 0, 1, 413),
('requests.leave', 'requests', 'Leave Request', 'File a leave request.', 0, 1, 420),
('requests.overtime', 'requests', 'Overtime Request', 'File an overtime request.', 0, 1, 430),
('requests.offset', 'requests', 'Offset Request', 'File an offset request.', 0, 1, 440),
('requests.reimbursement', 'requests', 'Reimbursement Request', 'File a reimbursement claim with its receipts.', 0, 1, 450),

('user', NULL, 'User', 'Open the User section of the sidebar.', 0, 1, 500),
('user.logs', 'user', 'Logs', 'See everything done under {UserName|Your} account in the portal.', 0, 1, 510),
('user.logs.export', 'user.logs', 'Export', 'Download {UserName|Your} logs.', 0, 1, 511),
('user.recycle', 'user', 'Recycle Bin', 'See {UserName|Your} deleted items from the last 30 days.', 0, 1, 520),
('user.recycle.restore', 'user.recycle', 'Restore', 'Put one of {UserName|Your} deleted items back where it was.', 0, 1, 521),
('user.recycle.export', 'user.recycle', 'Export', 'Download {UserName|Your} recycle bin.', 0, 1, 522),

('calendar', NULL, 'Event Calendar', 'See company events, holidays, leave and project dates.', 0, 1, 600),
('calendar.create', 'calendar', 'Add events', 'Add a company or project event for everyone, a department or chosen members.', 0, 1, 610),
('calendar.download', 'calendar', 'Download calendar', 'Download the calendar as an .ics file.', 0, 1, 620),

('access', NULL, 'Access', 'See {UserName|Your} powers and switch them off and on. This page itself always stays on.', 0, 0, 700),

('manage', NULL, 'Manage', 'Work on other people''s records.', 0, 1, 800),
('manage.requests', 'manage', 'Requests', 'Review other people''s requests. Each kind below reaches only as far as its own setting.', 0, 1, 810),
('manage.requests.leave', 'manage.requests', 'Leave Requests', 'See leave requests.', 1, 1, 811),
('manage.requests.leave.decide', 'manage.requests.leave', 'Approve or reject', 'Approve or reject leave requests, with remarks.', 0, 1, 812),
('manage.requests.overtime', 'manage.requests', 'Overtime Requests', 'See overtime requests.', 1, 1, 813),
('manage.requests.overtime.decide', 'manage.requests.overtime', 'Approve or reject', 'Approve or reject overtime requests, with remarks.', 0, 1, 814),
('manage.requests.offset', 'manage.requests', 'Offset Requests', 'See offset requests.', 1, 1, 815),
('manage.requests.offset.decide', 'manage.requests.offset', 'Approve or reject', 'Approve or reject offset requests, with remarks.', 0, 1, 816),
('manage.requests.reimbursement', 'manage.requests', 'Reimbursement Requests', 'See reimbursement claims and their receipts.', 1, 1, 817),
('manage.requests.reimbursement.decide', 'manage.requests.reimbursement', 'Approve or reject', 'Approve or reject each claim line, with remarks.', 0, 1, 818),
('manage.requests.reimbursement.payout', 'manage.requests.reimbursement', 'Attach payout receipts', 'Attach the proof of payment to an approved claim.', 0, 1, 819),
('manage.requests.flagged', 'manage.requests', 'Flag Reports', 'See reports filed while their author was on a warning.', 1, 1, 820),
('manage.requests.flagged.decide', 'manage.requests.flagged', 'Approve or reject', 'Approve or reject a flagged report, with remarks.', 0, 1, 821),
('manage.requests.late', 'manage.requests', 'Late Reports', 'See reports filed after their day, and whether each was excused.', 1, 1, 822),
('manage.requests.late.decide', 'manage.requests.late', 'Approve or reject', 'Approve or reject a late report, with remarks.', 0, 1, 823),
('manage.requests.notified', 'manage.requests', 'Request alerts', 'Get an inbox alert when someone in {UserName|Your} reach files or changes a request.', 0, 1, 824),
('manage.requests.export', 'manage.requests', 'Export', 'Download the request queue.', 0, 1, 825),
('manage.reports', 'manage', 'Reports', 'Open the timesheets of the people in {UserName|Your} reach.', 1, 1, 830),
('manage.reports.export', 'manage.reports', 'Export', 'Download their reports.', 0, 1, 831),
('manage.reports.unlisted', 'manage.reports', 'Ignore white and black lists', 'Open everyone {UserName|Your} reach covers on Reports, whatever white or black list anyone set on it.', 0, 1, 832),
('manage.users', 'manage', 'Users', 'See the member roster.', 1, 1, 840),
('manage.users.role', 'manage.users', 'Change roles', 'Set a member''s role.', 0, 1, 841),
('manage.users.export', 'manage.users', 'Export', 'Download the roster.', 0, 1, 842),
('manage.users.access', 'manage.users', 'Access Rules', 'Open the Access Rules of the people in {UserName|Your} reach who sit below {UserName|Your} own role.', 1, 1, 843),
('manage.users.access.give', 'manage.users.access', 'Give powers', 'Switch a power on for someone: only one held here, never wider than {UserName|Your} own reach. It ends if that power is ever lost.', 0, 1, 844),
('manage.users.access.limit', 'manage.users.access', 'Limit powers', 'Switch a power off for someone, with everything under it, whoever gave it to them.', 0, 1, 845),

('administration', NULL, 'Administration', 'Keep the portal in order: logs, trash, emails and backups.', 0, 1, 900),
('administration.recycle', 'administration', 'All Recycle', 'See what the people in {UserName|Your} reach deleted. View only: restoring someone else''s item could backfire on them.', 1, 1, 910),
('administration.recycle.export', 'administration.recycle', 'Export', 'Download the recycle list.', 0, 1, 911),
('administration.recycle.unlisted', 'administration.recycle', 'Ignore white and black lists', 'See everyone {UserName|Your} reach covers on All Recycle, whatever white or black list anyone set on it.', 0, 1, 912),
('administration.logs', 'administration', 'All Logs', 'See what the people in {UserName|Your} reach did in the portal.', 1, 1, 920),
('administration.logs.export', 'administration.logs', 'Export', 'Download the logs.', 0, 1, 921),
('administration.actions', 'administration', 'All Actions', 'See every change the portal recorded for Airtable.', 1, 1, 930),
('administration.actions.sync', 'administration.actions', 'Sync to Airtable', 'Send the recorded changes to Airtable.', 0, 1, 931),
('administration.actions.export', 'administration.actions', 'Export', 'Download the actions.', 0, 1, 932),
('administration.missing', 'administration', 'Missing Reports', 'See who has not filed a report for a day.', 1, 1, 940),
('administration.missing.email', 'administration.missing', 'Email reminders', 'Email a reminder to one person or to everyone missing.', 0, 1, 941),
('administration.missing.mobile', 'administration.missing', 'Mobile reminders', 'Send a reminder to the mobile app.', 0, 1, 942),
('administration.missing.export', 'administration.missing', 'Export', 'Download the missing list.', 0, 1, 943),
('administration.email', 'administration', 'Send Email', 'Open the Send Email page.', 0, 1, 950),
('administration.email.send', 'administration.email', 'Send emails', 'Write and send an email to everyone, a department, a role or chosen members.', 0, 1, 951),
('administration.email.library', 'administration.email', 'Templates and footers', 'Create, edit and delete email templates and footers.', 0, 1, 952),
('administration.email.outbox', 'administration.email', 'Outbox', 'See every email that went out.', 0, 1, 953),
('administration.email.export', 'administration.email', 'Export', 'Download the outbox.', 0, 1, 954),
('administration.backup', 'administration', 'Backup', 'Open the Backup page.', 0, 1, 960),
('administration.backup.create', 'administration.backup', 'Create backups', 'Take a backup of the portal data.', 0, 1, 961),
('administration.backup.download', 'administration.backup', 'Download backups', 'Download a backup file.', 0, 1, 962),
('administration.backup.restore', 'administration.backup', 'Restore backups', 'Put the portal back to a backup.', 0, 1, 963),
('administration.backup.export', 'administration.backup', 'Export', 'Download the backup list.', 0, 1, 964),
('administration.alerts', 'administration', 'Access alerts', 'Get an email when someone is refused access to a page.', 0, 1, 970),

('workspace', NULL, 'Data Workspace', 'Open the admin spreadsheet of portal tables.', 0, 1, 1000),
('workspace.private', 'workspace', 'Private columns', 'Read private columns: tax and government numbers, bank details, address and birthday.', 0, 1, 1010),
('workspace.edit', 'workspace', 'Edit cells', 'Change values in the spreadsheet.', 0, 1, 1020),
('workspace.views', 'workspace', 'Shared views', 'Save and delete views everyone can use.', 0, 1, 1030);

-- Administrators and above only: an admin holding it manages fellow admins, and passes it only to them.
-- Developers only: held solely in Developer mode, so nobody can give them, not even another developer.
INSERT INTO access_permissions (permission_key, parent_key, label, description, supports_reach, can_toggle, min_tier, sort_order) VALUES
('manage.users.access.peers', 'manage.users.access', 'Manage other admins', 'Change the role and Access Rules of fellow administrators in {UserName|Your} reach, not only members. Only administrators can hold it.', 0, 1, 'admin', 846),
('administration.logs.developers', 'administration.logs', 'See developers'' activity', 'See what developers did in All Logs and All Actions, sign-ins included. What they did in Developer mode reads "a developer" to anyone not in Developer mode. Only executives and MIS administrators can hold it.', 0, 1, 'admin', 922),
('manage.users.information', 'manage.users', 'Show employee information', 'See the private details of the people in {UserName|Your} reach: birthday, address, contact, government and tax numbers, bank details and emergency contact.', 1, 1, 'admin', 848),
('developer', NULL, 'Developer', 'Tools for the people who build the portal.', 0, 1, 'developer', 1100),
('developer.rules', 'developer', 'Rule catalog', 'Change the rule list and what each role starts with.', 0, 1, 'developer', 1110),
('developer.flag', 'developer', 'Developer flag', 'Mark or unmark someone as a developer.', 0, 1, 'developer', 1120),
('developer.performance', 'developer', 'Performance page', 'Open the performance debug page.', 0, 1, 'developer', 1130);

-- Reports and All Recycle can be narrowed to chosen people with a white or black list (access_lists).
UPDATE access_permissions SET supports_list = 1 WHERE permission_key IN ('manage.reports', 'administration.recycle');

-- Managing fellow admins and changing roles are for administrators and above, and stay with MIS
-- among admins; executives hold them anywhere.
UPDATE access_permissions SET only_department = 'MIS', min_tier = 'admin' WHERE permission_key IN (
    'manage.users.access.peers', 'manage.users.role', 'administration.logs.developers',
    'administration.actions', 'administration.actions.sync', 'administration.actions.export'
);

-- Members: their own work only. View Projects and Track Projects show only the projects they are on.
INSERT INTO access_presets (role, permission_key, reach, include_executives)
SELECT 'member', permission_key, CASE WHEN permission_key IN ('projects.view', 'projects.track') THEN 'own' END, 0
FROM access_permissions
WHERE permission_key IN (
    'home',
    'projects', 'projects.view', 'projects.track', 'projects.track.export',
    'reports', 'reports.daily', 'reports.late', 'reports.submitted',
    'reports.submitted.edit', 'reports.submitted.delete', 'reports.submitted.export',
    'requests', 'requests.own', 'requests.own.edit', 'requests.own.cancel', 'requests.own.export',
    'requests.leave', 'requests.overtime', 'requests.offset', 'requests.reimbursement',
    'user', 'user.logs', 'user.logs.export', 'user.recycle', 'user.recycle.restore', 'user.recycle.export',
    'calendar', 'calendar.download',
    'access'
);

-- Admins: everything but the developer tools, Backup, the Airtable sync, Manage other admins and
-- Ignore white and black lists and Show employee information,
-- for their own department. Every project, as today, and every department on All Actions, which only
-- MIS admins hold.
INSERT INTO access_presets (role, permission_key, reach, include_executives)
SELECT 'admin', permission_key,
       CASE
           WHEN supports_reach = 0 THEN NULL
           WHEN permission_key IN ('projects.view', 'projects.track', 'administration.actions') THEN 'all'
           ELSE 'department'
       END,
       0
FROM access_permissions
WHERE permission_key NOT LIKE 'developer%'
  AND permission_key NOT LIKE 'administration.backup%'
  AND permission_key <> 'administration.actions.sync'
  AND permission_key <> 'manage.users.access.peers'
  AND permission_key <> 'manage.users.information'
  AND permission_key <> 'administration.logs.developers'
  AND permission_key NOT LIKE '%.unlisted';

-- Executives: every department and executives' logs, without the developer tools and without the
-- pages that are a member's own work (Home, Reports, Requests, User) or housekeeping (All Recycle,
-- All Actions, Backup). An executive who is a developer still opens those in Developer mode.
INSERT INTO access_presets (role, permission_key, reach, include_executives)
SELECT 'executive', permission_key,
       CASE WHEN supports_reach = 1 THEN 'all' END,
       permission_key = 'administration.logs'
FROM access_permissions
WHERE permission_key NOT LIKE 'developer%'
  AND permission_key NOT IN ('home', 'reports', 'requests', 'user', 'administration.recycle', 'administration.actions', 'administration.backup', 'administration.logs.developers')
  AND permission_key NOT LIKE 'reports.%'
  AND permission_key NOT LIKE 'requests.%'
  AND permission_key NOT LIKE 'user.%'
  AND permission_key NOT LIKE 'administration.recycle.%'
  AND permission_key NOT LIKE 'administration.actions.%'
  AND permission_key NOT LIKE 'administration.backup.%';

-- Developers: every rule, every department, and executives' logs.
INSERT INTO access_presets (role, permission_key, reach, include_executives)
SELECT 'developer', permission_key,
       CASE WHEN supports_reach = 1 THEN 'all' END,
       permission_key = 'administration.logs'
FROM access_permissions;

-- Personal defaults (access_personal), matched on id_no like is_developer.
INSERT INTO access_personal (employee_id, permission_key, reach)
SELECT id, 'manage.users.access.peers', NULL FROM employees WHERE id_no = '220202-0002'; -- Nathaniel Tanglao
INSERT INTO access_personal (employee_id, permission_key, reach)
SELECT id, 'administration.logs.developers', NULL FROM employees WHERE id_no = '220202-0002'; -- Nathaniel Tanglao
INSERT INTO access_personal (employee_id, permission_key, reach)
SELECT id, 'administration.actions.sync', NULL FROM employees WHERE id_no = '220202-0002'; -- Nathaniel Tanglao
INSERT INTO access_personal (employee_id, permission_key, reach)
SELECT id, 'manage.users.information', 'all' FROM employees WHERE id_no IN (
    '220202-0002', -- Nathaniel Tanglao
    '220302-0003'  -- Steven Gaurano
);
