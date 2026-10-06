-- 011_migration_keys_and_tags.sql  (Basehim 1.2.11)
--
-- Every statement is safe to run more than once.

-- ── 1. One row per migration, named without ".sql" ─────────────────────────
--
-- The installer recorded "005_fix_post_status.sql"; the updater and the
-- System page recorded "005_fix_post_status". Neither recognised the other,
-- so the first update after an install ran every migration again, and each
-- one ended up recorded twice.

-- A ".sql" row whose plain twin exists is a duplicate.
DELETE a FROM {migrations} a
  JOIN {migrations} b ON b.`migration` = LEFT(a.`migration`, CHAR_LENGTH(a.`migration`) - 4)
 WHERE a.`migration` LIKE '%.sql';

-- A ".sql" row with no twin: the installer's record of a migration no runner
-- has seen. Rename it, so the runners recognise it and do not run it again.
UPDATE {migrations}
   SET `migration` = LEFT(`migration`, CHAR_LENGTH(`migration`) - 4)
 WHERE `migration` LIKE '%.sql';

-- Any exact duplicates left: keep the first.
DELETE a FROM {migrations} a
  JOIN {migrations} b ON b.`migration` = a.`migration` AND b.`id` < a.`id`;

-- Then make a second record impossible.
SET @bh_idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{@migrations}' AND INDEX_NAME = 'uq_migration');
-- "DO 0", not "SELECT 1": a statement that returns rows blocks the next one.
SET @bh_sql := IF(@bh_idx = 0,
                  'ALTER TABLE {migrations} ADD UNIQUE KEY `uq_migration` (`migration`)',
                  'DO 0');
PREPARE bh_stmt FROM @bh_sql;
EXECUTE bh_stmt;
DEALLOCATE PREPARE bh_stmt;

-- ── 2. One Tags taxonomy: "tag" ────────────────────────────────────────────
--
-- Migration 001 creates "tag", which the editor, /tag/ archives, the widget
-- and the sitemap all use. The installer then also created "post_tag", so
-- every install had a second Tags taxonomy whose tags never appeared
-- anywhere. Fold it into "tag".

-- No "tag" at all: "post_tag" simply becomes it.
UPDATE {taxonomies} SET `slug` = 'tag'
 WHERE `slug` = 'post_tag'
   AND NOT EXISTS (SELECT 1 FROM (SELECT `id` FROM {taxonomies} WHERE `slug` = 'tag') AS t);

-- A tag present in both: move its posts onto the "tag" one...
INSERT IGNORE INTO {post_term} (`post_id`, `term_id`, `term_order`)
SELECT pt.`post_id`, t2.`id`, pt.`term_order`
  FROM {post_term} pt
  JOIN {terms} t1      ON t1.`id` = pt.`term_id`
  JOIN {taxonomies} x1 ON x1.`id` = t1.`taxonomy_id` AND x1.`slug` = 'post_tag'
  JOIN {taxonomies} x2 ON x2.`slug` = 'tag'
  JOIN {terms} t2      ON t2.`taxonomy_id` = x2.`id` AND t2.`slug` = t1.`slug`;

-- ...then drop the "post_tag" copy and its links.
DELETE pt FROM {post_term} pt
  JOIN {terms} t1      ON t1.`id` = pt.`term_id`
  JOIN {taxonomies} x1 ON x1.`id` = t1.`taxonomy_id` AND x1.`slug` = 'post_tag'
  JOIN {taxonomies} x2 ON x2.`slug` = 'tag'
  JOIN {terms} t2      ON t2.`taxonomy_id` = x2.`id` AND t2.`slug` = t1.`slug`;
DELETE t1 FROM {terms} t1
  JOIN {taxonomies} x1 ON x1.`id` = t1.`taxonomy_id` AND x1.`slug` = 'post_tag'
  JOIN {taxonomies} x2 ON x2.`slug` = 'tag'
  JOIN {terms} t2      ON t2.`taxonomy_id` = x2.`id` AND t2.`slug` = t1.`slug`;

-- Every other "post_tag" tag moves across as it is.
UPDATE {terms} t
  JOIN {taxonomies} x1 ON x1.`id` = t.`taxonomy_id` AND x1.`slug` = 'post_tag'
  JOIN {taxonomies} x2 ON x2.`slug` = 'tag'
   SET t.`taxonomy_id` = x2.`id`, t.`parent_id` = NULL;

-- "post_tag" is now empty: remove it.
DELETE x FROM {taxonomies} x
 WHERE x.`slug` = 'post_tag'
   AND NOT EXISTS (SELECT 1 FROM {terms} t WHERE t.`taxonomy_id` = x.`id`);

-- Counts for every term, after the moves.
UPDATE {terms} t
   SET t.`count` = (SELECT COUNT(*) FROM {post_term} pt WHERE pt.`term_id` = t.`id`);
