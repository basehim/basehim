-- ============================================================================
-- Basehim Database Schema (v1.0.0)
-- MySQL 5.7+ / 8.0+ compatible
-- Run automatically by install.php
-- ============================================================================

SET FOREIGN_KEY_CHECKS = 0;
SET NAMES utf8mb4;

-- ----------------------------------------------------------------------------
-- Users
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS {users} (
    `id`            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `uuid`          CHAR(36) NOT NULL UNIQUE,
    `username`      VARCHAR(60) NOT NULL,
    `email`         VARCHAR(255) NOT NULL,
    `password_hash` VARCHAR(255) NOT NULL,
    `display_name`  VARCHAR(250),
    `bio`           TEXT,
    `role`          VARCHAR(60) NOT NULL DEFAULT 'subscriber',
    `status`        ENUM('active','inactive','suspended') DEFAULT 'active',
    `locale`        VARCHAR(20) DEFAULT 'en_US',
    `timezone`      VARCHAR(50) DEFAULT 'UTC',
    `avatar_media_id` BIGINT UNSIGNED NULL,
    `meta`          JSON NULL,
    `email_verified_at` TIMESTAMP NULL,
    `last_login_at` TIMESTAMP NULL,
    `created_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at`    TIMESTAMP NULL,
    UNIQUE KEY `uq_email` (`email`),
    UNIQUE KEY `uq_username` (`username`),
    KEY `idx_role` (`role`),
    KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Media
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS {media} (
    `id`            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `uuid`          CHAR(36) NOT NULL UNIQUE,
    `author_id`     BIGINT UNSIGNED NOT NULL,
    `post_id`       BIGINT UNSIGNED NULL,
    `title`         VARCHAR(255),
    `alt_text`      VARCHAR(255),
    `caption`       TEXT,
    `description`   TEXT,
    `mime_type`     VARCHAR(100) NOT NULL,
    `file_name`     VARCHAR(255) NOT NULL,
    `original_name` VARCHAR(255),
    `file_size`     INT UNSIGNED NOT NULL,
    `width`         SMALLINT UNSIGNED NULL,
    `height`        SMALLINT UNSIGNED NULL,
    `storage_disk`  VARCHAR(30) DEFAULT 'local',
    `storage_path`  VARCHAR(500) NOT NULL,
    `url`           VARCHAR(500),
    `sizes`         JSON NULL,
    `created_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at`    TIMESTAMP NULL,
    KEY `idx_author` (`author_id`),
    KEY `idx_mime` (`mime_type`),
    KEY `idx_created` (`created_at`),
    CONSTRAINT `fk_media_author` FOREIGN KEY (`author_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Posts (all content types: post, page, attachment, CPTs)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS {posts} (
    `id`              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `uuid`            CHAR(36) NOT NULL UNIQUE,
    `parent_id`       BIGINT UNSIGNED NULL,
    `author_id`       BIGINT UNSIGNED NOT NULL,
    `featured_media_id` BIGINT UNSIGNED NULL,
    `type`            VARCHAR(60) NOT NULL DEFAULT 'post',
    `status`          VARCHAR(20) NOT NULL DEFAULT 'draft',
    `slug`            VARCHAR(200) NOT NULL,
    `title`           TEXT NOT NULL,
    `content`         LONGTEXT,
    `content_format`  ENUM('html','blocks','markdown') DEFAULT 'html',
    `excerpt`         TEXT,
    `comment_status`  ENUM('open','closed') DEFAULT 'open',
    `menu_order`      INT DEFAULT 0,
    `view_count`      INT UNSIGNED DEFAULT 0,
    `published_at`    TIMESTAMP NULL,
    `created_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at`      TIMESTAMP NULL,
    UNIQUE KEY `uq_type_slug` (`type`, `slug`),
    KEY `idx_type_status` (`type`, `status`),
    KEY `idx_author` (`author_id`),
    KEY `idx_published` (`published_at`),
    KEY `idx_parent` (`parent_id`),
    FULLTEXT KEY `idx_ft_content` (`title`, `content`, `excerpt`),
    CONSTRAINT `fk_posts_author` FOREIGN KEY (`author_id`) REFERENCES `users` (`id`),
    CONSTRAINT `fk_posts_parent` FOREIGN KEY (`parent_id`) REFERENCES `posts` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_posts_featured` FOREIGN KEY (`featured_media_id`) REFERENCES `media` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Post meta
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS {post_meta} (
    `id`         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `post_id`    BIGINT UNSIGNED NOT NULL,
    `meta_key`   VARCHAR(255) NOT NULL,
    `meta_value` LONGTEXT,
    `is_json`    TINYINT(1) DEFAULT 0,
    KEY `idx_post_key` (`post_id`, `meta_key`),
    KEY `idx_key` (`meta_key`),
    CONSTRAINT `fk_post_meta_post` FOREIGN KEY (`post_id`) REFERENCES `posts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Post revisions
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS {post_revisions} (
    `id`              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `post_id`         BIGINT UNSIGNED NOT NULL,
    `author_id`       BIGINT UNSIGNED NOT NULL,
    `title`           TEXT,
    `content`         LONGTEXT,
    `excerpt`         TEXT,
    `revision_number` INT UNSIGNED DEFAULT 1,
    `created_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_post_revisions` (`post_id`, `revision_number`),
    CONSTRAINT `fk_revisions_post` FOREIGN KEY (`post_id`) REFERENCES `posts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Taxonomies
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS {taxonomies} (
    `id`           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `slug`         VARCHAR(60) NOT NULL UNIQUE,
    `label`        VARCHAR(255) NOT NULL,
    `singular`     VARCHAR(255) NOT NULL,
    `hierarchical` TINYINT(1) DEFAULT 0,
    `show_in_api`  TINYINT(1) DEFAULT 1,
    `post_types`   JSON,
    `created_at`   TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Terms
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS {terms} (
    `id`          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `taxonomy_id` BIGINT UNSIGNED NOT NULL,
    `parent_id`   BIGINT UNSIGNED NULL,
    `name`        VARCHAR(200) NOT NULL,
    `slug`        VARCHAR(200) NOT NULL,
    `description` TEXT,
    `meta`        JSON NULL,
    `term_order`  INT DEFAULT 0,
    `count`       INT UNSIGNED DEFAULT 0,
    `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_taxonomy_slug` (`taxonomy_id`, `slug`),
    KEY `idx_parent` (`parent_id`),
    CONSTRAINT `fk_terms_taxonomy` FOREIGN KEY (`taxonomy_id`) REFERENCES `taxonomies` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_terms_parent` FOREIGN KEY (`parent_id`) REFERENCES `terms` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Post ↔ Term pivot
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS {post_term} (
    `post_id`    BIGINT UNSIGNED NOT NULL,
    `term_id`    BIGINT UNSIGNED NOT NULL,
    `term_order` INT DEFAULT 0,
    PRIMARY KEY (`post_id`, `term_id`),
    KEY `idx_term` (`term_id`),
    CONSTRAINT `fk_pt_post` FOREIGN KEY (`post_id`) REFERENCES `posts` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_pt_term` FOREIGN KEY (`term_id`) REFERENCES `terms` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Comments
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS {comments} (
    `id`           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `post_id`      BIGINT UNSIGNED NOT NULL,
    `parent_id`    BIGINT UNSIGNED NULL,
    `author_id`    BIGINT UNSIGNED NULL,
    `author_name`  VARCHAR(100),
    `author_email` VARCHAR(255),
    `author_url`   VARCHAR(200),
    `author_ip`    VARCHAR(45),
    `content`      TEXT NOT NULL,
    `status`       ENUM('approved','pending','spam','trash') DEFAULT 'pending',
    `karma`        INT DEFAULT 0,
    `user_agent`   VARCHAR(500),
    `created_at`   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at`   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_post_status` (`post_id`, `status`),
    KEY `idx_parent` (`parent_id`),
    CONSTRAINT `fk_comments_post` FOREIGN KEY (`post_id`) REFERENCES `posts` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_comments_parent` FOREIGN KEY (`parent_id`) REFERENCES `comments` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_comments_author` FOREIGN KEY (`author_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Settings
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS {settings} (
    `id`            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `setting_group` VARCHAR(100) NOT NULL DEFAULT 'general',
    `setting_key`   VARCHAR(191) NOT NULL,
    `setting_value` LONGTEXT,
    `is_json`       TINYINT(1) DEFAULT 0,
    `autoload`      TINYINT(1) DEFAULT 1,
    `created_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_group_key` (`setting_group`, `setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- SEO meta
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS {seo_meta} (
    `id`              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `post_id`         BIGINT UNSIGNED NOT NULL UNIQUE,
    `meta_title`      VARCHAR(160),
    `meta_description` VARCHAR(320),
    `og_title`        VARCHAR(200),
    `og_description`  VARCHAR(400),
    `og_image_id`     BIGINT UNSIGNED NULL,
    `canonical_url`   VARCHAR(500),
    `robots`          VARCHAR(100) DEFAULT 'index,follow',
    `schema_markup`   JSON NULL,
    `focus_keyword`   VARCHAR(200),
    `created_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_seo_post` FOREIGN KEY (`post_id`) REFERENCES `posts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Menus
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS {menus} (
    `id`         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name`       VARCHAR(200) NOT NULL,
    `slug`       VARCHAR(200) NOT NULL UNIQUE,
    `location`   VARCHAR(100),
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {menu_items} (
    `id`         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `menu_id`    BIGINT UNSIGNED NOT NULL,
    `parent_id`  BIGINT UNSIGNED NULL,
    `type`       ENUM('post','page','taxonomy','custom','archive') DEFAULT 'custom',
    `object_id`  BIGINT UNSIGNED NULL,
    `title`      VARCHAR(200),
    `url`        VARCHAR(500),
    `target`     VARCHAR(20) DEFAULT '_self',
    `icon`       VARCHAR(100),
    `classes`    VARCHAR(200),
    `menu_order` INT DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_menu_order` (`menu_id`, `menu_order`),
    KEY `idx_mi_parent` (`parent_id`),
    CONSTRAINT `fk_mi_menu` FOREIGN KEY (`menu_id`) REFERENCES `menus` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_mi_parent` FOREIGN KEY (`parent_id`) REFERENCES `menu_items` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- App registry
--
-- Was `plugins` before 1.43.0. Migration 007 renames an existing `plugins`
-- table to `apps`, which is what an upgraded site gets; a fresh install starts
-- here and 007's rename simply finds nothing to do.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS {apps} (
    `id`           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `vendor`       VARCHAR(100),
    `slug`         VARCHAR(200) NOT NULL UNIQUE,
    `name`         VARCHAR(255) NOT NULL,
    `description`  TEXT,
    `version`      VARCHAR(30) NOT NULL,
    `author`       VARCHAR(255),
    `status`       ENUM('active','inactive','error') DEFAULT 'inactive',
    `config`       JSON NULL,
    `activated_at` TIMESTAMP NULL,
    `created_at`   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at`   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Refresh tokens (JWT)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS {refresh_tokens} (
    `id`         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`    BIGINT UNSIGNED NOT NULL,
    `token_hash` VARCHAR(255) NOT NULL,
    `family`     CHAR(36) NOT NULL,
    `used_at`    TIMESTAMP NULL,
    -- DATETIME, not TIMESTAMP. MariaDB gives the first TIMESTAMP NOT NULL column
    -- in a table an implicit CURRENT_TIMESTAMP default; any later one gets
    -- '0000-00-00 00:00:00', which NO_ZERO_DATE rejects outright — so this
    -- CREATE failed on a default MariaDB install. `used_at` above already took
    -- the first slot. DATETIME has no implicit default at all, and every INSERT
    -- sets this column explicitly.
    `expires_at` DATETIME NOT NULL,
    `ip_address` VARCHAR(45),
    `user_agent` VARCHAR(500),
    `revoked_at` TIMESTAMP NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_token_hash` (`token_hash`),
    KEY `idx_user` (`user_id`),
    CONSTRAINT `fk_rt_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Notifications
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS {notifications} (
    `id`         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`    BIGINT UNSIGNED NOT NULL,
    `type`       VARCHAR(60) NOT NULL,
    `title`      VARCHAR(255) NOT NULL,
    `body`       TEXT,
    `link`       VARCHAR(500),
    `icon`       VARCHAR(100),
    `read_at`    TIMESTAMP NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_user_read` (`user_id`, `read_at`),
    CONSTRAINT `fk_notif_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Activity log
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS {activity_log} (
    `id`          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`     BIGINT UNSIGNED NULL,
    `action`      VARCHAR(100) NOT NULL,
    `entity_type` VARCHAR(60),
    `entity_id`   BIGINT UNSIGNED NULL,
    `description` TEXT,
    `ip_address`  VARCHAR(45),
    `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_user` (`user_id`),
    KEY `idx_action` (`action`),
    KEY `idx_entity` (`entity_type`, `entity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================================
-- Seed data
-- ============================================================================

-- Built-in taxonomies
INSERT IGNORE INTO {taxonomies} (`slug`, `label`, `singular`, `hierarchical`, `show_in_api`, `post_types`) VALUES
('category', 'Categories', 'Category', 1, 1, '["post"]'),
('tag', 'Tags', 'Tag', 0, 1, '["post"]');

-- Default category
INSERT IGNORE INTO {terms} (`taxonomy_id`, `name`, `slug`, `description`)
SELECT id, 'Uncategorized', 'uncategorized', 'Default category for posts'
FROM {taxonomies} WHERE `slug` = 'category';

-- Default settings
INSERT IGNORE INTO {settings} (`setting_group`, `setting_key`, `setting_value`, `is_json`, `autoload`) VALUES
('general', 'site_title',  'Basehim',                 0, 1),
('general', 'tagline',     'A Modern API-First CMS',  0, 1),
('general', 'admin_email', 'admin@example.com',       0, 1),
('general', 'date_format', 'F j, Y',                  0, 1),
('general', 'time_format', 'g:i a',                   0, 1),
('general', 'timezone',    'UTC',                     0, 1),
('general', 'language',    'en_US',                   0, 1),
('reading', 'posts_per_page', '10',                   0, 1),
('reading', 'show_on_front',  'posts',                0, 1),
('writing', 'default_post_status', 'draft',           0, 1),
('writing', 'default_comment_status', 'open',         0, 1),
('discussion', 'require_name_email',     '1',         0, 1),
('discussion', 'moderate_comments',      '1',         0, 1),
('discussion', 'allow_threaded_comments','1',         0, 1),
('seo', 'meta_title_separator', '|',                  0, 1),
('seo', 'default_meta_description', '',               0, 1),
('seo', 'enable_sitemap', '1',                        0, 1),
('seo', 'robots_txt', "User-agent: *\nAllow: /\nDisallow: /admin/",  0, 1),
('appearance', 'active_theme', 'default',             0, 1),
('appearance', 'primary_color', '#2563eb',            0, 1),
('permalinks', 'structure', 'pretty',                 0, 1);

-- Default menu
INSERT IGNORE INTO {menus} (`name`, `slug`, `location`) VALUES
('Main Menu', 'main-menu', 'primary'),
('Footer Menu', 'footer-menu', 'footer');
