-- ============================================================================
-- Transaction Tracker — Airtable base converted to a MySQL schema
-- Source base ID: appp5MogesHN2eVvG
-- Generated data: migrations/generate/generate_sql_transaction_tracker.py
-- ============================================================================
--
-- DESIGN NOTES
--
-- 1. Same conventions as sql/portal/schema.sql: every table gets a surrogate
--    `id INT AUTO_INCREMENT PRIMARY KEY` plus `airtable_record_id VARCHAR(20)
--    UNIQUE` (the join key for a later push-back sync); multipleRecordLinks
--    become junction tables; attachments go in one shared `attachments` table.
--
-- 2. Foreign keys are declared as explicit table-level `FOREIGN KEY (...)
--    REFERENCES ...` clauses, NOT the inline `col INT REFERENCES tbl(id)`
--    shorthand. That shorthand is valid Postgres and parses silently in MySQL
--    while creating no constraint at all — sql/project_estimator/schema.sql
--    still uses it and enforces zero foreign keys as a result. Do not copy
--    that file's style into this one.
--
-- 3. Computed fields are NOT stored. Airtable rollup/formula/lookup fields
--    (Current Balance, Remaining Funds, Remaining Budget, Total Expense /
--    Income Transaction Amount, Account Name lookups, Budget Code Name,
--    Actual Credited, Credit Status) are derived from the rows below and are
--    recomputed by whatever reads them, never migrated.
--
-- 4. `payor_payee` the TABLE and `transactions.payor_payee` the COLUMN are not
--    the same thing. The Transactions field named "Payor/Payee" is plain text
--    in Airtable, not a link, so it gets a VARCHAR column and no foreign key.
--    Whether that free text is meant to resolve to rows in the payor_payee
--    table by name is unconfirmed — do not add a FK on the assumption that it
--    does. `payor_payee.associated_transactions_text` is the mirror image of
--    the same trap: also plain text, also not a link.
--
-- 5. Transactions link to Accounts through THREE separate Airtable fields —
--    "Account", "Transfer Source Account" and "Transfer Destination Account" —
--    so they get three junction tables rather than one with a role column.
--    Source and destination were both empty at export time; the tables exist
--    because the fields do.
--
-- 6. Envelopes - Budget links to Transactions in Airtable, but that side is
--    redundant: transactions_envelopes already records the same relationship
--    from the Transactions side, and rule 7 of the portal schema (one owning
--    layer per value) applies here too. Only the Transactions side is stored.
-- ============================================================================

DROP DATABASE IF EXISTS test_tracker_database;
CREATE DATABASE test_tracker_database;
USE test_tracker_database;

-- ============================================================================
-- SHARED / SUPPORT TABLES
-- ============================================================================

