# Basehim 1.2.73

This release contains the P0 fixes from the core reliability audit
(`docs/audits/core-reliability-scalability-audit.md`). It also contains the
security issues that were waiting for sign-off, and two remaining data bugs.

| Issue | Severity | Fixed |
|---|---|---|
| BH-022 | High (security) | REST user management: self email and password changes without the current password; role escalation |
| BH-023 | High | Scheduled tasks starved by tasks of an app that isn't running |
| BH-024 | High | App activation, installation and upgrade reported success after failing |
| BH-025 | Medium | Cache tag flushing deleted nothing; the updater's cache clear missed every entry |
| BH-026 | Medium | Post and terms written outside a transaction; a retry duplicated the post; parallel saves failed |
| BH-027 | Medium (security) | No pixel limit on uploaded images |
| BH-001, BH-013 | Medium (security) | Signed off. An app widget without a capability now defaults to the app's own access |

**Database:** migration 015 adds `apps.schema_version`. If the files are installed before the migration runs, core adds the column itself.

**Installs on:** 1.2.72. The patch is cumulative from 1.2.67.

## BH-022 — REST user management

**Before:**
- `PUT /api/v1/users/{id}` let any signed-in account change its own email and password without `current_password`. `/me` already demanded it, so a stolen access token or a `users:write` key was enough to take an account over.
- The endpoints checked the role names `admin` and `super_admin` instead of the `manage_users` capability. They skipped the admin screen's rules, so an admin could:
  - make itself `super_admin`;
  - create super-administrators;
  - create accounts with a role that doesn't exist.

**Now (`Api\UserController`):**
- **Your own account:**
  - email and password changes need `current_password` (403 without it);
  - you can't change your own role or status (403).
- **Other accounts:**
  - you need `manage_users`;
  - the target must be strictly below your level (`AccessControl::canManage`);
  - a new role must exist (422) and be at or below your own (`canAssignRole`, 403);
  - the last active administrator keeps their role and status (409) and can't be deleted.
- Email is validated and checked for uniqueness (409), instead of hitting the unique index.
- Creations, updates, role changes and deletions are written to the activity log.

## BH-023 — Scheduler starvation

**Before:** `SchedulerService::runDue()` took the five oldest due rows and then skipped those whose app wasn't running, leaving them due. Five such rows (from an app that was switched off, uninstalled, or quarantined by 1.2.71) filled every sweep, and no other task ran again.

**Now:**
- A sweep selects only the (app, task) pairs whose handler is registered in that request.
- Rows of an app that isn't running are kept, untouched, for when it comes back.
- Uninstalling an app removes its task rows (`SchedulerService::forgetApp()`).
- The scheduler's and the scheduled publisher's lock files moved from `storage/cache` to `storage/locks`. Clearing the cache had deleted a held lock, which let a second run start.

## BH-024 — Truthful app lifecycle and `schema_version`

**Before:**
- `activate()` marked an app active and *then* ran `onActivate()`. A throw was only logged, so an app whose tables were never created was reported "activated" and kept running.
- `sync()` stored the new version and *then* ran `onUpgrade()`. A failure was only logged, so it never ran again, and the new code ran against the old schema.
- `onInstall()` failures were logged and forgotten.

**Now:**
- **Two versions.** `apps.version` is the version of the files. The new `apps.schema_version` is the version the app's data was migrated to. It moves only after `onUpgrade()` returns, and `''` means `onInstall()` hasn't completed yet.
  - Existing rows start equal to their recorded version.
  - `AppService::hasSchemaColumn()` adds the column when new files run before migration 015 (a hand-uploaded patch).
- **`AppService::runPendingUpgrade()`** runs the upgrade under a per-app `GET_LOCK` and re-reads the versions inside it.
  - **On failure:** the failure is recorded in the app's report (`lifecycle:onUpgrade`), an active app is switched off (status `error`), and the schema version stays put.
  - **Retry:** when the app is next activated, after its code has been validated again, the upgrade runs from the last version that succeeded.
  - **Unloadable code:** an app whose code can't be loaded gets `AppNotLoaded`, never a silent success.
