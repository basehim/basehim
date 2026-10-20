# Basehim 1.2.70

Five fixes and a clean-up of the issues found while building 1.2.68–1.2.69:
a random table prefix chosen by the installer (1), the Media Library's
loading state and a details dialog that works like Quick Edit on phones (2),
dashboard cards whose own scripts never ran after Refresh (3), the dashboard
buttons on one line on phones (4), and four smaller fixes (5–8).

No database changes. Installs on 1.2.69 (cumulative from 1.2.67).

## What changed

1. **The installer picks the table prefix.** The Database step no longer asks
   for a prefix and no longer offers "Replace existing tables".
   - After the connection test succeeds, `installerPrefix()` chooses a letter
     plus two lower-case letters or digits and an underscore (`k1y_`), and
     checks the database has no table starting with it; up to 50 tries.
   - A `DB_PREFIX` already in `.env` wins, so an install prepared by hand keeps
     its prefix. Going back and retrying the same host and database reuses the
     prefix chosen the first time, so a half-finished install is resumed rather
     than duplicated.
   - Because the prefix is always new, the installer never touches another
     installation's tables in a shared database; the replace option had no
     remaining purpose.
   - The tables list on the next step shows names without the prefix.
   - `README.md` and `.env.example` say so.

2. **Media Library: loading state and the details dialog.**
   - **Loader.** The details preview showed a collapsed spinner box while a
     large image loaded. It now reserves the image's own shape
     (`--nml-ar: W / H` from the stored dimensions, 4:3 when unknown, at least
     12rem) with a soft shimmer, so nothing jumps when the image arrives.
     Grid thumbnails shimmer until loaded; a thumbnail that fails says
     "Preview unavailable" instead of a broken image. Thumbnails already in the
     browser cache are marked loaded at once (`markCachedThumbs()`), so they
     never flash the shimmer.
   - **Dialog.** The details pane follows the Quick Edit dialog: a header with
     "File details", the file name and ×; a scrolling body; a footer with
     Close and **Save changes** (only when you may edit the file). On phones
     (≤ 600px) it fills the screen (`100dvh`, safe-area padding), the footer
     buttons share the width, and inputs use 16px text so iOS does not zoom.

3. **Dashboard: a card's own scripts run after Refresh.** A legacy or `html`
   card that returns trusted markup with a `<script>` (CloudHim's Analytics
   card loads its numbers this way) stayed on "Loading…" after Refresh,
   Expand or a settings change: scripts inserted with `innerHTML` never run.
   `dashboard.js` now re-creates each `<script>` in a trusted card
   (`activateScripts()`), copying its attributes, so it runs exactly as on the
   first page load. Untrusted (`html` view, sanitised) cards are unchanged.
   Script types that are not JavaScript (`application/json`, templates) are
   left alone.

4. **Dashboard header on phones.** "New post", "Upload" and "Customize" share
   one line below 640px, each taking an equal share; below 360px the icons
   drop so the labels fit.

5. **`users.meta` saves can no longer lose each other (BH-008).**
   `UserService::setMeta()` read the whole JSON, changed one key and wrote it
   all back, so two saves at once (a table's Screen Options in one tab, the
   dashboard layout in another) could undo one another. It now goes through
   `UserRepository::lockedMetaUpdate()`: `SELECT meta … FOR UPDATE` in a
   transaction, the change, the write; it joins a transaction already open,
   and retries a deadlock or serialization failure (SQLSTATE 40001 / 1213) up
   to five times. Removing a key that is not there is a successful no-op;
   JSON over 64 KB or an unknown user returns `false`.

6. **The installer showed version 1.2.59.** `install.php` had its own
   `BASEHIM_VERSION`, last bumped in 1.2.59. It now reads the version from
   `index.php`.

7. **`docs/examples/system-monitor` works (BH-004).** The example called
   methods `App` does not have and fatally failed when copied into
   `content/apps/`. It is now a small working app: an admin page
   (`/admin/system-monitor`) and a dashboard status card (load, memory and
   disk, with a disk warning setting) that reports "unavailable" on hosts
   that hide those figures.

8. **Missing changelogs (BH-010).** The changelog and release notes for
   1.2.58–1.2.67 were linked from `CHANGELOG.md` but missing from the
   repository; they are restored from the release server, byte for byte.
   See "Repository" below for why they went missing.

## Repository

`.gitattributes` marks `/docs` and `/tests` `export-ignore`, and the release
server's GitHub sync leaves every `export-ignore` path to the repository. So
changelogs, docs and tests shipped in a release never reach GitHub by
themselves; they need committing to the repository directly. This release's
files under `docs/` and `tests/` are in the patch, but the repository needs
the same commit.

## Files

Changed:
- `install.php` — random prefix, no prefix or replace fields, version from
  `index.php`.
- `admin/assets/js/dashboard.js` — `activateScripts()`.
- `admin/assets/css/dashboard.css` — header actions on phones.
- `admin/assets/js/media-library.js`, `admin/views/media/index.php`,
  `admin/assets/css/media-picker.css` — dialog header and footer, loading
  states.
- `app/Repositories/UserRepository.php` — `lockedMetaUpdate()`.
- `app/Services/UserService.php` — `setMeta()` through it.
- `docs/examples/system-monitor/` — rewritten (`app.json`, `src/App.php`,
  `views/page.php`; `desktop-modules/` removed).
- `README.md`, `.env.example`, `CHANGELOG.md`, `index.php` (1.2.70).
- Tests: `tests/Integration/EditorActionsTest.php` (BH-009: the double-click
  test counts titles in PHP instead of filtering a FULLTEXT column, which
  Dolt cannot), `tests/Integration/DashboardTest.php` (two `users.meta`
  tests).

New:
- `docs/changelog/CHANGELOG-1.2.58.md` … `1.2.67.md`,
  `RELEASE-NOTES-1.2.58.md` … `1.2.67.md` (restored).
- `docs/changelog/CHANGELOG-1.2.70.md`, `RELEASE-NOTES-1.2.70.md`.

## Tests

`php tests/run.php` with a database (Dolt): 275 passed, 0 failed, 2 skipped.
For the first time on Dolt nothing fails: the double-click test (BH-009) now
passes. The skips are the two concurrency tests that need InnoDB row locks.

New:
- Eight processes saving different `users.meta` keys at once all land (run
  three times, on Dolt too).
- `setMeta()` changes one key and leaves the others; removing a missing key
  succeeds.

Checked in Chromium:
- Installer: three fresh installs got three different prefixes; a retry
  reused its prefix; another site's tables in the same database were left
  alone.
- Media Library at 1280px and 390px: the loading frame keeps the image's
  shape; the dialog's header, footer and Save; full screen on phones.
- Dashboard: a card with an inline script shows its values after Refresh and
  Expand (before: "Loading…"); the header buttons on one line at 320, 360 and
  390px.
- `system-monitor` copied into `content/apps/`: its page opens, signed-out
  visitors are sent to sign in, and its card is in the dashboard catalogue.
