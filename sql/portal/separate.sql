USE test_portal_database;

-- ============================================================================
-- PORTAL AUDIT — user tracking and in-app notifications
-- logs: every member action the portal backend records (no tokens / secrets).
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
-- Existing databases: the Laravel migration create_portal_email_tables.
-- ============================================================================

CREATE TABLE email_blocks (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    kind         VARCHAR(16)  NOT NULL,
    name         VARCHAR(191) NOT NULL,
    category     VARCHAR(32)  NULL,
    subject      VARCHAR(255) NULL,
    body         TEXT         NOT NULL,
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
    footer_id       INT          NULL,
    footer_body     TEXT         NULL,
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

