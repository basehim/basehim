# Basehim 1.2.71

App fault isolation: one broken app can no longer take a site down. Basehim
validates an app's code before loading it, contains a failure while it loads or
starts, switches the app off, and gives the administrator a report and a way
back. Reported on cdiy: the KiCad web app declared its own `schema()` method,
which clashes with `App\Core\App::schema()`, and the whole site answered 500.

No database changes. Installs on 1.2.70 (cumulative from 1.2.67).

## The failure path before 1.2.71

`AppService::bootActive()` loads each active app on every request:

1. it registers the app's namespace with the autoloader;
2. `class_exists()` includes the entry class;
3. it constructs the app;
4. it calls `boot()`.

A `try/catch (\Throwable)` sat around each app, and `callBoot()` logged and
swallowed exceptions. That did not help in three cases:

- **Engine fatals.** When PHP links a class against its parent, an
  incompatible override is an `E_COMPILE_ERROR`, not an exception: a narrower
  visibility (the KiCad `private function schema()`, and BH-014's
  `registerDashboardWidget()`), an incompatible signature, or "Cannot declare
  class". No `catch` runs and the request ends. Because active apps load on
  every request, every page, the admin and `/mcp` answered 500, and the only
  fix was editing the database or deleting the app's folder.
- **A `boot()` that failed half-way** left the app running with whatever it had
  registered so far: routes, menu items, hooks.
- **Nothing recorded the failure** anywhere an administrator would see it.
  Nothing stopped the app loading again on the next request.

## What changed

1. **Validation before an app's code is loaded**
   (`App\Services\AppHealth\Preflight`, `DeclarationCheck`,
   `preflight-worker.php`).
   - **Manifest checks:** the namespace, entry class and source folder make
     sense.
   - **A declaration check that never runs app code.** It tokenizes every PHP
     file (syntax errors) and compares each class with its parent: core
     through reflection, the app's own classes through their tokens. It flags
     only what PHP rejects: narrower visibility, an incompatible parameter or
     return type, static/instance mismatch, final methods, missing abstract
     methods, and names core or another loaded app already uses.
   - **An isolated load test.** A separate `php` process with a clean
     environment declares the app's classes against this core. An engine
     fatal there costs that process only.
   - **When it runs:** at install and update, at every activation, on
     **Check again**, and before an active app is loaded whenever its files,
     manifest, core or PHP version changed.
   - **Cost:** results are cached by fingerprint, and changed apps are checked
     in parallel.
2. **A boundary around loading** (`App\Services\AppHealth\AppGuard`, used by
   `AppService`).
   - Every path that includes app code goes through
     `AppService::loadAndBoot()`, which asks the guard first: boot,
     lifecycle methods, upgrades and `instance()`.
   - The stage (`autoload`, `construct`, `boot`, `lifecycle:…`) is tracked, so
     a failure is credited to the right app and stage.
   - An exception or error from loading or `boot()` is caught, and the app's
     registrations are removed: routes (`Router::forgetOwner`), hooks — which
     carry its menu items, assets and head/footer output
     (`HookRegistry::forgetOwner`) — AJAX actions, widgets, content types and
     enqueued assets.
   - The app is then quarantined, and the page carries on.
   - A database outage (`PDOException`) is not blamed on the app: it is
     skipped for that request only.
3. **Engine fatals that slip through** (a file edited within the minute its
   validation is trusted for, or a class loaded later) are caught by a
   shutdown handler.
   - It credits the fatal to the app being loaded, or to the app whose file
     failed.
   - It quarantines the app and answers that one request with 503,
     `Retry-After` and a self-reload. The next request runs without the app.
   - Memory and time limits count as strikes: three in ten minutes quarantine
     the app.
4. **Quarantine that persists, and survives a database outage.**
   - A report is written to `storage/app-health/{slug}.json` (atomic writes,
     under a lock).
   - `apps.status` is set to `error`, a value the schema always had and never
     used; it falls back to `inactive` where it is missing.
   - **Idempotent:** the same failure is recorded and logged once, and the
     database is written only while the app is still marked active.
   - If the database cannot be written, the file keeps the app off. The write
     is retried at most once a minute, and the report says so.
5. **Admin.**
   - **Apps cards:** the card turns red, "Disabled — error", with the reason,
     time and stage, plus **View report** and **Retry**.
   - **Banner:** every admin page shows it to people with `manage_apps`.
   - **Report** at `/admin/apps/{slug}/health`, rendered by core. It shows what
     failed, when and where, the request ID, a sanitized call stack, whether
     the database was updated, the last validation and earlier failures.
   - **Actions on the report:** **Check again**, **Check and reactivate**,
     **Keep it off**.
   - **The app's own URLs:** its admin screens redirect to the report (paths
     taken from its routes once it has booted, or read from its source before
     that), and its public pages answer 503.
   - **Access:** `manage_apps` and CSRF, through the existing `/admin/apps`
     area policy.
