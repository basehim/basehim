# Basehim 1.2.97

2026-11-02 · Patch release · installs on 1.2.96

Four fixes. No database changes.

## BH-085: phone admin bar pushed Display and the account off the screen

At 782 px and below only `.bhab-menus--main` scrolled sideways, and the
right-hand group kept its width. With a few apps' menus (the four example apps
at 390 px), the Display and account menus were off the screen and couldn't be
reached, and an app's menu was hidden inside the left group's own scroll.

`admin/assets/css/admin-bar.css`:
- the whole `.bhab-bar` row scrolls, with both groups `flex: none`;
- on phones, status and text badges become a coloured dot; counts stay.

Dropdowns still open as full-width sheets.

## BH-084: one HTTPS check

`Request::serverIsSecure()` (1.2.90) honours `TRUSTED_PROXIES`, Cloudflare's
`CF-Visitor` and an https `APP_URL`, but 14 places still read
`$_SERVER['HTTPS']` directly. Behind a TLS-terminating proxy they built
`http://` addresses and left cookies without the Secure flag. Changed to
`Request::serverIsSecure()`:

- `bootstrap.php`: the session cookie's Secure flag.
- `Admin/AuthController`: the remember-me cookie's Secure flag.
- `Web/PostController`, `Web/PageController`, `Web/ResolveController` and
  `Web/AuthorController`: canonical URLs.
- `Helpers::url()`: without `APP_URL`.
- `McpController`, `ScheduleController` (cron URL) and `SettingsSaver`
  (permalinks).
- `CustomizerService`, `UpdateService` and `SystemController`.
- `SystemInfoService`: shown on the System page.

## BH-080: a content type's list offered filters it doesn't use

`TypeTableProvider::filterKeys()` always returned `category` and `tag`, so a
type without tags showed an empty Tags filter, and a type without categories
showed an empty Categories filter. The provider now learns its table
(`forTable()`, called by `TableService::page()` and
`admin/views/partials/datatable.php` when a provider has it) and offers only
the taxonomies the type uses. A filter for a taxonomy the type doesn't use is
ignored like any unknown filter.

## BH-029: settings screens stored any posted key

`SettingsSaver::generic()` (General, Reading, Writing, Discussion, SEO,
Appearance, Media) wrote every posted field into the screen's settings group.
It now stores only the fields the screen's form has: the field names of the
form, taken from the screen as it renders (`SettingController::sectionPage()`).
If the screen can't be rendered, only keys the group already has may change.
This applies to the AJAX save (`settings.save`) and the form route alike.

## Tests

- `tests/Integration/Release1297Test.php`:
  - unknown keys aren't stored on Reading, Discussion, SEO and Media (AJAX and
    form);
  - content type lists offer only their own taxonomy filters, and Posts keep
    both.
- `tests/Unit/Release1297Test.php`:
  - no direct `$_SERVER['HTTPS']` reads outside `Request`;
  - `X-Forwarded-Proto` counts only from a trusted proxy;
  - the phone bar CSS.
