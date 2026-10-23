-- ============================================================================
-- data-test.sql -- local corrections applied after data.sql.
-- This file is NOT part of the Airtable snapshot; reset.ps1 runs it after
-- data.sql so local testing keeps these fixes. It is not a production file.
-- ============================================================================

USE test_portal_database;

START TRANSACTION;

-- John Dave Pega's local test account: use the production-style ID number and
-- the work email Microsoft returns, so the exact-email fallback can match him.
UPDATE employees
SET id_no = '260720-0020',
    email = 'jdmpega@ikaikabim.com'
WHERE id_no IN ('260701-0020', '260720-0020')
  AND first_name = 'John Dave'
  AND last_name = 'Pega';

COMMIT;