6. **Recovery.** Activation always validates afresh, so a broken app cannot be
   turned back on.
   - Once fixed, activating it starts a retry.
   - A clean boot marks it recovered.
   - Failing again quarantines it again, and the earlier failure is kept in its
     history (5 kept).
7. **Messages are cleaned** of server paths, `password=`/`token=`-style
   values and line breaks before they are stored or shown.

## Files

New:
- `app/Services/AppHealth/AppGuard.php`, `HealthStore.php`, `Preflight.php`,
  `DeclarationCheck.php`, `preflight-worker.php` (command line only; `/app`
  is also denied by `.htaccess`).
- `admin/views/apps/health.php`, `admin/views/errors/app-disabled.php`.
- `docs/APP-FAULT-ISOLATION.md`.
- `tests/Unit/AppHealthTest.php`, `tests/Integration/AppIsolationTest.php`.
- `docs/changelog/CHANGELOG-1.2.71.md`, `RELEASE-NOTES-1.2.71.md`.

Changed:
- `app/Services/AppService.php`:
  - `bootActive()`, `bootGuarded()`, `rollback()`;
  - `loadAndBoot()` asks the guard;
  - `callBoot()` no longer swallows exceptions;
  - activation validates; deactivate, uninstall, install, upgrade and
    marketplace update keep the guard informed;
  - lifecycle calls run inside the boundary.
- `app/Core/HookRegistry.php`: owners and `forgetOwner()`.
  `app/Core/Router.php`, `Ajax/AjaxRegistry.php`, `WidgetRegistry.php` and
  `app/Services/PostTypeRegistry.php`: `forgetOwner()`.
- `app/Core/Application.php`: binds `AppGuard`; `APP_HEALTH_DIR` (optional).
- `app/Http/Controllers/Admin/AppController.php`: `health()`, `validate()`;
  `routes/admin.php`: their routes.
- `admin/views/apps/index.php` (failure state), `admin/views/layouts/app.php`
  (banner).
- `docs/APP-DEVELOPMENT.md`, `.gitignore` (`storage/app-health/`), `tests/run.php`,
  `tests/support/environment.php` (test apps; reports kept per test run).
- `CHANGELOG.md`, `index.php` (1.2.71).

`App\Core\App` is unchanged: no method was added to the base class, and
`AppBaseClassTest` still passes.

## Compatibility

- **Healthy apps behave as before.** The declaration check was run against the
  28 apps installed on electronics360.org (about 200 PHP files): no errors.
- **A `boot()` that throws now switches its app off** instead of leaving it
  half-registered. An app that throws on purpose, for something it could live
  without, should catch it.
- **The first request after updating** validates every active app once:
  about 80 ms per app, run in parallel. After that it costs about 1–2 ms a
  request.
- **The `error` status** is shown as "Disabled — error". The API's
  `?status=active` list leaves these apps out, as it leaves out inactive ones.

## Tests

`php tests/run.php` with a database (Dolt): 308 passed, 0 failed, 2 skipped.
The two skips are the InnoDB-only concurrency tests.

New unit tests (17):
- Seven declaration conflicts are each flagged, and real PHP refuses each one
  in a child process, so the check is neither too lax nor too strict.
- A healthy app with compatible overrides passes, and PHP loads it.
- Also covered:
  - syntax errors, and a class name core uses;
  - vendor files as warnings;
  - a missing parent;
  - the isolated load test reporting an engine fatal while the caller carries
    on;
  - fingerprint changes;
  - message cleaning;
  - infrastructure vs defect;
  - quarantine with the database down (kept, not claimed as persisted, not
    duplicated);
  - three strikes;
  - unsafe report names.

New integration tests (16), through the real front controller:
- **Failure cases:**
  - a healthy app answers;
  - `boot()` throws after registering a route and menu item: the page renders
    in the same request, the route and menu item are gone, the app is
    `error`, secrets are redacted, and its URL answers 503 without details;
  - a `TypeError`;
  - a syntax error;
  - the KiCad clash: `/`, `/admin/login`, `/mcp` and `/api` all stay under 500;
  - an incompatible signature;
  - a missing parent and a missing class;
  - a constructor that throws;
  - four requests record one failure and log it once;
  - an engine fatal past validation gives one 503 with `Refresh`, then 200.
- **Admin and access:**
  - the report needs `manage_apps` (editor and subscriber 403, anonymous sent
    to log in), and a bad CSRF token changes nothing;
  - a broken app cannot be reactivated from the card or the report; once
    fixed it can, and its history is kept;
  - a retried app that fails again goes back to quarantine with both failures;
  - the dashboard, posts, apps and settings screens and the theme work while an
    app is off; the banner shows for admins only; the app's admin screen leads
    to the report.
- **Workflows:** activate, deactivate and reactivate a healthy app.
- **Database errors:** a `PDOException` in `boot()` does not switch the app off.

Checked in Chromium at 1280px and 390px:
- the Apps card, the report, the dashboard banner;
- recovery: Retry refused while broken, then accepted after the fix, with the
  app's screen working again.
