-- 014_rate_limits.sql  (Basehim, AJAX gateway)
--
-- Safe to run more than once.
--
-- Counters for App\Services\RateLimiter: the AJAX gateway's global and
-- per-action limits, and its idempotency-key claims. Each row is one key's
-- count for one window; `k` is a SHA-256 hex digest, so no client address or
-- user ID is stored in readable form. `expires_at` is a Unix timestamp, which
-- keeps the arithmetic free of time zones. Expired rows are swept as the
-- table is used.
--
-- RateLimiter also creates this table on first use, so a site that has not
-- run migrations yet still gets working limits.

CREATE TABLE IF NOT EXISTS {rate_limits} (
    `k`          CHAR(64) NOT NULL PRIMARY KEY,
    `hits`       INT UNSIGNED NOT NULL DEFAULT 0,
    `expires_at` INT UNSIGNED NOT NULL,
    KEY `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=ascii COLLATE=ascii_bin;
