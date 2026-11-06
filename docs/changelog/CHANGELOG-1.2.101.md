# Basehim 1.2.101

Fixes BH-107 to BH-111 and adds Quick View to data tables, for CloudHim 1.6.0
and any app with a list of its own. No database changes.

## Update hub (BH-107, BH-108)

- `UpdateService::siteIdentity()`: a site introduces itself to the hub with
  its Site title (the host name when there is none), tagline and site icon as
  a full URL, on registration and on every check (`name`, `tagline`, `icon`).
  `updates.site_name`, which held the host name from the first registration,
  is no longer read or written.
- After a successful `apply()`, `heartbeat($newVersion)` reports the version
  just installed (the constant still holds the old one in that request). If
  the hub can't be reached, the hourly wait is cleared so the next admin
  visit checks in.
- Saving the Site title, tagline or site icon (`SettingService::set()`)
  sends a check-in after the response (`heartbeatAfterResponse()`, once per
  request, only when something changed). `autoSync()` also skips its hourly
  wait when what the hub was last told (`updates.identity_sent`) differs.

## Data tables (BH-109, Quick View)

- The toolbar draws a menu for every column filter with a set of values, in
  column order after Status, and keeps each in the address; the first page
  reads them back. Before, only `status` had a menu.
- Filter keys: `all` (the empty choice: "Any version"), and `options`, a
  callable returning `[value => label]` worked out when the table is shown,
  once per request (`Column::filterSpec()`); it also drives the request check.
- Quick View: a table's `quick_view` callable `(int $id, ?array $user):
  ?array` describes one row; `content.quick_view` (GET) returns it after the
  table's capability and area checks, as text and http(s) or root-relative
  URLs only (`TableActions::cleanView()`). The row gets a **Quick View** link
  and menu item, which opens a read-only dialog (the Quick Edit dialog's
  frame) with the title, icon or image, badges, sections and links.

## Apps (BH-110)

- An app that is active when it is upgraded from the Apps screen has its
  previous version's classes loaded in that request. Its `onUpgrade()` and
  `onActivate()` now run on the next request, before the app boots
  (`AppService::finishPendingUpgrade()`), so they run the new code. Before,
  they ran the old code with the new manifest and recorded the upgrade as
  done.
- After the upload swap, OPcache's copies of the app's files are dropped
  (`forgetCompiled()`), as the marketplace upgrade already did; the request
  right after an upgrade otherwise still ran the previous code for up to the
  OPcache revalidation time.

## Responses (BH-111)

- `Response::file($path, $downloadName, $contentType, $headers)` streams a
  file from disk with `Content-Length` and, with a name,
  `Content-Disposition: attachment`; `HEAD` gets headers only; a missing file
  is a 404. Output buffers are dropped first, so nothing pending corrupts
  the download.

## Docs and tests

- `docs/DATA-TABLE.md` (Filter menus, Quick View), `docs/APP-DEVELOPMENT.md`
  (onUpgrade always runs the new code), `docs/PUBLIC-ROUTES.md`
  (`Response::file`).
- `tests/Unit/Release12101Test.php`.
