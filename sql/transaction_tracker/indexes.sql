-- Existing transaction tracker databases (phpMyAdmin or mysql client), run once.
-- Fresh installs get this from migrations/outputs/transaction_tracker/schema.sql.
-- Skip if MySQL reports Duplicate column name 'source_key'.
--
-- source_key says where a row written by another system came from -- the portal writes
-- portal:reimbursement-claim:{id} when an approved reimbursement is charged to an envelope. It is
-- unique so one claim can never be charged twice, even when two approvers save at the same moment,
-- and it is how an un-approved claim's charge is found again and removed.
--
-- The schema source this retrofits, sql/transaction_tracker/schema.sql, is not in the repository;
-- whoever regenerates the outputs with migrations/migrate.ps1 needs this column added there too,
-- or the next regeneration drops it.

USE test_tracker_database;

ALTER TABLE transactions ADD COLUMN source_key VARCHAR(191) NULL;

CREATE UNIQUE INDEX uniq_tt_transactions_source_key ON transactions (source_key);
