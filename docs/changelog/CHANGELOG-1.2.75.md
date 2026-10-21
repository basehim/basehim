# Basehim 1.2.75: the audit's P1 set

1.2.73 fixed the P0 findings of the core reliability audit
(`docs/audits/core-reliability-scalability-audit.md`) and part of P1. This
release completes P1: the updater no longer hides a failed migration,
migrations are safe to run twice, scheduled tasks hold a lease and back off,
retried requests get their first answer back, webhooks can be verified,
settings survive concurrent writes, and apps get transactions.

Two new migrations (016, 017); migration 013 is rewritten. No change to
existing app code is required.

## CR-05 (P1-4): the updater reported a failed migration as success

`UpdateService::apply()` ran the pending migrations and returned `ok: true`
whatever they did. A failed migration was visible only in the PHP error log,
and the Updates screen went on to install the next release, whose migrations
assume this one's ran.

- `apply()` returns `migration_error` (and logs it). The files stay installed,
  as before: re-running migrations is safer than reverting code under a
  changed schema.
- `UpdateController::installStep()` passes it on and marks the chain done;
  the non-JS `apply()` path flashes it as an error.
- The Updates screen (`admin/views/updates/index.php`) stops installing
  further updates and shows the error.

## CR-07 (P1-5): migration 013 changed data again when run twice

013 shifts stored times to UTC. The updater and the System page have
recorded migrations under different names in the past, so a migration can
run twice; 013 run twice shifted every time twice.

013 now records each of its eight steps in a new `{migration_progress}` table
(migration, step, done_at), inside the same transaction as the step, and each
step's UPDATE runs only while its marker is missing. A step that ran is never
repeated; a run that was interrupted resumes at the step it stopped at.

A new test runs every migration a second time and checks that nothing
changes (published times, term counts, links, settings, apps, taxonomies).

## CR-12 (P1-6): scheduled tasks: leases, attempts, backoff

1.2.73 made one sweep run at a time on one server. Two runners that start
together (a crontab and a page visit, two web servers), or a sweep killed by
the time limit, could still run a task twice or lose a run without a trace,
and a permanently failing task was retried at its full rate forever.

Migration `016_scheduled_task_leases.sql` adds `locked_until` and `attempts`
to `{scheduled_tasks}`. `SchedulerService`:

- **Lease.** A task is claimed with a conditional UPDATE that sets
  `locked_until` 15 minutes ahead (`LEASE_SECONDS`); only one runner's claim
  succeeds, the other skips the task. Due tasks with a live lease are not
  selected.
- **Lost runs.** A claim that finds `attempts > 0` with the lease expired
  logs that the previous run was interrupted, then runs the task.
- **Backoff.** After *n* failures in a row the next run is
  `interval × 2ⁿ⁻¹` away, capped at a day or the interval, whichever is
  longer (`backoff()`). A success resets `attempts` and `failures`.
- **Visible failures.** `failingTasks()`; Admin → System shows failing tasks
  with their last error, and *Run now* on a task that is running says so.

Works without migration 016 too (the old behaviour) until it has run.

## CR-27 (P1-8): idempotency keys with the first answer replayed

The AJAX gateway's idempotency keys (1.2.47) answered a repeat with
`409 DUPLICATE_REQUEST`. A client that timed out after the server had
created an order could not tell whether it existed. REST and public app
routes had no idempotency at all.

New `App\Services\IdempotencyStore` (table `{idempotency_keys}`, migration
`017_idempotency_keys.sql`; created on first use if the migration has not
run):

- `begin($scope, $key, $requestHash)` claims a key with a primary-key insert:
  `new`, `replay` (with the stored response), `running`, or `conflict`
  (same key, different request).
- `complete($k, $response)` stores status, body and the headers worth
  replaying (`Content-Type`, `Location`, `Retry-After`, `Cache-Control`).
  A 5xx, or a body over 1 MB, releases the key instead, so a retry runs.
- Keys live 24 hours; a claim whose request died is taken over after five
  minutes; expired keys are swept now and then.
- `once($scope, $id, $fn)` runs `$fn` once per id, for webhook events; a
  throwing `$fn` releases the id so the sender's retry is processed.

Where it is used:

- **REST** (`/api/v1`, authenticated routes): new middleware
  `App\Http\Middleware\Idempotency`. An `Idempotency-Key` on POST, PUT,
  PATCH or DELETE is optional. A finished repeat gets the first response with
  `Idempotent-Replayed: true`; still running: 409 with `Retry-After`; another
  body: 422; a malformed key: 400. Keys are per user. CORS allows the header.
- **Public app routes**: new option `'idempotent' => true` on a
  `site()` POST/PUT/PATCH/DELETE route makes the key required (400 without)
  and runs the handler once per key. `SiteRoutes`, `SiteRouteGuard`.
