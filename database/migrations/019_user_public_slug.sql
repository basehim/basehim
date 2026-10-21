-- ============================================================================
-- 019 — users.public_slug (1.2.76, CR-19)
--
-- An author's public address (/author/{slug}) lived only inside users.meta
-- (JSON), so finding one meant scanning every user, and a miss rebuilt the
-- slugs of every author. It now has its own column with a unique index.
-- Existing slugs are copied from meta; if two users somehow had the same one,
-- the second keeps none and gets a new one the next time it is needed.
-- AuthorService adds the column itself if new files run before this
-- migration. Safe to repeat.
-- ============================================================================

SET @has_col = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{@users}' AND COLUMN_NAME = 'public_slug'
);
SET @sql = IF(@has_col = 0,
    'ALTER TABLE {users} ADD COLUMN `public_slug` VARCHAR(191) NULL, ADD UNIQUE KEY `uq_public_slug` (`public_slug`)',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE IGNORE {users}
   SET `public_slug` = CASE WHEN JSON_VALID(`meta`)
                            THEN NULLIF(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(`meta`, '$.author_slug')), ''), 'null') END
 WHERE `public_slug` IS NULL AND `meta` LIKE '%author_slug%';