-- Polymorphic by (table_name, record_id, field_name) -- deliberately no FK, the
-- same shape sql/portal/schema.sql uses. file_url holds a real Cloudinary URL
-- once upload_attachments_to_cloudinary.py has run, or the local downloaded
-- path as a fallback. Never the Airtable URL: those are signed and expire.
CREATE TABLE attachments (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    table_name      VARCHAR(100) NOT NULL,
    record_id       INT          NOT NULL,
    field_name      VARCHAR(100) NOT NULL,
    file_url        VARCHAR(1000),
    file_name       VARCHAR(500),
    file_size_bytes BIGINT,
    mime_type       VARCHAR(150),
    INDEX idx_tt_attachments_owner (table_name, record_id, field_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ============================================================================
-- 1. ACCOUNTS  (Airtable table: "Accounts" / tbluWKBN7zWuPMjON)
-- Attachment field: QRCode
-- Computed, not stored: Remaining Funds, Total Income, Total Expense
-- ============================================================================

CREATE TABLE accounts (
    id                 INT AUTO_INCREMENT PRIMARY KEY,
    airtable_record_id VARCHAR(20) UNIQUE,
    account_name       VARCHAR(255),
    account_type       VARCHAR(100),   -- singleSelect
    email              VARCHAR(255),
    institution        VARCHAR(255),
    active             BOOLEAN NOT NULL DEFAULT FALSE,
    creation_status    VARCHAR(100),   -- singleSelect
    created_at         DATETIME,
    updated_at         DATETIME
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ============================================================================
-- 2. BUDGET CODES  (Airtable table: "Budget Code" / tblhCLVztUsaZJpN8)
-- Attachment field: Attachments
-- Computed, not stored: Actual Credited, Credit Status
-- ============================================================================

CREATE TABLE budget_codes (
    id                        INT AUTO_INCREMENT PRIMARY KEY,
    airtable_record_id        VARCHAR(20) UNIQUE,
    name                      VARCHAR(255),
    description               TEXT,           -- Airtable "Desc"; free text, embedded newlines
    transfer_date_received    DATE,
    expected_amount_quotation DECIMAL(15, 2),
    attachment_summary        TEXT            -- aiText
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ============================================================================
-- 3. PAYOR / PAYEE  (Airtable table: "Payor/Payee" / tblt4gtJjG7MtzV7y)
-- No attachments and no real links -- this table stands alone. See note 4.
-- ============================================================================

CREATE TABLE payor_payee (
    id                           INT AUTO_INCREMENT PRIMARY KEY,
    airtable_record_id           VARCHAR(20) UNIQUE,
    name                         VARCHAR(255),
    type                         VARCHAR(100),   -- singleSelect
    contact_name                 VARCHAR(255),
    email                        VARCHAR(255),
    phone                        VARCHAR(50),
    associated_transactions_text TEXT,           -- plain text, not a link; runs past 450 chars
    company                      VARCHAR(255),
    notes                        TEXT,
    business_lookup              TEXT,           -- aiText
    expense_plan                 TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ============================================================================
-- 4. ENVELOPES - BUDGET  (Airtable table: "Envelopes - Budget" / tblbeCM0N32nUcQe0)
-- Computed, not stored: Total Expense / Income Transaction Amount, Remaining Budget
-- ============================================================================

CREATE TABLE envelopes_budget (
    id                 INT AUTO_INCREMENT PRIMARY KEY,
    airtable_record_id VARCHAR(20) UNIQUE,
    envelope_name      VARCHAR(255),
    budget_amount      DECIMAL(15, 2),
    group_name         VARCHAR(100),   -- Airtable "Group"; GROUP is a reserved word in MySQL
    time_period        VARCHAR(50),    -- singleSelect
    active             BOOLEAN NOT NULL DEFAULT FALSE,
    budget_notes       TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ============================================================================
-- 5. TRANSACTIONS  (Airtable table: "Transactions" / tblKpuFbcyc6YfmH1)
-- Attachment fields: Receipt, Attachments
-- Computed, not stored: Current Balance, Account Name lookups, Budget Code Name
-- ============================================================================

CREATE TABLE transactions (
    id                     INT AUTO_INCREMENT PRIMARY KEY,
    airtable_record_id     VARCHAR(20) UNIQUE,
    transaction_name       VARCHAR(255),
    transaction_date       DATETIME,
    amount                 DECIMAL(15, 2),
    type                   VARCHAR(50),    -- singleSelect: Expense / Income / Transfer
    payor_payee            VARCHAR(255),   -- plain text, NOT a link to payor_payee -- see note 4
    description            TEXT,
    reviewed_by_email      VARCHAR(255),   -- collaborator, stored as the email alone
    approval_date          DATETIME,
    created_by             VARCHAR(255),
    auto_extracted_details TEXT,           -- aiText
    created_at             DATETIME,
    updated_at             DATETIME,
    INDEX idx_tt_transactions_date (transaction_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ============================================================================
-- JUNCTION TABLES  (one per multipleRecordLinks field)
-- ============================================================================

CREATE TABLE envelopes_accounts (
    envelope_id INT,
    account_id  INT,
    PRIMARY KEY (envelope_id, account_id),
    FOREIGN KEY (envelope_id) REFERENCES envelopes_budget(id) ON DELETE CASCADE,
    FOREIGN KEY (account_id)  REFERENCES accounts(id)         ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE envelopes_budget_codes (
    envelope_id    INT,
    budget_code_id INT,
    PRIMARY KEY (envelope_id, budget_code_id),
    FOREIGN KEY (envelope_id)    REFERENCES envelopes_budget(id) ON DELETE CASCADE,
    FOREIGN KEY (budget_code_id) REFERENCES budget_codes(id)     ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE transactions_accounts (
    transaction_id INT,
    account_id     INT,
    PRIMARY KEY (transaction_id, account_id),
    FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE CASCADE,
    FOREIGN KEY (account_id)     REFERENCES accounts(id)     ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE transactions_envelopes (
    transaction_id INT,
    envelope_id    INT,
    PRIMARY KEY (transaction_id, envelope_id),
    FOREIGN KEY (transaction_id) REFERENCES transactions(id)     ON DELETE CASCADE,
    FOREIGN KEY (envelope_id)    REFERENCES envelopes_budget(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE transactions_budget_codes (
    transaction_id INT,
    budget_code_id INT,
    PRIMARY KEY (transaction_id, budget_code_id),
    FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE CASCADE,
    FOREIGN KEY (budget_code_id) REFERENCES budget_codes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- A transfer names both ends, so these stay separate from transactions_accounts
-- above: that one is the transaction's own account, these two are the movement.
CREATE TABLE transactions_transfer_source_accounts (
    transaction_id INT,
    account_id     INT,
    PRIMARY KEY (transaction_id, account_id),
    FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE CASCADE,
    FOREIGN KEY (account_id)     REFERENCES accounts(id)     ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE transactions_transfer_destination_accounts (
    transaction_id INT,
    account_id     INT,
    PRIMARY KEY (transaction_id, account_id),
    FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE CASCADE,
    FOREIGN KEY (account_id)     REFERENCES accounts(id)     ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
