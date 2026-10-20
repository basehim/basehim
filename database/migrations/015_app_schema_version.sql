-- ============================================================================
-- 015 — apps.schema_version (1.2.73, BH-024)
--
-- `version` is the version of an app's files. `schema_version` is the version
-- its data was migrated to: it moves only after the app's onUpgrade()
-- succeeds, so a failed upgrade is retried instead of being recorded as done.
-- '' means onInstall() has not completed yet. Existing rows start equal to
-- their recorded version. AppService adds the column itself if new files run
-- before this migration; both paths are safe to repeat.
-- ============================================================================

SET @has_col = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{@apps}' AND COLUMN_NAME = 'schema_version'
);
SET @sql = IF(@has_col = 0,
    'ALTER TABLE {apps} ADD COLUMN `schema_version` VARCHAR(30) NULL AFTER `version`',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE {apps} SET `schema_version` = `version` WHERE `schema_version` IS NULL;
