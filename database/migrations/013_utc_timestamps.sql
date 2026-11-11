-- 013_utc_timestamps.sql  (Basehim 1.2.45)
--
-- From 1.2.45 every database connection works in UTC (time_zone '+00:00'),
-- as PHP always has. Before, connections used the server's own zone.
--
-- TIMESTAMP columns are stored as instants and converted to the connection's
-- zone when read. The columns below are written by PHP with UTC strings,
-- which the old connection took as server-local. Read in UTC they would come
-- back shifted by the server's offset (a post published at 07:00 UTC on a
-- UTC+5 server would read 02:00). This puts each value back to what PHP wrote.
--
-- Columns filled by the database itself (created_at, updated_at defaults)
-- need nothing: they were true instants all along and now read correctly.
--
-- On a server whose zone is already UTC every CONVERT_TZ() is a no-op. Where
-- the zone can't be converted (a named zone without time-zone tables),
-- CONVERT_TZ() returns NULL and the value is left as it was. updated_at is
-- assigned to itself so ON UPDATE doesn't touch it.
--
-- Runs once, from the updater. A fresh install records it without running it
-- (its rows are UTC from the first one).
--
-- Safe to re-run (1.2.75, CR-07). The migration runner re-runs a migration that
-- failed part-way from its first statement, and each UPDATE below shifts its
-- column again every time it runs: a failure at, say, the api_keys step would
-- have moved published_at twice on the retry. Each step is now a transaction
-- that records itself in {migration_progress}, and a step already recorded
-- changes nothing. Sites that applied 013 before are unaffected: the runner
-- never runs an applied migration again.

CREATE TABLE IF NOT EXISTS {migration_progress} (
    `migration` VARCHAR(100) NOT NULL,
    `step`      VARCHAR(100) NOT NULL,
    `done_at`   DATETIME NOT NULL,
    PRIMARY KEY (`migration`, `step`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET time_zone = '+00:00';

-- Posts seeded by the installer got published_at from the database's NOW(),
-- at the same instant as created_at; those are already right and are skipped.
START TRANSACTION;
UPDATE {posts} SET `published_at` = COALESCE(CONVERT_TZ(`published_at`, '+00:00', @@global.time_zone), `published_at`), `updated_at` = `updated_at` WHERE `published_at` IS NOT NULL AND ABS(TIMESTAMPDIFF(SECOND, `published_at`, `created_at`)) > 2 AND NOT EXISTS (SELECT 1 FROM {migration_progress} mp WHERE mp.`migration` = '013_utc_timestamps' AND mp.`step` = 'posts.published_at');
INSERT IGNORE INTO {migration_progress} (`migration`, `step`, `done_at`) VALUES ('013_utc_timestamps', 'posts.published_at', UTC_TIMESTAMP());
COMMIT;

START TRANSACTION;
UPDATE {posts} SET `deleted_at` = COALESCE(CONVERT_TZ(`deleted_at`, '+00:00', @@global.time_zone), `deleted_at`), `updated_at` = `updated_at` WHERE `deleted_at` IS NOT NULL AND NOT EXISTS (SELECT 1 FROM {migration_progress} mp WHERE mp.`migration` = '013_utc_timestamps' AND mp.`step` = 'posts.deleted_at');
INSERT IGNORE INTO {migration_progress} (`migration`, `step`, `done_at`) VALUES ('013_utc_timestamps', 'posts.deleted_at', UTC_TIMESTAMP());
COMMIT;

START TRANSACTION;
UPDATE {users} SET `deleted_at` = COALESCE(CONVERT_TZ(`deleted_at`, '+00:00', @@global.time_zone), `deleted_at`), `updated_at` = `updated_at` WHERE `deleted_at` IS NOT NULL AND NOT EXISTS (SELECT 1 FROM {migration_progress} mp WHERE mp.`migration` = '013_utc_timestamps' AND mp.`step` = 'users.deleted_at');
INSERT IGNORE INTO {migration_progress} (`migration`, `step`, `done_at`) VALUES ('013_utc_timestamps', 'users.deleted_at', UTC_TIMESTAMP());
COMMIT;

START TRANSACTION;
UPDATE {users} SET `last_login_at` = COALESCE(CONVERT_TZ(`last_login_at`, '+00:00', @@global.time_zone), `last_login_at`), `updated_at` = `updated_at` WHERE `last_login_at` IS NOT NULL AND NOT EXISTS (SELECT 1 FROM {migration_progress} mp WHERE mp.`migration` = '013_utc_timestamps' AND mp.`step` = 'users.last_login_at');
INSERT IGNORE INTO {migration_progress} (`migration`, `step`, `done_at`) VALUES ('013_utc_timestamps', 'users.last_login_at', UTC_TIMESTAMP());
COMMIT;

START TRANSACTION;
UPDATE {api_keys} SET `last_used_at` = COALESCE(CONVERT_TZ(`last_used_at`, '+00:00', @@global.time_zone), `last_used_at`), `updated_at` = `updated_at` WHERE `last_used_at` IS NOT NULL AND NOT EXISTS (SELECT 1 FROM {migration_progress} mp WHERE mp.`migration` = '013_utc_timestamps' AND mp.`step` = 'api_keys.last_used_at');
INSERT IGNORE INTO {migration_progress} (`migration`, `step`, `done_at`) VALUES ('013_utc_timestamps', 'api_keys.last_used_at', UTC_TIMESTAMP());
COMMIT;

START TRANSACTION;
UPDATE {api_keys} SET `revoked_at` = COALESCE(CONVERT_TZ(`revoked_at`, '+00:00', @@global.time_zone), `revoked_at`), `updated_at` = `updated_at` WHERE `revoked_at` IS NOT NULL AND NOT EXISTS (SELECT 1 FROM {migration_progress} mp WHERE mp.`migration` = '013_utc_timestamps' AND mp.`step` = 'api_keys.revoked_at');
INSERT IGNORE INTO {migration_progress} (`migration`, `step`, `done_at`) VALUES ('013_utc_timestamps', 'api_keys.revoked_at', UTC_TIMESTAMP());
COMMIT;

START TRANSACTION;
UPDATE {api_keys} SET `expires_at` = COALESCE(CONVERT_TZ(`expires_at`, '+00:00', @@global.time_zone), `expires_at`), `updated_at` = `updated_at` WHERE `expires_at` IS NOT NULL AND NOT EXISTS (SELECT 1 FROM {migration_progress} mp WHERE mp.`migration` = '013_utc_timestamps' AND mp.`step` = 'api_keys.expires_at');
INSERT IGNORE INTO {migration_progress} (`migration`, `step`, `done_at`) VALUES ('013_utc_timestamps', 'api_keys.expires_at', UTC_TIMESTAMP());
COMMIT;

START TRANSACTION;
UPDATE {refresh_tokens} SET `used_at` = COALESCE(CONVERT_TZ(`used_at`, '+00:00', @@global.time_zone), `used_at`) WHERE `used_at` IS NOT NULL AND NOT EXISTS (SELECT 1 FROM {migration_progress} mp WHERE mp.`migration` = '013_utc_timestamps' AND mp.`step` = 'refresh_tokens.used_at');
INSERT IGNORE INTO {migration_progress} (`migration`, `step`, `done_at`) VALUES ('013_utc_timestamps', 'refresh_tokens.used_at', UTC_TIMESTAMP());
COMMIT;
