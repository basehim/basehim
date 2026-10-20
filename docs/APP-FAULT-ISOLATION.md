# App fault isolation

Since 1.2.71 one broken app cannot take a Basehim site down. When an app
cannot load or start, Basehim switches that app off, keeps everything else
running, and shows the administrator a report.

This page explains how it works, what is and is not protected, and what an app
author should know.

## What happens when an app fails

| The app… | When it is caught | What the visitor sees | What happens to the app |
|---|---|---|---|
| has a syntax error | Before any of its code is loaded (validation) | Nothing — the page renders | Switched off, report saved |
| declares a method its parent class does not allow (narrower visibility, incompatible signature, static mismatch), uses a class name core already uses, or extends a class that does not exist in core | Before loading (validation) | Nothing | Switched off |
| throws from its constructor or `boot()` (any exception, `TypeError`, missing class) | While loading, in the same request | Nothing — the page renders without the app | Its routes, hooks, menu items, AJAX actions, widgets and content types are removed; switched off |
| hits an engine fatal that validation could not see (a file edited in the last minute, a class only loaded later) | When PHP stops | That one request answers **503** and reloads itself after a second | Switched off; the next request runs without it |
| exhausts memory or time | When PHP stops | That request fails | Counted; switched off on the 3rd time within 10 minutes |
| fails because the **database** is unreachable | While loading | Whatever core shows for a database outage | **Not** switched off — skipped for that request only |

"Switched off" means:

- `apps.status` becomes `error` (or `inactive` on an install whose column has
  no `error` value);
- a report is written to `storage/app-health/{slug}.json`;
- the failure is logged once in the core log and the app's own log
  (Apps → Logs).

The app's files, settings, database tables and data are never touched.

If the database cannot be written when the app fails, the report file alone
keeps the app off. The database write is retried, at most once a minute, until
it succeeds. The report says so ("Not yet — kept off by this report").

## How validation works

`App\Services\AppHealth\Preflight` checks an app in three steps:

1. **The manifest.** The namespace, entry class and source folder are
   checked.
2. **Declarations, without running code** (`DeclarationCheck`).
   - Every PHP file is tokenized with `TOKEN_PARSE`, which finds syntax
     errors.
   - Each class is compared with its parent: core classes through
     reflection, the app's own classes through their tokens.
   - It reports only what PHP itself rejects: narrower visibility, an
     incompatible parameter count or builtin type, a return type that is not
     covariant, static/instance mismatch, final methods, missing abstract
     methods, class and function names already in use, and missing core
     parents or interfaces.
   - Where a rule needs class types it cannot resolve, it says nothing and
     leaves the case to step 3.
3. **An isolated load test.** A separate `php` process declares the app's
   classes against this core (`preflight-worker.php`).
   - It runs with a clean environment: no database credentials, no `.env`,
     no boot.
   - An engine fatal there ends that process, not a page.
   - It needs the PHP command line (`proc_open` and a `php` binary of the
     same minor version). cPanel's `ea-php`, CloudLinux's `alt-php` and
     distribution packages are found automatically; set `PHP_CLI_BINARY` in
     `.env` to point at another one.
   - Where none is available, steps 1–2 and the runtime guard still apply,
     and the report says the load test did not run.

Validation runs:

- when an app is installed or updated (upload, marketplace, upgrade);
- when it is activated — always afresh;
- when an administrator clicks **Check again**;
- before an active app is loaded, whenever its files, `app.json`, core's
  version or `App.php`, or the PHP version have changed since it last passed.

Results are cached by a fingerprint of those inputs. File times are re-read at
most once a minute per app, so a normal request does no validation at all.

After a core update, every active app is validated on the first request. The
isolated load tests run in parallel, six at a time, and take about 80 ms each.
Other requests that arrive meanwhile wait for the result instead of starting
the same checks.

## Recovering an app

1. Open **Apps**. The app's card is red: "Disabled — error", with the reason.
   Every admin page shows a banner to people who can manage apps until it is
   dealt with.
2. **View report** (`/admin/apps/{slug}/health`) shows:
   - what failed, when, and at which stage;
   - the file and line, the request ID and the call stack;
   - whether the database was updated;
   - the last full validation and earlier failures.

   Opening one of the app's own admin screens leads here too. The report is
   rendered by core and needs `manage_apps`.
3. Fix the app: install a corrected version, or edit its files.
4. **Check and reactivate** (or **Retry** on the card) validates the app afresh
   and activates it only if it passes.
   - If it then fails while starting, it is switched off again.
   - The previous failure is kept under "Earlier failures".

**Keep it off** deactivates the app normally. Deleting it works as for any
app. Nothing requires editing the database or deleting the app's folder.

## For app authors

- Do not declare methods with the names of `App\Core\App`'s protected or public
  methods unless you mean to override them with a compatible signature: `get`,
  `post`, `route`, `table`, `schema`, `db`, `log`, `view`, `icon`, `settings` …
  (`tests/Unit/AppBaseClassTest.php` lists them all). A private `schema()` is
  exactly what took a site down: `App::schema(string $sql): void` exists, and
  PHP refuses a child class that narrows or changes it.
- `boot()` that throws is no longer "logged and carried on": the app is
  switched off and its registrations are removed. Catch what you expect to
  fail (a remote service, an optional file), and let real defects surface.
- Keep class files free of code that runs when the file is included. The
  isolated load test includes them.
- Run your app once with the site in Development mode and open
  Apps → your app → **Check again** before shipping.

## Limits

- **An engine fatal still ends the request it happens in.** PHP offers no way
  to continue after a compile error, for example "Cannot declare class" or an
  incompatible override. Validation exists so that this happens before the
  code is loaded. When it does slip through, the cost is one 503 that reloads
  itself, and the app is off from the next request.
- **Fatals while serving a page** (for example, in a controller the app
  autoloads later) switch the app off only when they are declaration errors.
  Other runtime errors give a 500 for that route, as before, and are not
  blamed on the app.
- **Two apps that declare the same class** are caught only if the other app's
  class is already loaded when validation runs. Otherwise the runtime guard
  switches off the one that failed.
- **This is fault isolation, not a sandbox.** An app's code runs with core's
  rights. A malicious app is not contained.
- On a site with several web servers, reports are per server, as
  `storage/` is.
