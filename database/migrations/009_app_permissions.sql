-- ============================================================================
-- Basehim Migration 009 — App permission grants and scan results
--
-- Adds the columns the permission broker and the static scanner need:
--
--   granted_permissions  what the operator approved (JSON array)
--   consented_at         when they approved it — also the "has consented"
--                        flag, since an empty grant list is a valid decision
--                        and cannot be distinguished from "never asked"
--   scan_result          cached AppScanner output, so the admin list does not
--                        re-scan every app on every page load
--   scanned_at           when that scan ran
--
-- Deliberately left NULL for every existing app. A NULL grant list plus an
-- empty declared list means "unrestricted", which is exactly how every app
-- installed before this release must keep behaving — see PermissionBroker.
--
-- Idempotent, since the runner only records a migration once the whole file
-- succeeds and a partial re-run must be harmless.
-- ============================================================================

SET @has_col = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{@apps}' AND COLUMN_NAME = 'granted_permissions'
);
SET @sql = IF(@has_col = 0,
    'ALTER TABLE {apps} ADD COLUMN `granted_permissions` TEXT NULL AFTER `permissions`',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{@apps}' AND COLUMN_NAME = 'consented_at'
);
SET @sql = IF(@has_col = 0,
    'ALTER TABLE {apps} ADD COLUMN `consented_at` DATETIME NULL AFTER `granted_permissions`',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{@apps}' AND COLUMN_NAME = 'scan_result'
);
SET @sql = IF(@has_col = 0,
    'ALTER TABLE {apps} ADD COLUMN `scan_result` TEXT NULL AFTER `consented_at`',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{@apps}' AND COLUMN_NAME = 'scanned_at'
);
SET @sql = IF(@has_col = 0,
    'ALTER TABLE {apps} ADD COLUMN `scanned_at` DATETIME NULL AFTER `scan_result`',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
