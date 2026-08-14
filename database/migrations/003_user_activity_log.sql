-- ----------------------------------------------------------------------------
-- Per-user activity / audit log (also auto-created on first use by
-- App\Services\ActivityLogService for existing installs).
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS {user_activity_log} (
    `id`          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`     BIGINT UNSIGNED NULL,
    `event`       VARCHAR(80) NOT NULL,
    `object_type` VARCHAR(40) NULL,
    `object_id`   BIGINT UNSIGNED NULL,
    `detail`      VARCHAR(500) NULL,
    `ip`          VARCHAR(45) NULL,
    `user_agent`  VARCHAR(255) NULL,
    `created_at`  DATETIME NOT NULL,
    KEY `idx_ual_user` (`user_id`, `id`),
    KEY `idx_ual_event` (`event`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
