-- Existing portal databases (Bluehost phpMyAdmin or mysql client).
-- Fresh installs get idx_user_reports_date from schema.sql.
-- Skip if MySQL reports Duplicate key name 'idx_user_reports_date'.
-- employees_user_reports already has PRIMARY (employee_id, user_report_id)
-- plus a FOREIGN KEY index on user_report_id. Do not add a second copy.
-- logs / notifications indexes live on CREATE TABLE (schema.sql) and the Laravel migration.

CREATE INDEX idx_user_reports_date ON user_reports (report_date);

-- ============================================================================
-- Project soft delete. Run once against an existing portal database.
-- Fresh installs get the column from schema.sql; skip this if MySQL reports
-- Duplicate column name 'deleted_at'.
--
-- A column rather than removing the row: eight tables cascade from projects(id)
-- -- employees_projects, projects_clients, projects_project_scopes,
-- projects_user_reports and four more -- so a DELETE would take the memberships,
-- the client links, the scope assignments and the link to submitted reports with
-- it, and nothing in the recycle payload could put those back.
-- ============================================================================

ALTER TABLE projects ADD COLUMN deleted_at TIMESTAMP NULL;

CREATE INDEX idx_projects_deleted_at ON projects (deleted_at);
