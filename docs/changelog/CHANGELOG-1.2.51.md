# Basehim 1.2.51 (candidate)

Security and correctness release from the repository-wide audit. **App admin
pages are closed to accounts without access to the app**, **MCP acts with the
owner's role**, SVG uploads that can run script are refused, comment links
and redirects are safe, and the REST API stops handing out credentials and
error details. No database changes.

## App admin pages require access to the app

Routes an app registers with `adminGet()` / `adminPost()` ran behind
`Authenticate` only. `AdminAreaPolicy`, which turns an app's menu URL into
its access capability `access_app:{slug}`, was never applied to them, so any
signed-in account — a subscriber included — could open every app's admin
pages and post to its admin endpoints. On a Cloudhim hub a subscriber could
read every connected site's key from `/admin/cloudhim/sites.json`.

- `adminGet()` / `adminPost()` now run behind core's admin middleware:
  `Authenticate`, then `AdminAreaPolicy` (`App::ADMIN_MIDDLEWARE`).
- An app admin route that no rule covers (outside the menu URL, or an app
  without a menu) falls back to `access_app:{owner}`, using the route owner
  the router records since 1.2.50 (`Router::currentOwner()`).
- Who holds `access_app:{slug}` is unchanged: roles with `manage_apps` or
  `manage_options`, and anyone granted it under Users → Access. The sidebar
  already hid app menus from everyone else; the URLs now match.
- For app developers: to open an admin path to more roles, map it to a
  capability with the `admin.area_policy` filter (see APP-DEVELOPMENT.md).

## MCP acts with the owner's role

The MCP tools checked API scopes only; the REST API also checks the owner's
capabilities (since 1.2.43). Now:

- each tool requires the capability the admin and REST API require: editing
  or trashing someone else's post needs `edit_others_*` / `delete_others_*`,
  editing a live post needs publish rights, and comments, users, settings,
  menus, media and taxonomy tools need `moderate_comments`, `manage_users`,
  `manage_settings`, `manage_menus`, `upload_media`, `manage_taxonomies`;
- unpublished items (drafts, pending, private) are visible only to their
  author and to accounts that can edit others' posts (editors and up);
- a post created by someone without publish rights is saved as *pending*
  instead of *published*, and its author is the token's owner;
- API keys and OAuth tokens of a suspended, archived or deleted account are
  refused;
- at the OAuth consent screen, scopes with no capability rule (every scope an
  app adds through `mcp.scopes`) are granted only to administrators
  (`manage_options`). Apps can map their scopes with the new
  `mcp.scope_capabilities` filter.

## SVG uploads

SVGs are allowed by default and authors can upload. Opened on its own an SVG
is a document with the site's origin, so one carrying `onload=` or `<script>`
could act with the session of whoever opened it.

- New `App\Services\SvgGuard` parses the file as a browser would (internal
  entities expanded, no network) and refuses script-capable elements, `on*`
  attributes, `javascript:`/`vbscript:` values, non-image `data:` links,
  links changed by animation, external and parameter entities, and stylesheet
  processing instructions. Illustrator and Inkscape exports pass.
- `MediaService` applies it to every upload path (admin, REST, apps'
  `importFile`); `.svgz` is refused.
- `/uploads/...` responses carry `X-Content-Type-Options: nosniff`, and SVGs a
  `Content-Security-Policy` with `sandbox`, so files uploaded before this
  release cannot run script either when served through Basehim.
- The shipped `.htaccess` adds the same policy for SVGs Apache serves
  directly. The updater never changes an existing `.htaccess`; see the release
  notes for the optional block.

## Comments

- A commenter's website is stored only as an `http(s)` URL with a host (a bare
  domain gets `https://`). Before, `javascript:` was stored as typed and the
  default theme printed it in `href`. The default theme also skips such values
  already stored. Applies to the form, the REST API and apps
  (`CommentService::create()`, `CommentService::safeAuthorUrl()`).
- A failed comment post (bad token, comments closed) redirected to
  `redirect_to` as given; only same-site paths are honoured now.

## REST API

- `GET /api/v1/settings` and `PUT /api/v1/settings` return credentials (SMTP
  password, update-service key, signing secrets — any key naming a password,
  secret, key, token or DSN) as `"[redacted]"`, as MCP's `get_settings`
  already did. Sending `"[redacted]"` back leaves the stored value unchanged.
- `GET /api/v1/users/{id}` returns the full record only to that user and to
  user managers; everyone else gets the public profile (id, display name, bio,
  avatar).
- A 5xx response no longer includes the exception message unless debugging is
  on; it is logged as before. 4xx messages are unchanged.
- A bearer token with an algorithm Basehim does not implement (`alg: none`) is
  a 401, not a 500; tokens without an expiry are refused.
- A `JWT_SECRET` copied from `.env.example` or `config/auth.php` (or shorter
  than 32 characters) is no longer used to sign tokens; a key derived from the
  site's own secret is used instead. Installer-made sites already have a
  random 64-character secret and are unaffected.

## Fixes

- **WP Migrator**: four classes the app uses (`UrlMapper`, `ContentFixer`,
  `MediaFilter`, `Downloader`) were missing from the release, so an import
  failed with a fatal error at the media or content step, and the
  `/wp-content/uploads/` redirect did nothing. Restored.
- **Profile photos**: changing or removing one now clears the cache (it asked
  for a cache class that does not exist, and the error was swallowed).
- The repository's `.htaccess` no longer carries the "Basehim canonical URL"
  block (forced HTTPS and `www.`), which belongs to a particular site and is
  written by Settings → Permalinks. Existing sites are unaffected.

## Development

- The test suite is in the repository (`php tests/run.php`), and CI runs it
  against MySQL 8.0 on PHP 8.1 and 8.3. `/tests` is refused over HTTP and left
  out of release archives.

## Tests

New: `tests/Integration/{AppAdminAccessTest,McpAuthorizationTest,CommentSafetyTest,SvgUploadTest,ApiHardeningTest}.php`,
`tests/Unit/{SvgGuardTest,JwtTest,BundledAppFilesTest}.php`. Each fails
without its fix. Core suite 184 passed; `devtools/regress.php` 63 passed.

## Files

New: `app/Services/SvgGuard.php`,
`content/apps/wp-migrator/src/{UrlMapper,ContentFixer,MediaFilter,Downloader}.php`.

Changed: `app/Core/{App,Router,Jwt,ErrorHandler}.php`,
`app/Http/Middleware/{AdminAreaPolicy,Authenticate}.php`,
`app/Http/Controllers/Api/{McpController,OAuthController,SettingController,UserController}.php`,
`app/Http/Controllers/Web/{CommentController,UploadController}.php`,
`app/Services/{AuthService,AvatarService,CommentService,MediaService,SettingService}.php`,
`content/themes/default/templates/single.php`, `server.php`, `README.md`,
`docs/APP-DEVELOPMENT.md`, `index.php`, `install.php`; `.htaccess` (new
installs only).