- **Activation order** is: a pending `onInstall()`, then a pending upgrade, then `onActivate()`, then status `active`. A failure leaves the app off with the reason on the Apps screen and in its report.
- **`applyStagedUpgrade()`** reports a failed data upgrade. The Apps screen says "installed, but its upgrade did not complete … fix the cause, then activate it to try again", never "Upgraded".
- **`fireLifecycle()`** returns what went wrong. The new `App\Services\AppHealth\AppNotLoaded` stands for code that wasn't loaded. `onDeactivate()` and `onUninstall()` results are still only logged; they never block switching an app off.

## BH-025 — Cache tags

**Before:**
- `Cache::flushTag()` deleted a `tag_*` folder that `set()` never wrote to. So `$this->api()->cache()->flush()` answered `true` and removed nothing, and so did `POST /api/v1/cache/flush?tag=`.
- `UpdateService::clearCaches()` removed only top-level files, but every entry lives in a sub-folder.

**Now:**
- **Tags.** Every key belongs to one tag, read from the key itself, and lives in that tag's folder:
  - `app.{slug}.x` → `app.{slug}`
  - `settings.all` → `settings`
  - a key with no dot → `_`, cleared only by `flush()`.
- `flushTag()` returns whether the tag's entries are gone.
- `flush()` keeps dot-files.
- Writes go to a temporary file and are renamed into place.
- A cached `null` is now a hit.
- The updater clears the cache through `Cache::flush()`.
- Entries cached by 1.2.72 and earlier, in the old layout, are simply misses, and the first cache clear removes them.

## BH-026 — Atomic post writes

**Before:**
- `PostService::create()` inserted the post, then attached its terms outside any transaction. An unknown term ID failed on the foreign key *after* the post was saved; the client saw a 500, retried, and got a second post.
- Of 8 parallel term updates to one post, 5 returned 500 (duplicate key on `post_term`).
- `terms.count` was adjusted by ±1 and could drift.

**Now:**
- Term IDs are validated before anything is written (`PostRepository::unknownTermIds()`), and the REST API and MCP answer 422.
- The post row, its terms and its default category are written in one transaction, on create and on update.
- `attachTerms()` is idempotent (`INSERT … ON DUPLICATE KEY UPDATE`) and recomputes the touched terms' counts from `post_term`.
- **`Database::transaction()`:**
  - a nested call joins the open transaction instead of throwing;
  - a deadlock, lock-wait timeout or serialization failure (1213, 1205, 40001) runs the callback again, up to 3 times;
  - the original exception is rethrown even when the server already ended the transaction;
  - `Database::isRetryable()` and `inTransaction()` are public.
- `ensureCategory()` rethrows a retryable error instead of swallowing it, because the transaction around it is already gone.

## BH-027 — Image pixel limit

**Before:** a 249 KB PNG of 16,000 × 16,000 pixels was accepted. GD then decoded about 1 GB for thumbnails, taking 13 s. On hosts with PHP's bundled GD, that ended the request after the file had been stored.

**Now:**
- **Uploads:** an image above **Settings → Media → Largest image** (new; 50 megapixels by default) is refused with 422, before the file is placed.
- **`ImageProcessor::safeToDecode()`:** never decodes above 100 Mpx. Where GD's memory counts against `memory_limit`, it decodes only when the image fits.
- **Above that limit:** thumbnails are skipped (`generate()`), and avatars are left as uploaded.

## BH-001 / BH-013 — Signed off

The dashboard and `widgets.render` fixes from 1.2.68 stay as they are.

The open question is answered: an app widget that declares no `capability` now needs the app's own `access_app:{slug}` (administrators, and anyone granted the app), instead of `read` (every signed-in account). An app that means its widget for everyone declares `'capability' => 'read'`.

## Files

