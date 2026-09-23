-- ============================================================================
-- Core platform — shared recycle bin and write-action log
-- MySQL 8.0 / MariaDB 10.x, InnoDB, utf8mb4.
-- ============================================================================
--
-- Local:   CORE_DB_DATABASE=ikaika_platform  (this file)
-- Bluehost: CORE_DB_DATABASE=eoxvhumy_ikaika_platform
--           Skip CREATE DATABASE / USE below and run the CREATE TABLE block
--           against that schema, or `php artisan migrate --force`.
--
-- Do NOT DROP this database. Laravel's cache/jobs tables may already live here.
-- ============================================================================

CREATE DATABASE IF NOT EXISTS ikaika_platform
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE ikaika_platform;

-- ============================================================================
-- SETTINGS
-- Per-product knobs. recycle.retain_days is how long a recycled row stays
-- before a later purge job may delete it.
-- ============================================================================

CREATE TABLE IF NOT EXISTS settings (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    product        VARCHAR(64)  NOT NULL,
    setting_key    VARCHAR(100) NOT NULL,
    setting_value  TEXT         NOT NULL,
    UNIQUE KEY uniq_settings_product_key (product, setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO settings (product, setting_key, setting_value) VALUES
    ('portal', 'recycle.retain_days', '30'),
    ('project-estimator', 'recycle.retain_days', '30');


-- ============================================================================
-- ACTIONS
-- Every database write (add / edit / delete) — never a fetch. Airtable (or
-- anything else) syncs from this table. `product` is portal vs
-- project-estimator vs future products; never mix them on one row.
-- ============================================================================

CREATE TABLE IF NOT EXISTS actions (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    product           VARCHAR(64)  NOT NULL,
    database_target   VARCHAR(191) NOT NULL,
    action_type       VARCHAR(32)  NOT NULL,
    resource          VARCHAR(191) NULL,
    record_id         VARCHAR(191) NULL,
    recycle_key       VARCHAR(191) NULL,
    actor_id          INT          NULL,
    actor_id_no       VARCHAR(100) NULL,
    parameters        JSON         NOT NULL,
    synced_at         TIMESTAMP    NULL,
    created_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_actions_product_created (product, created_at),
    INDEX idx_actions_product_type (product, action_type),
    INDEX idx_actions_product_key (product, recycle_key),
    INDEX idx_actions_unsynced (synced_at, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ============================================================================
-- RECYCLE
-- Snapshot of a deleted record. `product` separates portal trash from
-- estimator trash. Unique per (product, recycle_key): deleting a portal
-- daily report for 2026-08-24 does not occupy the estimator's 2026-08-24.
-- An add with the same product + key removes this row — the live record
-- already replaced it.
-- ============================================================================

CREATE TABLE IF NOT EXISTS recycle (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    product           VARCHAR(64)  NOT NULL,
    recycle_key       VARCHAR(191) NOT NULL,
    database_target   VARCHAR(191) NOT NULL,
    resource          VARCHAR(191) NULL,
    record_id         VARCHAR(191) NOT NULL,
    payload           JSON         NOT NULL,
    deleted_by        INT          NULL,
    deleted_by_id_no  VARCHAR(100) NULL,
    purges_at         TIMESTAMP    NOT NULL,
    created_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_recycle_product_key (product, recycle_key),
    INDEX idx_recycle_product_purge (product, purges_at),
    INDEX idx_recycle_product_deleted_by (product, deleted_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
