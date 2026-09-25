-- 010_root_pages_default_category.sql  (Basehim 1.2.7)
--
-- Every statement is idempotent: this file may run more than once, because the
-- updater and the System page record migrations under different names.

-- 1. Menu items saved while pages lived at /page/{slug} point at /{slug} now.
--    The old address still redirects; this saves visitors the extra hop.
UPDATE {menu_items} SET `url` = SUBSTRING(`url`, 6) WHERE `url` LIKE '/page/%';

-- 2. An "Uncategorized" category, for any install that has lost it.
INSERT INTO {terms} (`taxonomy_id`, `name`, `slug`, `count`)
SELECT x.`id`, 'Uncategorized', 'uncategorized', 0
  FROM {taxonomies} x
 WHERE x.`slug` = 'category'
   AND NOT EXISTS (SELECT 1 FROM {terms} t WHERE t.`taxonomy_id` = x.`id` AND t.`slug` = 'uncategorized');

-- 3. File every post that has no category under Uncategorized, so it gets a
--    category URL. Trashed posts too, so a restored post is filed as well.
INSERT INTO {post_term} (`post_id`, `term_id`, `term_order`)
SELECT p.`id`, u.`id`, 0
  FROM {posts} p
  JOIN {taxonomies} ux ON ux.`slug` = 'category'
  JOIN {terms} u ON u.`taxonomy_id` = ux.`id` AND u.`slug` = 'uncategorized'
 WHERE p.`type` = 'post'
   AND NOT EXISTS (
        SELECT 1 FROM {post_term} pt
          JOIN {terms} t ON t.`id` = pt.`term_id`
          JOIN {taxonomies} x ON x.`id` = t.`taxonomy_id` AND x.`slug` = 'category'
         WHERE pt.`post_id` = p.`id`);

-- 4. Every category and tag count, recomputed. Before 1.2.7 a permanent delete
--    never lowered the counts of the deleted post's terms, so every emptied
--    Trash left them too high; this also covers step 3's additions.
UPDATE {terms} t
   SET t.`count` = (SELECT COUNT(*) FROM {post_term} pt WHERE pt.`term_id` = t.`id`);
