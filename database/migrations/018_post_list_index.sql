-- ============================================================================
-- 018 — published lists sorted by an index (1.2.76, CR-17)
--
-- Lists of published content now sort on (published_at, id) instead of
-- COALESCE(published_at, created_at), which no index can serve. Published
-- rows have always been given a published_at; any that were not (imported,
-- or written by hand) get their creation time, which is what the old sort
-- showed for them. idx_list covers the list filter and the sort. Safe to
-- repeat.
-- ============================================================================

UPDATE {posts} SET `published_at` = `created_at`, `updated_at` = `updated_at`
 WHERE `status` = 'published' AND `published_at` IS NULL;

SET @has_idx = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{@posts}' AND INDEX_NAME = 'idx_list'
);
SET @sql = IF(@has_idx = 0,
    'ALTER TABLE {posts} ADD KEY `idx_list` (`type`, `status`, `deleted_at`, `published_at`, `id`)',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
