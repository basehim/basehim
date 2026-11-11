-- ============================================================================
-- 017 — idempotency keys (1.2.75, CR-27)
--
-- A request sent with an Idempotency-Key runs once; a repeat with the same key
-- gets the stored answer (App\Services\IdempotencyStore). Keys expire after a
-- day. The store also creates this table on first use. Safe to repeat.
-- ============================================================================

CREATE TABLE IF NOT EXISTS {idempotency_keys} (
    `k`             CHAR(64) NOT NULL PRIMARY KEY,
    `request_hash`  CHAR(64) NOT NULL,
    `status`        VARCHAR(10) NOT NULL DEFAULT 'running',
    `response_code` SMALLINT UNSIGNED NULL,
    `response_headers` TEXT NULL,
    `response_body` MEDIUMTEXT NULL,
    `created_at`    INT UNSIGNED NOT NULL,
    `expires_at`    INT UNSIGNED NOT NULL,
    KEY `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
