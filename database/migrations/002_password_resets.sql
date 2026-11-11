-- ----------------------------------------------------------------------------
-- Password reset tokens (also auto-created on first use by
-- App\Services\PasswordResetService for existing installs).
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS {password_resets} (
    `id`         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `email`      VARCHAR(255) NOT NULL,
    `token_hash` CHAR(64) NOT NULL,
    `expires_at` DATETIME NOT NULL,
    `used_at`    DATETIME NULL,
    `created_at` DATETIME NOT NULL,
    KEY `idx_pr_email` (`email`),
    KEY `idx_pr_token` (`token_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
