-- Existing transaction tracker databases (phpMyAdmin or mysql client), run once.
-- Fresh installs get this from sql/transaction_tracker/schema.sql.
-- Skip if MySQL reports Table 'account_fund_transfers' already exists.
--
-- The fund transfers Finance / Accounts lists: one is left pending when an expense is paid from one
-- account out of an envelope another account owns, and completing it writes the account transfer.
-- The portal writes it; Airtable has no table for it.

USE test_tracker_database;

CREATE TABLE account_fund_transfers (
    id                         INT AUTO_INCREMENT PRIMARY KEY,
    source_account_id          INT NULL,
    destination_account_id     INT NULL,
    amount                     DECIMAL(15, 2) NOT NULL,
    reason                     TEXT,
    status                     VARCHAR(20) NOT NULL DEFAULT 'Pending',  -- Pending / Completed / Cancelled
    origin_transaction_id      INT NULL,       -- the expense that left it owed
    created_by                 VARCHAR(255),
    created_by_email           VARCHAR(255),
    created_at                 DATETIME,
    settled_by                 VARCHAR(255) NULL,
    settled_by_email           VARCHAR(255) NULL,
    settled_at                 DATETIME NULL,
    source_transaction_id      INT NULL,       -- the account transfer completing it wrote
    destination_transaction_id INT NULL,
    INDEX idx_tt_fund_transfers_status (status),
    FOREIGN KEY (source_account_id)          REFERENCES accounts(id)     ON DELETE SET NULL,
    FOREIGN KEY (destination_account_id)     REFERENCES accounts(id)     ON DELETE SET NULL,
    FOREIGN KEY (origin_transaction_id)      REFERENCES transactions(id) ON DELETE SET NULL,
    FOREIGN KEY (source_transaction_id)      REFERENCES transactions(id) ON DELETE SET NULL,
    FOREIGN KEY (destination_transaction_id) REFERENCES transactions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
