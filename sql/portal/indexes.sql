-- Existing portal databases (Bluehost phpMyAdmin or mysql client).
-- Fresh installs get idx_user_reports_date from schema.sql.
-- Skip if MySQL reports Duplicate key name 'idx_user_reports_date'.
-- employees_user_reports already has PRIMARY (employee_id, user_report_id)
-- plus a FOREIGN KEY index on user_report_id. Do not add a second copy.

CREATE INDEX idx_user_reports_date ON user_reports (report_date);
