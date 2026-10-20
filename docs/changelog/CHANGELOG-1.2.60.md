# Basehim 1.2.60

Post scheduling: a Publish panel in the block editor's sidebar (publish now or
schedule in the site timezone), a publisher that takes scheduled posts live on
their own, and the publishing rules in one place.

No database changes. Installs on 1.2.58.

## The problem

`scheduled` existed as a label — in the editor's status menu, the list badges
and the Status filter — but nothing gave it a date and nothing ever published
it. Choosing "Scheduled" left a post hidden for good. Around it:

- **Contributors could set `scheduled` or `private`.** `gatePublishing()` in the
  admin form only downgraded `published`. Harmless while scheduling did
  nothing; with a working publisher it would let a contributor publish without
  review.
- **Bulk Publish on a scheduled post kept its future `published_at`**, because
  `PostService::update()` only set the date when it was empty: a live post
  dated in the future, sorted above everything.
- **One flash per save:** the "submitted for review" notice was overwritten by
  "Post updated." on the next line, so contributors never saw it.
- **The cron URL was nowhere in the admin** — only in `GET /api/v1/schedule`.

## What changed

1. **Publish panel** (`admin/views/posts/edit.php`, `post-publish.js`). First
   section of the sidebar: the current state ("Published October 1, 2026 at
   9:00 am", "Scheduled for …", "Pending review"…), a choice of *Immediately*
   or *Schedule* (*Keep published* / *Schedule* on a live post), and — only
   when scheduling — date and time fields with the site timezone named beside
   them and a live preview ("Goes live Tue, Oct 20, 2026, 9:30 AM PKT — in 2
   days"). The fields are site-local; conversion to an instant uses `Intl` in
   the browser for the preview, and the same zone in PHP for the decision.
   The primary button follows the panel: **Publish**, **Schedule**, **Publish
   now** (on a scheduled post), **Update**, or **Submit for review** for roles
   without the publish capability. A past or missing time is caught before
   submitting, against the server's clock (passed in the page, so a wrong
   browser clock does not decide). Scheduling a live post asks for
   confirmation first, since it takes the post off the site.
   "Scheduled" is no longer offered in the status select unless the post
   already is scheduled — a schedule is reached through the panel.

2. **One place for the rules** (`App\Services\PostPublishing`, pure, no
   database). Reads the status plus `publish_mode`, `publish_date`,
   `publish_time`:
   - going out (`published`, `scheduled`, `private`) needs `publish_{type}s`;
     without it the post is submitted for review and no date is set;
   - *Schedule* needs a real date and time, still in the future and before
     2038-01-19 (the end of a `TIMESTAMP`), and gives `scheduled` plus the UTC
     instant;
   - *Now* on a post that is not live gives `published` at the server's time;
     a post that was live before and has a past date keeps it;
   - a live post keeps its status **and** date on Update; it becomes
     `scheduled` only on an explicit *Schedule*;
   - a request it cannot honour returns an error and the stored status/date are
     left alone. `PostController` then saves everything else (no work lost) and
     reports the reason; a new post is created as a draft.

3. **Data rules in `PostService`** (`timing()`), whatever the entry point — the
   form, bulk actions, the App API, REST:
   - `published` with no date, or a future one, gets now;
   - `scheduled` needs a future `published_at`; a passed one is due (published,
     date kept); none leaves a draft;
   - `draft`/`pending` coming from `scheduled` drops the unused date.
   `create()`/`update()` accept `published_at` (anything `Time::utc()` reads).
   A new action, **`post.published`**, fires once each time a post becomes
   `published` (create, update, bulk, schedule), with the post and the previous
   row.

4. **The publisher** (`App\Services\ScheduledPublisher`).
   - Runs at the end of `Application::bootApps()` on every request — after apps
     and the theme booted (so their listeners run), before routing (so the
     first visitor after the time already sees the post). The earliest due time
     is cached in a file for up to five minutes and dropped on every post save,
     delete or restore; a request with nothing due costs one cache read.
   - Runs on every call of the cron URL (`ScheduleController::run`), which now
     also returns `posts_published`.
   - Missed windows: everything at or before now is due, oldest first, 25 per
     run.
   - Concurrency: a non-blocking `flock` lets one run through at a time per
     server; each post is then claimed with one conditional UPDATE
     (`PostRepository::claimScheduled()`: `… SET status='published' WHERE id=?
     AND status='scheduled' AND published_at <= ? AND deleted_at IS NULL`), so
     across servers only the request that changed the row fires `post.updated`
     and `post.published`. A listener that throws is logged; the publication
     stands and the next post is still processed.
   - Trashed posts are never published. Each publication is written to the
     activity log (`post.scheduled_published`).

5. **Public visibility** is unchanged and already strict: every public route
   (single, archives, author, home, feed, sitemap, search, REST, MCP) asks for
   `status = 'published'`. A scheduled post is invisible until the publisher
   flips its status, whatever its date; editors still see it in preview.

6. **System page.** Cache & Maintenance shows the cron URL (with Copy), the
   number of scheduled posts, the next one due, and the last task sweep.
   `manage_settings` only, as the page already is.

7. **Lists.** The data table's Date column says "Goes live …" for a scheduled
   row.

## Files

New:
- `app/Services/PostPublishing.php` — the editor's request → status and time.
- `app/Services/ScheduledPublisher.php` — finds and publishes due posts.
- `admin/assets/js/post-publish.js` — the Publish panel and the primary button.
- `tests/Unit/PostPublishingTest.php`, `tests/Unit/PublishPanelJsTest.php`,
  `tests/js/post-publish.test.js`, `tests/Integration/ScheduledPublishingTest.php`.
- `docs/changelog/CHANGELOG-1.2.60.md`, `docs/changelog/RELEASE-NOTES-1.2.60.md`.

Changed:
- `app/Services/PostService.php` — `timing()` invariants, `published_at` in
  create/update, `post.published`, `publishScheduled()`, next-due cache reset.
- `app/Repositories/PostRepository.php` — `dueScheduledIds()`,
  `nextScheduledAt()`, `claimScheduled()`.
- `app/Http/Controllers/Admin/PostController.php` — `gatePublishing()` replaced
  by `resolvePublishing()` (PostPublishing); one combined flash per save.
- `app/Core/Application.php` — binds `ScheduledPublisher`, runs it after boot.
- `app/Http/Controllers/Api/ScheduleController.php` — cron run publishes posts.
- `app/Http/Controllers/Admin/SystemController.php`, `admin/views/system/index.php`
  — the cron card.
- `admin/views/posts/edit.php` — the panel, the primary button, status select.
- `admin/assets/js/block-editor.js` — Save draft knows the panel fields and
  never sends them (so it stays on AJAX instead of falling back to the form).
- `admin/assets/css/block-editor.css` — panel styles.
- `routes/tables.php` — "Goes live" on scheduled rows.
- `index.php` — version 1.2.60.
- `BLOCK-EDITOR.md`, `docs/APP-DEVELOPMENT.md`, `docs/APP-API.md`, `CHANGELOG.md`.

## Compatibility

- Existing posts keep their status and `published_at`. Nothing is rewritten.
- Forms and apps that post only `status` behave as before: `published` means
  now, `scheduled` keeps a stored future time.
- Posts already `scheduled` with a past `published_at` (from the WordPress
  importer, which maps `future` to `scheduled`) are published on the first
  request after updating, keeping their dates.
- REST and MCP still accept only `draft`, `pending`, `published`.
- Themes need nothing.

## Tests

`php tests/run.php` with a database: 278 passed, 3 skipped, 1 failed. The
failure is the pre-existing `editor: a double click (same idempotency key)
creates one post` on Dolt (full-text index limitation; fails identically on
1.2.58). The run used Dolt (`BASEHIM_TEST_DB_COMPAT=dolt`); the concurrency
test is skipped there by `bh_require_innodb()` and also passes when forced.

New: 12 unit tests for the rules (timezones, DST gap and overlap, past, 2038,
capabilities, live/scheduled transitions), 9 for the panel in Node (labels,
validation against the server clock, confirmation), 17 integration tests
(form create/update, public routes, first-visitor publish, cron URL, missed
window, bulk actions, AJAX draft refusal, permissions and CSRF, repeated and
concurrent runs, hooks once, site timezone with DST, PostService rules, System
page).
