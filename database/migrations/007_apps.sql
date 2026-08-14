-- ============================================================================
-- Basehim Migration 007 — Plugins become Apps
--
-- Renames the `plugins` table to {apps}, adds the `icon` and `permissions`
-- columns, and moves per-plugin settings from the "plugin:{slug}" group to
-- "app:{slug}".
--
-- Every statement is idempotent: the migration can be re-run safely, which
-- matters because the runner only records a migration after the whole file
-- succeeds. Conditional DDL is done with PREPARE/EXECUTE against
-- information_schema, since MySQL has no "ALTER TABLE ... ADD COLUMN IF NOT
-- EXISTS" before 8.0.
--
-- Deliberately NOT done here:
--   * Capability strings (access_plugin:{slug}) live inside JSON blobs in the
--     settings table. Rewriting JSON from SQL is fragile, so compatibility is
--     handled in PHP instead: AccessControl accepts access_app:{slug} and
--     access_plugin:{slug} as equivalent, forever.
--   * App-owned tables (plugin_{slug}_*) are left untouched. They belong to
--     the app author, not core; App::renameLegacyTable() lets each app move
--     its own tables when it is ready.
-- ============================================================================

-- ----------------------------------------------------------------------------
-- 1. plugins -> apps
--    Only when `apps` does not already exist and `plugins` is a real table.
-- ----------------------------------------------------------------------------
SET @has_apps = (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{@apps}'
);
SET @has_plugins = (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{@plugins}'
      AND TABLE_TYPE = 'BASE TABLE'
);
SET @sql = IF(@has_apps = 0 AND @has_plugins = 1,
    'RENAME TABLE {plugins} TO {apps}',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ----------------------------------------------------------------------------
-- 2. Safety net — create `apps` outright if neither table existed
--    (a fresh install that somehow skipped 001, or a partially-restored DB).
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS {apps} (
    `id`           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `vendor`       VARCHAR(100),
    `slug`         VARCHAR(200) NOT NULL UNIQUE,
    `name`         VARCHAR(255) NOT NULL,
    `description`  TEXT,
    `version`      VARCHAR(30) NOT NULL,
    `author`       VARCHAR(255),
    `status`       ENUM('active','inactive','error') DEFAULT 'inactive',
    `config`       JSON NULL,
    `activated_at` TIMESTAMP NULL,
    `created_at`   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at`   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 3. `icon` — manifest icon (FA class, heroicon:name, or bundled file path)
-- ----------------------------------------------------------------------------
SET @has_col = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{@apps}' AND COLUMN_NAME = 'icon'
);
SET @sql = IF(@has_col = 0,
    'ALTER TABLE {apps} ADD COLUMN `icon` VARCHAR(190) NULL AFTER `author`',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ----------------------------------------------------------------------------
-- 4. `permissions` — JSON array declared in the manifest.
--    Recorded now, enforced by the permission broker in a later release.
--    Stored as TEXT rather than JSON so MariaDB 10.1 hosts stay supported.
-- ----------------------------------------------------------------------------
SET @has_col = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{@apps}' AND COLUMN_NAME = 'permissions'
);
SET @sql = IF(@has_col = 0,
    'ALTER TABLE {apps} ADD COLUMN `permissions` TEXT NULL AFTER `icon`',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ----------------------------------------------------------------------------
-- 5. Settings: "plugin:{slug}" -> "app:{slug}"
--
--    UPDATE IGNORE skips any row that would collide with an already-migrated
--    "app:{slug}" key (uq_group_key), leaving the legacy row in place rather
--    than failing the migration. App::getSetting() reads the legacy group as a
--    fallback, so a skipped row is still visible to the app either way.
-- ----------------------------------------------------------------------------
UPDATE IGNORE `settings`
   SET `setting_group` = CONCAT('app:', SUBSTRING(`setting_group`, 8))
 WHERE `setting_group` LIKE 'plugin:%';
