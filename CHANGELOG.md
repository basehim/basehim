# Changelog

All notable changes to Basehim are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and
this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- `LICENSE` (MIT), `CONTRIBUTING.md`, `SECURITY.md`, and this changelog.
- GitHub issue and pull request templates, plus a CI workflow that syntax-checks
  every PHP file on 8.1–8.4, applies all migrations against MySQL 8, and fails
  the build if an `.env` or hardcoded credential is ever committed.
- `.gitignore` and `.gitattributes`.

### Removed — legacy naming (breaking)

The NovaCMS and "plugin" vocabulary is gone from the codebase entirely. What
this removes, and what to check before upgrading a live site:

- **Deleted alias classes:** `App\Core\Plugin`, `App\Services\PluginService`,
  `App\Http\Controllers\Admin\PluginController`,
  `App\Http\Controllers\Web\PluginAssetController`. Apps must extend
  `App\Core\App`.
- **`plugin.json` manifests are no longer read.** Rename to `app.json`.
- **`Plugin` is no longer tried as an entry class.** Use `App`, or name it
  explicitly in the manifest's `entry` key.
- **The `plugin:{slug}` settings group is no longer read as a fallback.** Any
  app installed before 1.34 whose settings still live in that group will read
  as unset. This fails *silently* — check before upgrading.
- **Removed the `/content/plugins/{slug}/assets/*` route** and the
  `content/plugins/` directory scan.
- **Removed `legacyTable()` and `renameLegacyTable()`** from the app base class.
- **Removed the dead `/admin/apps/{slug}/migrate` route** and
  `migrateToAppsDir()`. Since `content/plugins/` was no longer scanned, source
  and destination were always identical and the action could never move
  anything.
- **Session cookie renamed** `NOVASESS` → `BASEHIMSESS`; everyone is logged out
  once on upgrade.
- **Log files renamed** `nova-YYYY-MM-DD.log` → `basehim-YYYY-MM-DD.log`.
- **Browser globals renamed** `window.NovaMediaLibrary` →
  `window.BasehimMediaLibrary`; localStorage key `nova.sidebar.collapsed` →
  `basehim.sidebar.collapsed`.
- **wp-migrator's tables renamed** `plugin_wpmig_*` → `app_wpmig_*`. They are
  recreated on activation and only hold in-flight migration state, but do not
  upgrade mid-migration.
- **`AccessControl::pluginList()` / `pluginMenuUrls()` removed** — use
  `appList()` / `appMenuUrls()`.

The CloudHim marketplace endpoint paths (`/api/v1/cloudhim/plugins`,
`plugin-download`) are **deliberately unchanged** — they are the hub's public
API contract, not Basehim's naming.

### Fixed — bugs found during the legacy sweep

- **`window.NovaIcon` never existed.** `icons.js` exposes `window.BasehimIcon`,
  so every icon in the wp-migrator wizard silently fell back to Font Awesome.
- **`NOVA_DEBUG` was never defined anywhere**, leaving the 500 error page's
  debug branch permanently dead. It now reads `APP_DEBUG`.
- **`AppService::marketplaceBrowse()` read `$json['plugins']` unguarded.** An
  empty result set omits the key, producing the `foreach() argument must be of
  type array|object, null given` warning at `AppService.php:1329`. Now guarded
  and tolerant of both `apps` and `plugins` response keys.
- **The installer's partial-install cleanup named the pre-1.43 `plugins` table**
  and omitted every table added after migration 001 (`api_keys`, `apps`,
  `scheduled_tasks`, `password_resets`, `auth_login_attempts`,
  `user_activity_log`). A retried install tripped over leftovers from its own
  first attempt. It also ignored `DB_PREFIX`.
- **`Wizard::__construct()` type-hinted `Plugin`** in the `Basehim\WpMigrator`
  namespace — a class that never existed there — so constructing the wizard
  threw a `TypeError`.
- **`AppController::index()` contained an empty `foreach` loop** doing nothing.
- **`docs/AGENT-API.md` documents `$this->agents()`**, which is not implemented
  on the app base class. Flagged inline in the doc; the service still works
  through the container.

### Fixed
- Version was reported inconsistently in three places: `index.php` said
  `1.44.2`, `install.php` said `1.42.0`, and `config/app.php` hardcoded
  `1.0.0`. `BASEHIM_VERSION` in `index.php` is now the single source of truth
  and the other two derive from or match it.
- `config/app.php` still defaulted `APP_NAME` to `NovaCMS`.
- Documentation referred to `content/plugins/`, `plugin.json`, and
  `App\Core\Plugin` throughout; the current names are `content/apps/`,
  `app.json`, and `App\Core\App`.
- README pointed at two example plugins (`hello-world`, `nova-greeter`) that do
  not exist in the tree, and used `novacms/` in install paths.
- README claimed app settings are stored under the `plugin:{slug}` group; they
  have been written to `app:{slug}` since 1.34, with the old group read as a
  fallback.

### Security
- Removed a stray `update/` directory that had been committed containing live
  production database credentials and a JWT signing secret for an unrelated
  site. **Anyone who had access to that directory should treat those
  credentials as compromised** — see the note under [Known issues](#known-issues).
- Removed committed session files from `storage/sessions/`, which contained a
  valid super-admin session and CSRF token.

## Known issues

- **Missing migration `010`.** `app/Core/Application.php` documents a migration
  `010` that relocates pre-1.43 `content/plugins/` installs into
  `content/apps/`. No such file exists in `database/migrations/`, so upgrading
  sites must move that directory by hand. Either add the migration or correct
  the comment.
- **Migration numbering gaps.** There are two `002_` files and no `004_`.
  Harmless while migrations are applied in filename order, but worth tidying.
- **`AppService.php:1329`** emitted `foreach() argument must be of type
  array|object, null given` in the logs shipped with this snapshot. The null
  case is unguarded.

## Earlier releases

Release history before this changelog was not recorded. The schema evolution is
readable from `database/migrations/`:

| Migration | Introduced |
| --- | --- |
| `001_initial_schema.sql` | Posts, pages, taxonomies, media, comments, users, menus, settings |
| `002_api_keys.sql` | API key authentication |
| `002_password_resets.sql` | Password reset flow |
| `003_user_activity_log.sql` | Activity and audit logging |
| `005_fix_post_status.sql` | Post status correction |
| `006_otp_attempts.sql` | OTP rate limiting |
| `007_apps.sql` | App registry |
| `008_scheduled_tasks.sql` | Scheduled tasks |
| `009_app_permissions.sql` | App permission model |

Notable named changes visible in the code:

- **1.43.0** — plugins renamed to apps; `content/plugins/` → `content/apps/`.
- **1.34** — app settings group changed from `plugin:{slug}` to `app:{slug}`.

<!--
Maintainer note: the entries above are reconstructed from the codebase, not from
release notes. Correct them against your real history before the first public
release, then delete this comment.
-->
