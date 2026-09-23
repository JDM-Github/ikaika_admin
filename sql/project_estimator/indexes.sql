-- Existing estimator databases (Bluehost phpMyAdmin or mysql client).
-- Fresh installs get idx_pe_holidays_date from schema.sql.
-- Skip if the holidays table is missing, or if MySQL reports Duplicate key name.

CREATE INDEX idx_pe_holidays_date ON holidays (holiday_date);
