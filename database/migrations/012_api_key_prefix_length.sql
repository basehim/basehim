-- 012_api_key_prefix_length.sql  (Basehim 1.2.43)
--
-- Safe to run more than once.
--
-- ApiKeyService stores the first 16 characters of a key ("basehim_" plus 8)
-- as its display prefix, but 002 made the column VARCHAR(10). Under MySQL or
-- MariaDB strict mode (the default since MySQL 5.7) every "Create API key"
-- failed with "Data too long for column 'key_prefix'", so a fresh install
-- could not issue keys at all. Lenient servers silently cut the prefix short.

ALTER TABLE {api_keys} MODIFY `key_prefix` VARCHAR(32) NOT NULL;
