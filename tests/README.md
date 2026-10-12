# Tests

No Composer, no PHPUnit — the runner and its assertions are a few hundred
lines in `tests/support/`, in keeping with Basehim's no-dependency rule.

```bash
php tests/run.php                  # unit tests (no database needed)
php tests/run.php --filter=csrf    # only tests whose name contains "csrf"
php tests/run.php --keep           # keep the test database and logs afterwards
```

## Integration tests

These drive the real `index.php` over HTTP (PHP's built-in server, eight
workers), with real sessions, the real login form and a real database. They
run when a test database server is configured:

```bash
BASEHIM_TEST_DB_HOST=127.0.0.1 \
BASEHIM_TEST_DB_USER=root BASEHIM_TEST_DB_PASS=secret \
php tests/run.php
```

| Variable | Default | |
|---|---|---|
| `BASEHIM_TEST_DB_HOST` | — | Required to enable integration tests |
| `BASEHIM_TEST_DB_PORT` | `3306` | |
| `BASEHIM_TEST_DB_USER` / `_PASS` | `root` / empty | |
| `BASEHIM_TEST_DB_NAME` | `basehim_test` | Must end in `_test`: it is **dropped and recreated** |
| `BASEHIM_TEST_DB_PREFIX` | `bht_` | Exercises table prefixing |

What the run does:

1. Creates the database and applies every migration with Basehim's own
   `MigrationService`.
2. Seeds users of each role and a few media rows.
3. Copies `tests/fixtures/apps/ajax-fixture` into `content/apps/` and marks it
   active (it is removed afterwards; a folder of that name without the test
   marker file is never touched).
4. Boots the application in the runner process, for tests that call services
   directly.
5. Serves the site from `tests/support/router.php`, which loads a generated
   test `.env` first. Your own `.env` is never read.

The JavaScript client tests (`tests/js/ajax-client.test.js`) run the real
`admin/assets/js/ajax.js` in Node 18+ against that server. Without Node they
are skipped.

Logs written during a run go to `storage/logs/` as usual.

### Database servers

MariaDB is what CI uses. MySQL 8.0 cannot apply migration 006 (`ADD COLUMN IF
NOT EXISTS` is MariaDB-only syntax) — a pre-existing issue, not one the tests
introduce.

`BASEHIM_TEST_DB_COMPAT=dolt` lets the suite run against
[Dolt](https://github.com/dolthub/dolt), a MySQL-compatible server that is
easy to install where MySQL is not. It applies the migrations through a
test-only path (`bh_migrate_compat()` in `tests/support/environment.php`),
because Dolt lacks the `PREPARE … 'DO 0'` pattern they use. The two
concurrency tests are skipped there: Dolt merges concurrent writes
optimistically, so simultaneous increments of one counter can be lost, while
the rate limiter relies on InnoDB's row lock. (Set
`BASEHIM_TEST_FORCE_INNODB_TESTS=1` to run them anyway and watch them fail.)
A green run on Dolt therefore does **not** prove the rate limit holds under
concurrency; CI on MariaDB does.

## Writing a test

```php
test('what it proves, in a sentence', function () {
    $b = bh_browser('author');              // signed in through the login form
    $res = $b->ajax('media.update', ['id' => 1, 'title' => 'x']);
    assert_same(403, $res->status);
    assert_same('FORBIDDEN', $res->errorCode());
});
```

Files in `tests/Unit/` must not need a database. Files in
`tests/Integration/` may use `bh_browser()`, `bh_db()`, `bh_env()`,
`bh_core_log()` and `bh_app_log()`.
