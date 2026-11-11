-- ============================================================================
-- Basehim Migration 008 — Scheduled tasks
--
-- Backing store for SchedulerService: one row per (app, task), holding the
-- interval, when it next comes due, and the outcome of the last run.
--
-- Handlers themselves are NOT stored. A callable can't be serialised
-- meaningfully, and storing a class/method name would let a row name any
-- callable in the codebase — a row edit would become arbitrary code execution.
-- Apps re-register their handlers in boot() on every request instead; a task
-- whose handler isn't registered is skipped, never fired blindly.
-- ============================================================================

CREATE TABLE IF NOT EXISTS {scheduled_tasks} (
    `id`               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `app_slug`         VARCHAR(200) NOT NULL,
    `task_key`         VARCHAR(100) NOT NULL,
    `interval_seconds` INT UNSIGNED NOT NULL DEFAULT 3600,
    `next_run_at`      DATETIME NOT NULL,
    `last_run_at`      DATETIME NULL,
    `last_status`      ENUM('ok','error') NULL,
    `last_output`      VARCHAR(500) NULL,
    `last_duration`    DECIMAL(8,3) NULL,
    `runs`             INT UNSIGNED NOT NULL DEFAULT 0,
    `failures`         INT UNSIGNED NOT NULL DEFAULT 0,
    `created_at`       DATETIME NOT NULL,
    `updated_at`       TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- One row per task per app. register() runs on every request, so this is
    -- what keeps it an upsert rather than an ever-growing pile of duplicates.
    UNIQUE KEY `uq_app_task` (`app_slug`, `task_key`),

    -- The sweep query is "due, oldest first" on every qualifying request, so
    -- it gets its own index rather than scanning the table each time.
    KEY `idx_next_run` (`next_run_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
