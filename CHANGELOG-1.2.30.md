# Basehim 1.2.30 — Table prefix (DB_PREFIX) on new installs

Patch for 1.2.29. Three migration files change, for fresh installs only:
existing sites have already applied them and run no new migration.

## The fault

`DB_PREFIX` was supported by `Database`, the migration runners and app tables —
but the installer never used it:

- The database step had no prefix field, so `$cfg['DB_PREFIX']` was always
  empty and every table was created unprefixed.
- The `.env` written at the end had no `DB_PREFIX` line. Setting it in `.env`
  before installing, as the README says, was overwritten.

Behind that, three faults would have broken a prefixed install anyway:

- **Foreign keys** in `001_initial_schema` and `002_api_keys` pointed at bare
  names (`REFERENCES `users``), so creating `bh_media` failed: the table it
  references is `bh_users`.
- **Constraint names** (`fk_media_author` …) must be unique per database, so a
  second site in a shared database failed with a duplicate constraint name.
  They now carry the prefix (`bh_fk_media_author`).
- **Migration 007** renamed legacy app settings in a bare `settings` table, so
  a prefixed fresh install stopped at 007 with "table doesn't exist".
- **MCP OAuth and "remember me"** created and queried `mcp_oauth_*` and
  `auth_remember_tokens` without the prefix, while inserts went to the
  prefixed name — both broken on a prefixed site.

## Changes

- Installer, database step: **Table Prefix** field (letters, numbers, `_`, up
  to 32), prefilled from `DB_PREFIX` in an existing `.env`. Written to the new
  `.env`. The schema step lists the real, prefixed table names.
- Installer: refuses to install over Basehim tables that already exist with the
  chosen prefix (the schema step drops them) unless **Replace existing tables**
  is ticked — in a shared database that used to delete another site.
- Migrations 001, 002: `REFERENCES {table}` and `CONSTRAINT `{@fk_…}``;
  007: `UPDATE IGNORE {settings}`.
- `McpOAuthService`, `AuthSecurityService`: `{table}` in every query.
- System page: table sizes list only this site's tables.
- New `database/add-prefix.php` (command line): gives an existing unprefixed
  install a prefix — renames core and app tables in one `RENAME TABLE`, then
  sets `DB_PREFIX` in `.env` (old copy in `storage/backups/`). Dry run unless
  `--apply`.

Existing sites keep working unchanged: an empty prefix still means bare names.

## Files

    index.php, install.php                        version 1.2.30; installer prefix
    database/migrations/001_initial_schema.sql    prefixed FK targets + names
    database/migrations/002_api_keys.sql          prefixed FK target + name
    database/migrations/007_apps.sql              {settings}
    app/Services/McpOAuthService.php              {table}
    app/Services/AuthSecurityService.php          {table}
    app/Services/SystemInfoService.php            this site's tables only
    database/add-prefix.php                       new