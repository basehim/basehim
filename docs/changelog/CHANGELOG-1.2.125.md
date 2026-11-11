# Basehim 1.2.125

PHP 8.1 support is restored, and CI on GitHub passes again. No database changes.

## Core: a fatal error on PHP 8.1

### Why

`App\Core\WidgetRegistry::normaliseDashboard()` declared
`static fn(string $m): never => throw …`. An arrow function returns its
expression, and PHP 8.1 refuses `never` on anything that returns. PHP 8.2
lifted that rule, which is why it went unnoticed on 8.2 and later. On 8.1, any
request that loaded `WidgetRegistry.php` stopped with "A never-returning
function must not return", including the dashboard. CI's PHP 8.1 lint and
test jobs had been failing on it.

### Fix

It is now a plain closure that throws. A new test scans core (`app`, `admin`,
`routes`, `config`) with the tokenizer and fails on any arrow function typed
`never`. It finds the old code at line 369.

## CI: two environment-dependent tests

- **`public routes: … 400 for a malformed path`:**
  - The test client is curl. Newer libcurl (on GitHub's runners) folds dot
    segments, including `%2e%2e`, before sending. So `/bhpr-only-get/%2e%2e/x`
    reached the server as `/bhpr-only-get/x` and got 404.
  - The client now sets `CURLOPT_PATH_AS_IS`, so the server sees the path as
    written. Core's handling (400 for a dot-dot segment) was right all along.
- **`html filter: … removed`:**
  - Browsers parse `<img/src=x/onerror=alert(1)>` as
    `src="x/onerror=alert(1)"`, with no event handler. Newer libxml does the
    same, and older libxml drops the `src`. Both results are safe.
  - The test pinned the older output. It now accepts either, and checks that
    no `on…` attribute survives.

## Aligning the repository with CloudHim's release sync

CloudHim writes the repository from a release's file list: the last full
release (1.2.0) plus every patch since, with each file's content read from
the hub's own installation. A dry run of that sync against `main` found two
problems:

- **The hub's `.gitattributes` decided the rules.** A release that ships the
  file uses the hub's copy, and the hub's copy was 1.2.124's. So the next sync
  would have:
  - put back 1.2.124's `.gitattributes`;
  - re-added `content/apps/wp-migrator`, `content/themes/dark-night`,
    `docs/AGENT-API.md` and `admin/assets/css/marketplace.css`;
  - added two site themes that an old release once carried,
    `content/themes/basehim` and `content/themes/circuits-diy`.
- **14 files differed** between the hub and the repository (README,
  SECURITY, two docs pages, CHANGELOG and five tests, among others). The sync
  would have put the hub's older copies back.

This release ships `.gitattributes`, with the two site themes added to the
retired list, and every file that differed. Once the hub runs 1.2.125, a
simulated sync re-adds nothing and deletes nothing.

## Files

- `app/Core/WidgetRegistry.php`
- `index.php` (version)
- `.gitattributes`: retires `content/themes/basehim` and
  `content/themes/circuits-diy`
- Brought level with the repository:
  - `README.md`, `SECURITY.md`, `CHANGELOG.md`
  - `docs/APP-PERMISSIONS.md`, `docs/THEME-DEVELOPMENT.md`
  - `docs/changelog/CHANGELOG-1.2.124.md`
- Tests:
  - `tests/support/harness.php`
  - `tests/Unit/HtmlSanitizerTest.php`
  - `tests/Unit/Release12125Test.php` (new)
  - `tests/Integration/AdminBarTest.php`
  - `tests/Unit/CommentModerationTest.php`
  - `tests/Unit/Release12100Test.php`, `Release12102Test.php`,
    `Release1299Test.php`

## Tests

- **Full suite with the database (MariaDB):** 769 passed, 0 failed, 1 skipped.
- **On GitHub:** CI passes all 7 jobs:
  - lint on PHP 8.1, 8.2, 8.3 and 8.4;
  - tests on PHP 8.1 and 8.3 with MySQL 8.0;
  - the secrets check.

  The previous green run was before 1.2.123.
