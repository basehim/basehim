-- ============================================================================
-- 016 — scheduled task leases and attempts (1.2.75, CR-12)
--
-- locked_until: a run claims its task until then, so two runners cannot both
-- start it; a run killed by the time or memory limit frees it when the lease
-- expires. attempts: runs started since the last success, which sets how long
-- a failing task waits before its next try. Safe to repeat.
-- ============================================================================

SET @has_col = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{@scheduled_tasks}' AND COLUMN_NAME = 'locked_until'
);
SET @sql = IF(@has_col = 0,
    'ALTER TABLE {scheduled_tasks} ADD COLUMN `locked_until` DATETIME NULL AFTER `next_run_at`',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_col = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{@scheduled_tasks}' AND COLUMN_NAME = 'attempts'
);
SET @sql = IF(@has_col = 0,
    'ALTER TABLE {scheduled_tasks} ADD COLUMN `attempts` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `failures`',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
