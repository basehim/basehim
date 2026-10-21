# Basehim 1.2.76: the audit's P2 set (scalability)

1.2.73 and 1.2.75 fixed the P0 and P1 findings of the core reliability audit
(`docs/audits/core-reliability-scalability-audit.md`). This release fixes
P2: the places where cost grew with the size of the site — lists, search,
slugs, author pages, the sitemap, settings, page views, and tables that were
never cleaned.

Three new migrations (018, 019, 020). No change to existing app or theme
code is required.

## CR-17 (P2-1): lists sorted by an index, totals cached, cursor pages

Every list of posts sorted on `COALESCE(published_at, created_at)`, which no
index can serve, and counted every row on every request.

- Lists of **published** content sort on `published_at DESC, id DESC`
  (`PostRepository::paginate()`, `byTermId()`, `search()`), served by the new
  index `idx_list (type, status, deleted_at, published_at, id)` (migration
  `018_post_list_index.sql`, which also gives any published row without a
  `published_at` its creation time, the date the old sort showed). Drafts and
  mixed lists keep the old order. Every order now ends on `id`, so posts with
  the same date no longer swap places between pages.
- The total of a published list is cached for five minutes, and counted
  again after any save that goes through `PostRepository` (a generation
  number, as for settings in 1.2.75).
- **Keyset pagination on the API:** `GET /api/v1/posts` returns
  `meta.next_cursor`; `?after=<cursor>` reads the next page without counting
  or skipping rows. A malformed cursor is a 400.

## CR-16 (P2-2): search

Search matched `LIKE '%q%'` on title, content and excerpt — a scan of the
whole table for every visitor's query. `%` and `_` in a query were
wildcards.

- **FULLTEXT** through the existing `idx_ft_content` index, in boolean mode:
  every word of three letters or more must appear, as a word or a word
  prefix. A query with no such word (one or two letters, or a script written
  without spaces) matches titles only. Results stay newest first.
- If the database refuses the FULLTEXT query (no index, an engine without
  it), the same search runs as before with LIKE; this is remembered for an
  hour so searches don't fail first every time. Settings → Reading → Search
  method can choose the LIKE match outright.
- LIKE wildcards are escaped in every search: posts, and the admin searches
  of comments, users and terms (media already did).
- **Rate limit:** `/search` and `/api/v1/search` allow
  `reading.search_rate_limit` searches a minute per visitor (default 30; 0 =
  no limit), then answer 429 with `Retry-After` (`SearchThrottle`).

## CR-18 (P2-3): slugs

- **One query for a free slug.** `PostService::resolveSlug()` tried `-2`,
  `-3`… with one query each while holding the slug lock. It now reads the base
  and all its numbered variants in one indexed range read
  (`PostRepository::slugsLike()`) and takes the highest number + 1. Measured
  on Dolt with 5,000 "Blue T-Shirt" posts: 6.9 s (5,001 queries) before,
  0.02 s after.
- **Unicode slugs.** A title in a script without a Latin transliteration —
  Urdu, Arabic, Persian, Chinese, Cyrillic, Devanagari — became `n-a`,
  `n-a-2`, … It now keeps its letters: "پاکستان کی خبریں" →
  `پاکستان-کی-خبریں`. Latin titles, accented ones included, are unchanged.
  `Helpers::slug($text, '-', true)`; used for posts, pages, categories, tags
  and author slugs. Existing slugs are not changed.
- Links are percent-encoded (`Helpers::postUrl()`, the term links of the two
  bundled themes, the sitemap), and **core routes now receive decoded
  parameters** (`Router`), as app routes have since 1.2.72, so
  `/%D9%BE%D8%A7…` reaches the post whose slug is `پا…`. A parameter that
  decodes to a slash, a NUL byte or invalid UTF-8 is a 400.
- **No save without the slug lock.** A save that waited for the lock went
  ahead without it after 5 s, racing the save that held it. It now waits up
  to 10 s and is then refused ("The site is busy saving other content");
  databases without named locks carry on as before.

## CR-19 (P2-4): author pages

`/author/{slug}` found its author by scanning `users.meta`, and on a miss
rebuilt every author's slug — loading every user's meta into PHP — on every
request for an unknown address.

- New column `users.public_slug` with a unique index (migration
  `019_user_public_slug.sql`, which copies existing slugs from meta;
  `AuthorService` adds the column itself if new files run first). Meta is
  still written, for older code.
- A lookup is one indexed query. A miss fills in missing slugs for authors
  without one, at most once an hour (`authors.slug_backfill_at`), never
  everybody's.
- New slugs are claimed through the unique index: two requests that pick the
  same slug at once end with different ones.
- Author slugs can be Unicode, like post slugs.

## CR-20 (P2-5): the sitemap

`/sitemap.xml` was one file capped at 2,000 entries — larger sites silently
lost the rest from search engines — made one query per post for its
category, and listed URLs on whatever host the request named.

- `/sitemap.xml` is a **sitemap index** of `/sitemap-content-1.xml`, `-2`, …
  (published content, 10,000 per file, by id range, no OFFSET),
  `/sitemap-terms.xml` (categories and tags with content) and
  `/sitemap-authors.xml` (when author archives are on).
