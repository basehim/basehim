-- ============================================================================
-- 020 — buffered page-view counts (1.2.76, CR-23)
--
-- A page view used to UPDATE the post's own row, so a popular post's row was
-- locked by every visitor at once. Views are now added to small counters,
-- one per post, minute and shard, and a core task adds them to
-- posts.view_count every minute. PostViewCounter creates the table itself if
-- new files run before this migration. Safe to repeat.
-- ============================================================================

CREATE TABLE IF NOT EXISTS {post_views} (
    `post_id` BIGINT UNSIGNED NOT NULL,
    `bucket`  INT UNSIGNED NOT NULL,
    `shard`   TINYINT UNSIGNED NOT NULL,
    `hits`    INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`post_id`, `bucket`, `shard`),
    KEY `idx_bucket` (`bucket`)
) ENGINE=InnoDB DEFAULT CHARSET=ascii;
