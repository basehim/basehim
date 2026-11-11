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

## Files

- `app/Core/WidgetRegistry.php`
- `index.php` (version)
- Tests:
  - `tests/support/harness.php`
  - `tests/Unit/HtmlSanitizerTest.php`
  - `tests/Unit/Release12125Test.php` (new)

## Tests

- **Full suite with the database (MariaDB):** 769 passed, 0 failed, 1 skipped.
- **On GitHub:** CI runs PHP 8.1, 8.2, 8.3 and 8.4 for lint, and 8.1 and 8.3
  with MySQL 8.0 for the tests.
