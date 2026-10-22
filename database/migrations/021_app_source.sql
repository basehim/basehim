-- ============================================================================
-- 021 — apps.source (1.2.85)
--
-- How an app was installed: 'upload' (a ZIP on the Apps screen),
-- 'marketplace', 'devtools' (registered by Basehim Dev Tools), or 'legacy'
-- (registered before 1.2.85, by any route). Since 1.2.85 a folder copied into
-- content/apps by hand is not registered, listed or loaded. AppService adds
-- the column itself if new files run before this migration; both paths are
-- safe to repeat.
-- ============================================================================

SET @has_col = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{@apps}' AND COLUMN_NAME = 'source'
);
SET @sql = IF(@has_col = 0,
    'ALTER TABLE {apps} ADD COLUMN `source` VARCHAR(20) NULL AFTER `status`',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE {apps} SET `source` = 'legacy' WHERE `source` IS NULL;
