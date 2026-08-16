# Basehim 1.0.1 — the migration runner

The System page's **Run migrations** button had never worked. Pressing it gave:

    SQLSTATE[42000]: Syntax error ... near '{migrations} (`id` INT UNSIGNED ...'

## What was wrong

Three runners apply migrations: `install.php`, `database/migrate.php`, and the
System page. All three talk to PDO directly, so `Database::query()` — which
normally expands `{table}` tokens — is not in the path. Each has to expand them
itself.

`install.php` did, through its own `pxSql()`. `UpdateService` did, through
`Database::expand()`. **`SystemController` and `database/migrate.php` did not**,
so MySQL received the literal string `{migrations}` and rejected it.

`Database::applyPrefix()` carries a docblock describing this exact failure —
"they would send `{migrations}` to MySQL verbatim and fail on a syntax error,
which is exactly what happened the first time this was wired up". Two of the
four call sites were written without it anyway.

Both now expand every statement, including the contents of each migration file.
The CLI expansion was checked against `Database::applyPrefix()` and produces
byte-identical output, with and without a table prefix.

## A second bug, found in the same place

The runners disagreed about how to name a migration in the tracking table.
`migrate.php` recorded `001_initial_schema.sql`; the others recorded
`001_initial_schema`. A migration applied by one therefore looked pending to the
other.

On a real install this had already happened — eleven migrations recorded twice,
under both spellings. Harmless in itself, because every migration is written to
be idempotent, but it is why the System page reported work outstanding on a
database that was fully migrated.

`migrate.php` now records the same key as the other two: the basename with the
`.sql` extension stripped.

### If your migrations table has duplicates

Nothing needs doing. The duplicate rows are inert, and the runners now agree, so
no further duplicates will appear. Removing the ones ending in `.sql` is safe if
you would rather the table read cleanly:

    DELETE FROM migrations WHERE migration LIKE '%.sql';

Take a backup first, as with any manual delete.