**New:**
- `database/migrations/015_app_schema_version.sql`
- `app/Services/AppHealth/AppNotLoaded.php`
- `tests/Integration/ReliabilityTest.php`
- `docs/audits/core-reliability-scalability-audit.md`
- `docs/changelog/CHANGELOG-1.2.73.md` and `RELEASE-NOTES-1.2.73.md`

**Changed:**
- **Core:** `app/Core/Cache.php`, `app/Core/Database.php`, `app/Core/WidgetRegistry.php`.
- **Controllers:** `app/Http/Controllers/Api/UserController.php`, `Api/PostController.php`, `Api/McpController.php`, `Admin/AppController.php`.
- **Services:** `app/Services/AppService.php`, `SchedulerService.php`, `ScheduledPublisher.php`, `PostService.php`, `UpdateService.php`, `MediaService.php`, `ImageProcessor.php`, `AvatarService.php`.
- **Repositories:** `app/Repositories/PostRepository.php`.
- **Admin views:** `admin/views/settings/media.php`, `admin/views/api/reference.php`.
- **Docs and changelog:** `docs/APP-DEVELOPMENT.md`, `docs/DASHBOARD-WIDGETS.md`, `CHANGELOG.md`, `index.php` (1.2.73).
- **Tests:** `tests/Unit/DashboardWidgetsTest.php`. Its fixtures now declare `'capability' => 'read'`.

## Compatibility

- **REST API clients** that change their own password or email through `PUT /users/{id}` must send `current_password`, or use `PATCH /me`.
- **Custom roles:** a role with `manage_users` can now use the user endpoints. An `admin` role stripped of `manage_users` can't.
- **Apps whose `onActivate()` throws** no longer activate. They were broken already; now the screen says so.
- **Apps whose `onUpgrade()` failed before** are upgraded again on their next activation. Make upgrade handlers idempotent.
- **App dashboard widgets without a `capability`** are no longer shown to editors, authors and subscribers. Administrators still see them. Declare `'capability' => 'read'`, or another capability, to show them more widely.
- **Clients sending unknown `term_ids`** get 422. Before, they got a 500 and a saved post.
- **Images over 50 megapixels** are refused until the limit is raised in Settings → Media.

## Tests

`php tests/run.php` against Dolt (`BASEHIM_TEST_DB_COMPAT=dolt`): **338 passed, 0 failed, 3 skipped**, up from 321 in 1.2.72.
- **Skips:** the three InnoDB-only concurrency tests, which need MySQL or MariaDB.
- **Not run against MySQL or MariaDB:** migration 015's real `PREPARE … 'DO 0'` path, because neither server is available in this environment. It uses the same pattern as migrations 006, 007 and 009, and the column is also created on first use if missing (tested).

There are 17 new tests in `tests/Integration/ReliabilityTest.php`. Each one reproduces an audit probe that used to show the defect:
- **Users API:**
  - self changes without `current_password` are refused;
  - no self role change, no role above your own, no unknown role, with the activity-log entry checked;
  - admin against admin, and editors without `manage_users`.
- **Scheduler:** 7 orphaned due tasks; the live task still runs; `forgetApp()`.
- **Apps:**
  - a throwing `onActivate()` refuses activation, then activation works once fixed;
  - a throwing `onUpgrade()` keeps `schema_version`, switches the app off, isn't retried on page loads, and is retried on activation;
  - a successful upgrade of an inactive app;
  - a failed `onInstall()` runs again on activation.
- **Cache:** `flushTag()` removes only its own tag; a cached null is a hit; the updater's clear works.
- **Database:**
  - a nested transaction rolls back with the outer one;
  - a retryable error runs the transaction again, and other errors don't.
- **Posts:**
  - an unknown term ID gives 422 with nothing saved;
  - 8 parallel term updates give no 500, and counts equal the real links.
- **Media:** an 81-megapixel PNG gives 422, with no row and no decoding.
- **Widgets:** the default capability is `access_app:{slug}`; an explicit `read` still works.
- **Migration fallback:** new files on a database without migration 015 get the `schema_version` column on first use.
