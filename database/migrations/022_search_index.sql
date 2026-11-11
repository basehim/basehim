-- ============================================================================
-- 022 — BH Search: one index for every searchable source (1.2.92)
--
-- A copy of the searchable text of posts, pages, media, comments, users and
-- apps' own sources, with one FULLTEXT index, so search costs what matches
-- rather than what exists. No existing table is altered. The table starts
-- empty: rows are written in the background (scheduled task
-- core:search-index, or Settings → Search → Build now), and until a source is
-- complete its searches use its own table as before. Safe to repeat.
-- ============================================================================

CREATE TABLE IF NOT EXISTS {search_index} (
    `id`         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `source`     VARCHAR(100) NOT NULL,
    `object_id`  BIGINT UNSIGNED NOT NULL,
    `type`       VARCHAR(60) NOT NULL DEFAULT '',
    `status`     VARCHAR(30) NOT NULL DEFAULT '',
    `is_public`  TINYINT(1) NOT NULL DEFAULT 0,
    `owner_id`   BIGINT UNSIGNED NULL,
    `title`      VARCHAR(500) NOT NULL DEFAULT '',
    `body`       MEDIUMTEXT NULL,
    `keywords`   TEXT NULL,
    `url`        VARCHAR(500) NULL,
    `item_date`  DATETIME NULL,
    `gen`        INT UNSIGNED NOT NULL DEFAULT 0,
    `indexed_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_source_object` (`source`, `object_id`),
    KEY `idx_public` (`is_public`, `source`, `item_date`),
    KEY `idx_source_gen` (`source`, `gen`),
    FULLTEXT KEY `ft_text` (`title`, `body`, `keywords`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