- Each content file looks up its posts' primary categories in one query.
- Every file is cached for ten minutes and rebuilt after any save.
- Addresses use `APP_URL`. The request's host is used only when `APP_URL` is
  missing or is a localhost address while the site is served elsewhere, and
  the cache is kept per host, so a forged `Host` header can never put its
  URLs in front of other visitors.
- `lastmod` is written in UTC (`Z`).

## CR-22 (P2-6): settings autoload

Every request loaded every setting, including rows saved with
`autoload = 0`, and the scheduler wrote `scheduler.last_sweep` once a minute,
throwing that cache away every minute.

- The cached snapshot holds only `autoload = 1` rows. `autoload = 0` rows are
  loaded the first time one is asked for, and cached separately; writing one
  leaves the snapshot alone. `get()`, `getGroup()` and `all()` return the same
  values as before.
- The time of the last sweep is a file (`storage/locks/scheduler-last-sweep`),
  not a setting. The old setting is read once and deleted.

## CR-23 (P2-7): page views without a lock on the post

Each page view ran `UPDATE posts … view_count + 1` on the post's own row,
so under a traffic spike every visitor of one post waited for the same row
lock.

- Views go to `{post_views}` (migration `020_post_views.sql`): one small
  counter per post, minute and shard (16 shards), incremented with
  `INSERT … ON DUPLICATE KEY UPDATE`. The core task `post-views` adds every
  finished minute to `posts.view_count` once a minute (`PostViewCounter`).
- Settings → Reading → Page views: *Count, added up every minute* (default),
  *Count, updated on every view* (as before), or *Don't count*.
- `view_count` may be up to two minutes behind. A view still never changes a
  post's modified date.

## CR-24 (P2-8): retention

Nothing deleted expired refresh tokens, activity logs or notifications, and
on many Linux hosts PHP never collects old session files.

The core task `cleanup` runs daily (`RetentionService`), in batches of
5,000 rows:

- refresh tokens, remember-me tokens, MCP OAuth codes and tokens, and
  password resets 30 days after they expired; sign-in attempt records idle for
  30 days that lock nobody; expired idempotency keys and rate-limit counters;
- activity logs older than `retention.activity_days` (180), notifications
  older than `retention.notification_days` (90), `storage/logs/basehim-*.log`
  older than `retention.log_days` (30) — 0 keeps them;
- session files unused for a day (or `session.gc_maxlifetime`, if longer).

## Core scheduled tasks

`SchedulerService::core($key, $interval, $handler)` registers a core task
without touching the database; the task row is created when a sweep runs, so
ordinary requests pay nothing. Core tasks appear on Admin → System under
`core` and get the same leases, backoff and failure list as app tasks.

## Not done

- The audit's suggestion to warn in the app review when an app's settings
  exceed a size is not in this release.
- The FULLTEXT path and the list index were measured on Dolt only, which has
  no boolean-mode FULLTEXT (so the LIKE fallback ran) and plans queries
  differently. Confirm on MySQL or MariaDB (BH-031).

## Files

New: `app/Services/PostViewCounter.php`, `RetentionService.php`,
`SearchThrottle.php`; `database/migrations/018_post_list_index.sql`,
`019_user_public_slug.sql`, `020_post_views.sql`;
`tests/Integration/ReliabilityP2Test.php`.

Changed: `app/Repositories/PostRepository.php`, `TaxonomyRepository.php`,
`UserRepository.php`; `app/Services/PostService.php`, `AuthorService.php`,
`SettingService.php`, `SchedulerService.php`, `SettingsSaver.php`,
`TaxonomyService.php`, `CommentService.php`, `PostEditorService.php`,
`Table/ContentQuickEdit.php`; `app/Core/Application.php`, `Helpers.php`,
`Router.php`, `Api/TermsApi.php`; `app/Http/Controllers/Web/SitemapController.php`,
`Web/SearchController.php`, `Api/SearchController.php`, `Api/PostController.php`;
`routes/web.php`; `admin/views/settings/reading.php`, `api/reference.php`;
`content/themes/default/templates/single.php`,
`content/themes/dark-night/templates/single.php`; `docs/APP-API.md`,
`docs/THEME-DEVELOPMENT.md`; `index.php` (version);
`tests/Integration/PostDatesTest.php`.

## Compatibility

- New posts, pages and terms with non-Latin titles get Unicode slugs instead
  of `n-a-N`. Existing slugs and URLs are unchanged.
- Search finds posts containing every word, not the exact phrase; results
  are still newest first. *Text match* on Settings → Reading restores the
  old behaviour.
- `/sitemap.xml` is an index; search engines follow it. Empty categories and
  tags are no longer listed.
- `view_count` is up to two minutes behind by default.
- A save that cannot get the slug lock within 10 s is refused instead of
  racing.
- Core routes receive decoded parameters. A handler that decoded a parameter
  itself would now decode twice; none in core did.

## Tests

`tests/Integration/ReliabilityP2Test.php`, 15 tests. Full suite on Dolt:
362 passed, 0 failed, 4 skipped (MySQL/MariaDB-only, BH-031).
