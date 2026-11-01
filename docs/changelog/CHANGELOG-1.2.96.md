# Basehim 1.2.96

2026-11-01 · Patch release · installs on 1.2.95

The frontend admin bar: a core framework for managing the site from its public
pages, with a registration API for apps and themes. It also fixes BH-082
(widget areas started a session for every visitor) and shows the editor's
logo in white. No database changes. Settings use the existing settings table
(group `toolbar`), and user choices go in `users.meta` (`admin_bar`).
Documentation: [docs/ADMIN-BAR.md](../ADMIN-BAR.md).

## Architecture

| Part | File | Job |
|---|---|---|
| Registry | `app/Core/AdminBar/AdminBar.php` | Menus, items, panels, indicators, notification and search sources. Validation, ownership (namespaced ids, `forgetOwner`), ordering, visibility (capability, roles, context, `visible`), failure isolation (a throwing callable hides its component and is logged once to its owner's log), and the `run*` methods behind the AJAX actions |
| Context | `AdminBarContext.php` | What the page shows, captured from `RendersTheme::renderTheme()` / `notFound()` data (post, term, author, search), else the matched route and status. `matches()` conditions, and a session-keyed HMAC descriptor for AJAX |
| Settings | `AdminBarSettings.php` | Site settings (group `toolbar`) and user choices (`users.meta.admin_bar`), each value checked against its options. `allows()` decides the audience; `effective()` merges user choices the site permits |
| Core sections | `CoreItems.php` | Site, this page, New, search, notifications, display and account, registered through the registry. Links are checked with `AdminAreaPolicy::requiredFor()` and posts with `PostEditorService::canEdit()` |
| Renderer | `AdminBarRenderer.php` | Escaped markup for both modes, the script config, and an early inline margin so the page doesn't jump |
| Injector | `AdminBarInjector.php` | Called from `Router::emit()` before `SecurityHeaders` (so scripts get the nonce). Only GET HTML pages outside the admin, for a session user the settings allow. Skips the Customizer preview, `?bh_admin_bar=0`, the `X-Basehim-Admin-Bar: off` header (removed before sending) and the `admin_bar.show` filter. Marks the page `Cache-Control: private, no-store`, `Vary: Cookie` |
| AJAX | `app/Http/Controllers/Ajax/AdminBarActions.php`, `routes/ajax.php` | `admin.bar_panel`, `admin.bar_indicators`, `admin.bar_notifications`, `admin.bar_search`, `admin.bar_url` (GET) and `admin.bar_prefs` (POST), all rate-limited, with signed context verified |
| App API | `app/Core/Api/AdminBarApi.php`, `AppApi::adminBar()` | `menu`, `item`, `panel`, `indicator`, `notifications`, `search`, `remove`; ids namespaced to the app. No new `App` base-class methods (BH-014) |
| Theme API | `bootstrap.php` | `bh_admin_bar()`: the same API, ids under `theme.` |
| Front end | `admin/assets/css/admin-bar.css`, `admin/assets/js/admin-bar.js` | Top bar and floating control; dropdowns (click or hover, keyboard, Escape, outside click), nested items, panels with loading/error/refresh, lazy badges, notifications (sessionStorage, 2 min), quick search (Ctrl/⌘+K), Quick edit on `content.quick_edit*`, display choices, draggable button snapping to corners, auto-hide, phone sheets, reduced motion, dark/light/auto |
| Settings screen | `admin/views/settings/toolbar.php`, `SettingsSaver::toolbar()`, `SettingController`, `routes/admin.php`, `_sections.php` | Settings → Admin bar |
| Profile | `admin/views/profile/index.php`, `ProfileController::saveAdminBar()`, `POST /admin/profile/admin-bar` | A user's own mode, including turning the bar back on |

Hooks: `admin_bar.register` (action, once per request with `$ctx`, `$user`),
`admin_bar.show` (filter). Browser: `window.BasehimAdminBar`.

Smaller changes:

- `Response::removeHeader()` (case-insensitive).
- `Router` records the request path for `emit()`.
- `AppService::rollback()` also forgets an app's admin bar components.
- The `Application` singletons `AdminBar` and `AdminBarSettings`.

## BH-082: a session for every visitor

The frontend widget renderer's user resolver (1.2.93) called
`AuthService::currentUser()`, which builds `Session` and starts a PHP session.
Every page with a widget area sent visitors a `BASEHIMSESS` cookie and wrote a
session file. The resolver now answers null without an active session or
session cookie. Post pages still start one for the comment form's token
(BH-083, open).

## Editor logo

`block-editor.css`: the back button's logo is shown white
(`filter: brightness(0) invert(1)`) on its near-black background. The admin
bar uses the Basehim mark the same way on its dark scheme, and in its own
colours on the light scheme.

## Not offered (see Limits in the docs)

Revisions and duplicating are not offered: core has neither. Quick edit
covers simple fields only; categories, tags and the publish date link to the
editor.

## Tests

- `tests/Unit/AdminBarTest.php` (10): registry validation and ownership, tree
  ordering and filtering, failure isolation, dynamic items, indicators,
  panels, notifications, search, `forgetOwner`, panel data shape, URL checks,
  context matching and capture, the signed descriptor, settings checks.
- `tests/Integration/AdminBarTest.php` (10):
  - visitors get nothing and no session (BH-082);
  - admin pages are private and carry the nonce;
  - roles: subscriber, contributor (no edit for others' posts), editor;
  - the page menu on posts, pages, categories, search and 404 pages;
  - AJAX with forged or borrowed context, hidden components and subscribers;
  - search filtered by permission;
  - four example apps plus a broken one: refused definitions logged, throwing
    callables isolated, the `admin_bar.register` hook;
  - the opt-out header;
  - user choices and the profile;
  - site settings (off, roles, sections, colours, mode).
- Example apps in `docs/examples/admin-bar/` (page-stats, seo-check,
  comment-queue, site-health), checked on a local site.
