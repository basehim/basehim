-- ============================================================================
-- Basehim API Keys Table (v1.1.0)
-- ============================================================================

CREATE TABLE IF NOT EXISTS {api_keys} (
    `id`            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `uuid`          CHAR(36) NOT NULL UNIQUE,
    `user_id`       BIGINT UNSIGNED NOT NULL,
    `name`          VARCHAR(100) NOT NULL,
    `key_prefix`    VARCHAR(10) NOT NULL COMMENT 'First 8 chars for display (basehim_XXXX)',
    `key_hash`      VARCHAR(255) NOT NULL COMMENT 'SHA-256 hash of full key',
    `scopes`        JSON NOT NULL DEFAULT ('[]') COMMENT 'Array of allowed scopes',
    `rate_limit`    SMALLINT UNSIGNED NOT NULL DEFAULT 1000 COMMENT 'Requests per hour',
    `last_used_at`  TIMESTAMP NULL,
    `last_used_ip`  VARCHAR(45) NULL,
    `expires_at`    TIMESTAMP NULL COMMENT 'NULL = never expires',
    `is_active`     TINYINT(1) NOT NULL DEFAULT 1,
    `revoked_at`    TIMESTAMP NULL,
    `created_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_user`      (`user_id`),
    KEY `idx_key_hash`  (`key_hash`),
    KEY `idx_active`    (`is_active`),
    CONSTRAINT `fk_apikey_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