- **AJAX actions** declared `'idempotent'`: a repeat now gets the first
  answer (200, same data, `Idempotent-Replayed: true`). Still running, or the
  key used for another action or input: 409 `DUPLICATE_REQUEST`, as before.
  Only successes are stored: the handler throwing (an `AjaxException`
  included) releases the key.

## CR-28 (P1-8): webhook verification

New `App\Core\Http\Webhook`: `payload()` (the raw body),
`verifyHmac($body, $signature, $secret)` (HMAC-SHA256, hex or base64, with or
without a `sha256=` prefix), `verifyTimestamped($body, $header, $secret,
$tolerance = 300)` (the `t=…,v1=…` format signed over `{t}.{body}`; refuses
timestamps more than five minutes away, accepts any of several `v1` values
during a secret rotation), and `header()`. Constant-time comparisons. Example
in PUBLIC-ROUTES.md.

## CR-09 (P1-9): settings under concurrent writes

- **First save.** `SettingService::set()` read the row, then inserted or
  updated it, so two first saves of one key at once could fail with a
  duplicate key. It is now one `INSERT … ON DUPLICATE KEY UPDATE`.
- **Stale cache.** `all()` caches every autoloaded setting. A request that
  read the database just before a save and wrote the cache just after put the
  old values back for an hour. The cache now carries a generation number,
  changed by every save (`invalidate()`); a snapshot from another generation
  is ignored.
- **Read, change, write.** New `SettingService::update($group, $key, $fn,
  $default)` changes a value under a row lock (`SELECT … FOR UPDATE` in a
  transaction), so concurrent appends and counters lose nothing. In the App
  API: `settings()->update()` for site settings (with the same protections as
  `set()`, `settings.write` permission) and `settings()->updateOwn()` for the
  app's own `app:{slug}` group (no permission needed).
- The public-routes example app (`docs/examples/public-routes`) appended
  notes with a get-then-set and lost notes posted at the same time; it now
  uses `updateOwn()`.

## CR-08 (rest of P1-2): transactions for apps

`$this->api()->transaction(fn)` runs the function in a database transaction:
committed together or rolled back, the exception rethrown, nesting joins an
outer transaction, deadlocks and serialization failures retried up to three
times (all from 1.2.73's `Database::transaction()`). Also new:
`$this->api()->idempotency()`, the store above.

## Files

New: `app/Services/IdempotencyStore.php`,
`app/Http/Middleware/Idempotency.php`, `app/Core/Http/Webhook.php`,
`database/migrations/016_scheduled_task_leases.sql`,
`database/migrations/017_idempotency_keys.sql`,
`tests/Integration/ReliabilityP1Test.php`.

Changed: `app/Services/UpdateService.php`, `SchedulerService.php`,
`SettingService.php`; `app/Http/Controllers/Admin/UpdateController.php`,
`SystemController.php`; `app/Http/Middleware/Cors.php`,
`SiteRouteGuard.php`; `app/Core/Ajax/AjaxGateway.php`;
`app/Core/Api/AppApi.php`, `SettingsApi.php`, `Resource.php`;
`app/Core/Routing/SiteRoutes.php`; `routes/api.php`;
`database/migrations/013_utc_timestamps.sql`;
`admin/views/updates/index.php`, `system/index.php`, `api/reference.php`;
`docs/APP-AJAX.md`, `APP-API.md`, `PUBLIC-ROUTES.md`,
`docs/examples/public-routes/src/App.php`; `index.php` (version);
`tests/support/environment.php`, `tests/Integration/AjaxGatewayTest.php`,
`EditorActionsTest.php`, `JsClientTest.php`, `tests/js/ajax-client.test.js`.

## Compatibility

- **AJAX clients** that treated a repeated idempotency key's 409 as "it
  already happened" now get the first answer (200) instead. Clients that
  check for success are unaffected.
- REST and public routes are unchanged unless a client sends
  `Idempotency-Key`, or the app marks a route `'idempotent'`.
- A task that fails now waits longer between attempts; one that succeeds
  runs on its normal schedule.

## Tests

`tests/Integration/ReliabilityP1Test.php`, 11 tests: migrations twice;
a failing migration reported; scheduler lease and lost-run recovery;
backoff and failing list; REST replay, conflict, bad key, per-caller keys;
the store's 5xx and `once()` rules; an `'idempotent'` public route and an
App API transaction rollback; webhook signatures; the stale settings
snapshot; parallel first saves and appends (MySQL/MariaDB only).

Full suite on Dolt: 347 passed, 0 failed, 4 skipped. The skipped tests need
InnoDB row locking (MySQL/MariaDB).
