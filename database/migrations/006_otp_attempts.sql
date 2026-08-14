-- 006_otp_attempts.sql
--
-- Adds a guess counter to the login-attempt tracker so the emailed one-time
-- code (a 6-digit number) can't be brute-forced within its 10-minute window.
-- verifyOtp() caps wrong guesses and burns the code once the cap is hit.
--
-- The table is created on first use by AuthSecurityService::ensureSchema(), so
-- it may not exist yet on a brand-new install — hence IF NOT EXISTS on both.
CREATE TABLE IF NOT EXISTS {auth_login_attempts} (
    `id`            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `identifier`    VARCHAR(190) NOT NULL,
    `ip`            VARCHAR(45) NOT NULL,
    `fails`         INT UNSIGNED NOT NULL DEFAULT 0,
    `captcha_fails` INT UNSIGNED NOT NULL DEFAULT 0,
    `otp_hash`      VARCHAR(255) NULL,
    `otp_expires_at` DATETIME NULL,
    `otp_user_id`   BIGINT UNSIGNED NULL,
    `otp_attempts`  INT UNSIGNED NOT NULL DEFAULT 0,
    `locked_until`  DATETIME NULL,
    `updated_at`    DATETIME NOT NULL,
    UNIQUE KEY `uniq_ident_ip` (`identifier`, `ip`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE {auth_login_attempts} ADD COLUMN IF NOT EXISTS `otp_attempts` INT UNSIGNED NOT NULL DEFAULT 0;
