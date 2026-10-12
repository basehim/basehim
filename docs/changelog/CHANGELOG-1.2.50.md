# Basehim 1.2.50

Admin housekeeping: **each site's admin now says which site it is**, the admin
is marked no-index everywhere, **Settings save without a page load** with the
spinner inside the Save button, and **API → Reference lists every endpoint
the site actually has**.

## Admin tab titles and icon

Every admin page was titled "{Page} - Basehim" with the Basehim icon, so with
several sites open the tabs were indistinguishable.

- Titles are now `{Page} - {Site name} - Basehim`: *Admin Dashboard - Circuits
  DIY - Basehim*, *Apps - Circuits DIY - Basehim*, *General Settings - …*. The
  sign-in screens follow the same pattern (*Sign in - Circuits DIY - Basehim*).
  The site name is left out while it is still "Basehim".
- The tab icon is the site's own icon when one is set (Customizer → Site
  identity → Site icon); otherwise the Basehim icon as before.
- One partial builds the head for every admin layout
  (`admin/views/partials/admin-head.php`, logic in `App\Core\AdminHead`).
- "Ai Settings" and "Seo Settings" are now "AI Settings" and "SEO Settings";
  the API screens are "API Overview", "API Keys", "API Reference", "MCP Server".

## Admin never indexed

Checked: only the sign-in layout had a robots meta tag, and `robots.txt`
disallows `/admin/` (which keeps crawlers out but does not stop a linked URL
being listed). Now:

- every admin layout prints `<meta name="robots" content="noindex, nofollow">`;
- every response under `/admin` and from the AJAX gateway (`/ajax`) carries
  `X-Robots-Tag: noindex, nofollow` (`Router::emit()`), which also covers JSON,
  CSV exports and redirects. Public pages are untouched.

## Settings save through the AJAX gateway

All Settings screens — General, Reading, Writing, Discussion, Permalinks,
Media, SEO, AI Agents, Email, Authentication — save without leaving the page:

- New gateway action `settings.save` (`manage_settings`, and the screen's own
  admin-area rule). The browser sends the form exactly as it would have
  posted it, URL-encoded, and it is decoded the way PHP decodes a POST, so the
  hidden "0" before each checkbox works as before.
- Validation and messages are shared with the form routes through the new
  `App\Services\SettingsSaver` (the old per-screen savers moved there
  unchanged). `POST /admin/settings/{tab}` remains as the no-script fallback.
- After saving, the message replaces the page's banner; fields the server
  adjusted show the stored value (an empty From Email becomes noreply@…, an
  out-of-range port 587); panels worked out from the settings (the .htaccess
  rules, the sender-domain warning, the AI status table) are refreshed;
  renaming the site retitles the tab and the sidebar at once.
- A refused value (a timezone not in the list, a bad date format) saves
  nothing and shows the reason, as the form did.

## Loader inside the button

`bhLoader(button, 'Saving…')` now puts the spinner and its words *inside* the
button instead of a pill beside it: the button is disabled while the request
runs, shows ✓ Saved or a red ! Not saved, then gets its own label back. Used
by the Settings screens, the post editor's **Save draft** and the media
library's **Delete**. Other anchors (the media preview) keep the pill; a
`place` option still asks for it. Styles: `.bh-btn-busy` in `ui.css`.

## API → Reference

- **Missing endpoints added**: site time (`/time`, `/time/convert`,
  `/timezones`), the OAuth and discovery endpoints the MCP server uses
  (`/.well-known/oauth-*`, `/oauth/register|authorize|token`), the AI and
  discovery files (`robots.txt`, `llms.txt`, `llms-full.txt`,
  `/.well-known/ard.json`, `/ai/recent-articles.json`, `sitemap.xml`, `feed`),
  the AJAX gateway, and `POST /admin/settings/{tab}`.
- **Read from the running site**, so they cannot fall behind:
  - routes apps register outside the admin, grouped by app (the router now
    records which app's `boot()` added a route: `Router::routes()`);
  - every AJAX gateway action, core's and each app's, with its method,
    capability, input fields and admin screen (`InputSchema::describe()`);
  - every MCP tool, including app-contributed ones, with the scope it needs
    (`McpController::allTools()`).
- Fixed: URLs ignored the install folder and HTTPS behind a proxy (they now
  use the MCP server's own address); `/posts` listed filters that do not exist
  (it takes `?q` and `?author_id`); theme and update routes sat inside "Posts
  (admin)"; the search box broke on descriptions containing quotes.
- **MCP Server — connect an AI assistant**: the endpoint shows the full URL;
  the code boxes no longer push the card wider than a phone screen; the tool
  list is a scrollable box; and code boxes across the admin get a scrollbar you
  can see (macOS and phones hide overlay scrollbars, so long lines looked cut
  off). Same grid fix on API → MCP Server.

## Tests

`tests/Integration/SettingsAdminTest.php` (5 tests: settings.save semantics,
refused values, email clean-up, permissions, admin titles/icon/robots).
Core suite 158 passed; `devtools/regress.php` 63 passed.

No database changes.

## Files

New: `app/Core/AdminHead.php`, `app/Services/SettingsSaver.php`,
`app/Http/Controllers/Ajax/SettingsActions.php`,
`admin/views/partials/admin-head.php`, `admin/assets/js/settings-save.js`.

Changed: `admin/views/layouts/{app,auth,fullscreen,signin}.php`,
`admin/views/settings/{_nav,ai,email,permalinks}.php`,
`admin/views/api/{reference,mcp}.php`, `admin/assets/css/ui.css`,
`admin/assets/js/{block-editor,media-library}.js`, `app/Core/Router.php`,
`app/Core/Ajax/InputSchema.php`, `app/Services/AppService.php`,
`app/Http/Controllers/Admin/{SettingController,ApiController}.php`,
`app/Http/Controllers/Api/McpController.php`, `routes/ajax.php`,
`docs/ADMIN-UI.md`, `docs/APP-AJAX.md`, `index.php`, `install.php`.
