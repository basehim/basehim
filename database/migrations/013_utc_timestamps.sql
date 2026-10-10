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

SET time_zone = '+00:00';

-- Posts seeded by the installer got published_at from the database's NOW(),
-- at the same instant as created_at; those are already right and are skipped.
UPDATE {posts} SET `published_at` = COALESCE(CONVERT_TZ(`published_at`, '+00:00', @@global.time_zone), `published_at`), `updated_at` = `updated_at` WHERE `published_at` IS NOT NULL AND ABS(TIMESTAMPDIFF(SECOND, `published_at`, `created_at`)) > 2;
UPDATE {posts} SET `deleted_at` = COALESCE(CONVERT_TZ(`deleted_at`, '+00:00', @@global.time_zone), `deleted_at`), `updated_at` = `updated_at` WHERE `deleted_at` IS NOT NULL;
UPDATE {users} SET `deleted_at` = COALESCE(CONVERT_TZ(`deleted_at`, '+00:00', @@global.time_zone), `deleted_at`), `updated_at` = `updated_at` WHERE `deleted_at` IS NOT NULL;
UPDATE {users} SET `last_login_at` = COALESCE(CONVERT_TZ(`last_login_at`, '+00:00', @@global.time_zone), `last_login_at`), `updated_at` = `updated_at` WHERE `last_login_at` IS NOT NULL;
UPDATE {api_keys} SET `last_used_at` = COALESCE(CONVERT_TZ(`last_used_at`, '+00:00', @@global.time_zone), `last_used_at`), `updated_at` = `updated_at` WHERE `last_used_at` IS NOT NULL;
UPDATE {api_keys} SET `revoked_at` = COALESCE(CONVERT_TZ(`revoked_at`, '+00:00', @@global.time_zone), `revoked_at`), `updated_at` = `updated_at` WHERE `revoked_at` IS NOT NULL;
UPDATE {api_keys} SET `expires_at` = COALESCE(CONVERT_TZ(`expires_at`, '+00:00', @@global.time_zone), `expires_at`), `updated_at` = `updated_at` WHERE `expires_at` IS NOT NULL;
UPDATE {refresh_tokens} SET `used_at` = COALESCE(CONVERT_TZ(`used_at`, '+00:00', @@global.time_zone), `used_at`) WHERE `used_at` IS NOT NULL;
