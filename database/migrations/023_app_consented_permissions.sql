-- ============================================================================
-- Basehim Migration 023 — apps.consented_permissions (1.2.103)
--
-- Records what an app DECLARED at the moment the operator consented, so that a
-- later upgrade which adds or removes a permission is noticed. Until now the
-- "needs review" check compared the declared list against granted ∪ withheld,
-- which always equals the declared list, so a widened upgrade was never caught;
-- and an upgrade that dropped the permissions array entirely made the app
-- unrestricted without anyone being asked.
--
-- Backfill: for apps already consented to, the best available baseline is the
-- permissions they currently declare — recorded here so existing installs are
-- not nagged on upgrade to 1.2.103. From the next consent onward the value is
-- written exactly (PermissionBroker::grant).
--
-- Idempotent: the column is only added when missing, and the backfill only
-- touches rows that have consent but no baseline yet.
-- ============================================================================

SET @has_col = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{@apps}' AND COLUMN_NAME = 'consented_permissions'
);
SET @sql = IF(@has_col = 0,
    'ALTER TABLE {apps} ADD COLUMN `consented_permissions` TEXT NULL AFTER `granted_permissions`',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE {apps}
   SET `consented_permissions` = `permissions`
 WHERE `consented_at` IS NOT NULL
   AND `consented_permissions` IS NULL;
