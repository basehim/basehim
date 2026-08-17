# Basehim

**Open-source PHP CMS with REST API, Admin Dashboard, and built-in MCP Server for AI Agents.** Basehim gives developers authentication, RBAC, apps, themes, webhooks, and permission-scoped AI access in one modular CMS.

**Modern PHP. Simple deployment.** PSR-4 autoloading, no mandatory Composer setup, no build pipeline, and no background services. Upload the files, run the installer, and start building.

![PHP](https://img.shields.io/badge/PHP-8.1%2B-blue)
![MySQL](https://img.shields.io/badge/MySQL-5.7%2B-orange)
![License](https://img.shields.io/badge/License-MIT-green)
[![CI](https://github.com/basehim/basehim/actions/workflows/ci.yml/badge.svg)](https://github.com/basehim/basehim/actions/workflows/ci.yml)

[basehim.com](https://www.basehim.com) · [Docs](https://www.basehim.com/docs) · [API reference](https://www.basehim.com/docs/api-reference) · [Getting started](https://www.basehim.com/docs/getting-started)

---

## Why Basehim

Most modern CMSs assume SSH, Composer and a deploy pipeline. Basehim assumes
the hosting real clients actually buy — cPanel, Plesk, plain Apache — and still
gives you a first-class API surface and native agent support.

- **AI-ready by default** — a built-in MCP server at `/mcp`. Point Claude, or
  any MCP client, at your site and it registers itself. No client id, no secret.
- **Scoped, revocable access** — agents get OAuth 2.1 tokens carrying explicit
  scopes. A token issued to read posts cannot touch users.
- **API-first** — a full REST API over posts, pages, media, taxonomies, menus,
  comments, users and settings.
- **Zero-build deployment** — pure PHP. Upload a zip, run the installer.
- **Apps and themes** — drop a folder in, activate, done. Marketplace built in,
  every download checksum-verified.
- **A readable micro-framework** — autoloader, DI container with autowiring,
  router, view engine, hooks/events, JWT, CSRF, sessions, file cache.

## AI and agents

This is the part worth reading first.

### MCP server

Basehim exposes a Model Context Protocol endpoint at the root of your domain:

| Method | Path   | Purpose                                          |
| ------ | ------ | ------------------------------------------------ |
| `GET`  | `/mcp` | Discovery — transport, auth methods, metadata URL |
| `POST` | `/mcp` | JSON-RPC 2.0 endpoint                             |

Add `https://your-site.com/mcp` as a custom connector in an AI assistant and it
handles registration itself. The endpoint accepts either an OAuth token or a
bearer API key.

Resources are addressed with a `basehim://` URI scheme — `basehim://site/info`,
`basehim://post/{id-or-slug}` — and are enumerated through `resources/list`.

### OAuth 2.1 — built for agents, not humans clicking through consoles

Agent auth sits at the domain root, outside `/api/v1`:

| Method       | Path                                        | Purpose                        |
| ------------ | ------------------------------------------- | ------------------------------ |
| `GET`        | `/.well-known/oauth-protected-resource`     | Resource metadata (RFC 9728)   |
| `GET`        | `/.well-known/oauth-protected-resource/mcp` | Path-suffixed variant          |
| `GET`        | `/.well-known/oauth-authorization-server`   | Server metadata (RFC 8414)     |
| `GET`        | `/.well-known/openid-configuration`         | Same metadata, OIDC path       |
| `POST`       | `/oauth/register`                           | Dynamic client registration    |
| `GET`/`POST` | `/oauth/authorize`                          | Consent screen and approval    |
| `POST`       | `/oauth/token`                              | Exchange a code, or refresh    |

Grants: `authorization_code` and `refresh_token`, with PKCE (`S256`).

Dynamic client registration is the point — there is nothing to configure by
hand before an agent can connect.

### Scopes

Every token carries scopes, and a token issued for one thing cannot do another:

```
posts:read        posts:write
taxonomies:read   taxonomies:write
media:read        comments:read      comments:write
settings:read     users:read
```

Installed apps can contribute their own scopes, so your site's list may be
longer. Whatever your site advertises is authoritative — fetch
`/.well-known/oauth-authorization-server` to see it.

### Desktop agent API

For companion apps running on a machine rather than in a chat window:

| Method | Path                               | Purpose                          |
| ------ | ---------------------------------- | -------------------------------- |
| `POST` | `/api/v1/agents/register`          | First contact; mints the token   |
| `POST` | `/api/v1/agents/{uuid}/heartbeat`  | Check in and collect commands    |
| `POST` | `/api/v1/agents/{uuid}/commands/{id}/ack` | Acknowledge a command     |

These authenticate with a per-agent bearer token validated against the `{uuid}`
in the path, not a user session. Apps queue commands through `AgentService` —
see [`docs/AGENT-API.md`](docs/AGENT-API.md).

### Token hygiene

A token is a password. Issue the narrowest scope that does the job, use a
separate token per integration so one can be revoked without disturbing the
others, and keep tokens out of client-side code and screenshots. Revoking in
the admin takes effect immediately.

## REST API

Base URL `https://your-site.com/api/v1`.

- Success responses are wrapped: `{"data": …}`
- Errors follow RFC 7807 problem details: `{"type", "title", "status", "detail"}`
- **Single items are fetched by slug; updates and deletes address them by id.**
  `GET /posts/hello-world` but `DELETE /posts/12`. This catches people out.
- List endpoints accept `page`, `per_page`, `status`, `q` and `author_id`.

Reading published content needs no authentication. Anything that writes, or
reads drafts, does.

```bash
# Public read — no auth
curl https://your-site.com/api/v1/posts

# API key
curl -H "Authorization: Bearer basehim_…" https://your-site.com/api/v1/me

# JWT
curl -X POST https://your-site.com/api/v1/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"username":"admin","password":"…"}'

# Create a post
curl -X POST https://your-site.com/api/v1/posts \
  -H "Authorization: Bearer basehim_…" \
  -H 'Content-Type: application/json' \
  -d '{"title":"Hello","content":"<p>World</p>","status":"published"}'
```

Three ways in, for three different callers:

| Method | Header / flow | Best for |
|---|---|---|
| API key | `Authorization: Bearer basehim_…` | Scripts, server-to-server |
| JWT | `POST /auth/login`, refresh at `/auth/refresh` | Apps acting for a signed-in person |
| OAuth 2.1 | Dynamic client registration | AI agents over MCP |

The full endpoint list — content, media, taxonomies, comments, menus, users,
settings, apps, cache and scheduling — is at
[basehim.com/docs/api-reference](https://www.basehim.com/docs/api-reference),
and mirrored in the admin at `/admin/api/reference`.

## Requirements

- PHP 8.1+ with `pdo_mysql`, `mbstring`, `fileinfo`, `gd`, `zip`
- MySQL 5.7+ or MariaDB 10.3+
- Apache with `mod_rewrite` (works on default cPanel)
- ~30 MB disk, plus space for uploads

## Install

1. **Upload** the contents of this repository to your document root
   (e.g. `public_html/`).
2. **Create a MySQL database** and grant a user full privileges.
3. **Visit your domain** — you'll be redirected to `/install.php`.
4. Work through the wizard: database, site details, admin account, optional
   starter content.
5. **Delete `install.php`** when it finishes. The installer reminds you.

Log in at `/admin/login`.

<details>
<summary>Manual install</summary>

```bash
rsync -avz basehim/ user@server:/path/to/webroot/

cp .env.example .env
# Edit DB_*, APP_URL, then generate the two secrets:
php -r "echo 'APP_KEY=' . bin2hex(random_bytes(16)) . PHP_EOL;"
php -r "echo 'JWT_SECRET=' . bin2hex(random_bytes(32)) . PHP_EOL;"

# Migrations apply in filename order
for f in database/migrations/*.sql; do mysql -u user -p mydb < "$f"; done

chmod -R 775 storage/ content/
```

Generate a password hash with:

```bash
php -r "echo password_hash('YourPassword', PASSWORD_BCRYPT, ['cost' => 12]) . PHP_EOL;"
```

</details>

Subdirectory installs work out of the box — Basehim detects its own base path.

`DB_PREFIX` lets several sites share one database, which matters on shared
hosting where you get a fixed number. Set it before installing and leave it
alone afterwards.

## Directory map

```
basehim/
├── index.php              # Front controller (all requests funnel here)
├── install.php            # Web installer (delete after use)
├── bootstrap.php          # Autoloader, env, sessions, boots the app
├── .htaccess              # Apache rewrites + security headers
├── .env                   # Your environment — git-ignored, NEVER commit
├── .env.example           # Template to copy to .env
│
├── app/
│   ├── Core/              # Router, Container, View, Cache, JWT, App base class
│   ├── Http/
│   │   ├── Controllers/   # Admin/, Api/, Web/
│   │   └── Middleware/    # Authenticate, CheckCapability, Cors
│   ├── Repositories/      # DB access layer (PDO)
│   └── Services/          # Business logic (PostService, AppService, …)
│
├── admin/views/           # Admin UI templates (Tailwind)
├── api/v1/                # REST API entry
├── config/                # app, database, auth, capabilities, cms
├── content/
│   ├── apps/              # Drop-in apps (manifest in app.json)
│   ├── themes/            # Drop-in themes (manifest in theme.json)
│   └── uploads/           # Media library
├── database/migrations/   # SQL migrations, applied in filename order
├── docs/                  # App, permission, and agent API guides
├── routes/                # api.php, admin.php, web.php
└── storage/               # Runtime: cache/, logs/, sessions/ (must be writable)
```

Everything under `storage/` and `content/uploads/` is runtime state — the
directories are tracked, their contents are git-ignored.

## Apps

An app is a folder in `content/apps/` with an `app.json` manifest. Install by
uploading a zip from `/admin/apps`, from the built-in marketplace, or by
dropping the folder in — it is auto-detected.

```
content/apps/my-app/
├── app.json                 # Manifest (required)
├── src/
│   └── App.php              # Entry class extending App\Core\App
├── views/                   # (optional) app-owned templates
└── assets/                  # (optional) bundled css/js/images
```

**app.json**

```json
{
    "name": "My App",
    "vendor": "myco",
    "slug": "my-app",
    "version": "1.0.0",
    "description": "What my app does.",
    "author": "Me",
    "namespace": "MyCo\\MyApp",
    "src": "src",
    "entry": "App"
}
```

### Writing an app

Extend `App\Core\App` and implement `boot()`. Every helper below is provided by
the base class — no need to dig into the DI container.

```php
<?php
namespace MyCo\MyApp;

class App extends \App\Core\App
{
    /* -- Lifecycle (all optional) -- */
    public function onInstall(): void   { /* first detection */ }
    public function onActivate(): void  { /* admin enabled it */ }
    public function onDeactivate(): void{ /* admin disabled it */ }
    public function onUninstall(): void { /* about to be removed */ }

    /* -- Main entry: runs on every request while active -- */
    public function boot(): void
    {
        // Hooks
        $this->addAction('post.created', [$this, 'onPostCreated']);
        $this->addFilter('post.content', fn($content) => $content . '<p>👋</p>');

        // Custom routes
        $this->get('/hello', fn() => 'Hello from an app!');
        $this->post('/admin/myapp', [$this, 'saveSettings']);

        // Dashboard widget
        $this->registerWidget('stats', [
            'title'  => 'Site Stats',
            'render' => fn() => '<p>Hello 👋</p>',
        ]);

        // Admin sidebar item
        $this->addAdminMenu([
            'url' => '/admin/myapp', 'label' => 'My App', 'icon' => 'fa-star',
        ]);
    }
}
```

### Built-in helpers

| Helper | What it does |
|---|---|
| `addAction($tag, $cb, $priority?, $args?)` | Register an event listener |
| `addFilter($tag, $cb, $priority?, $args?)` | Register a value transformer |
| `doAction($tag, ...$args)` | Fire your own event |
| `applyFilters($tag, $value, ...)` | Run a value through your filter chain |
| `get($pattern, $handler)` / `post(...)` / `route([...], ...)` | Register HTTP routes |
| `routeGroup($attrs, $cb)` | Group routes with shared prefix/middleware |
| `addAdminMenu(['url'=>'…','label'=>'…','icon'=>'…'])` | Add a sidebar item |
| `registerWidget($key, $def)` | Contribute a widget (editor/frontend/dashboard) |
| `getSetting($key, $default = null)` | Read an app-scoped setting |
| `setSetting($key, $value)` | Write an app-scoped setting |
| `deleteSetting($key)` / `allSettings()` / `dropAllSettings()` | Manage settings |
| `table($name)` | Prefixed, app-namespaced table name (`app_{slug}_{name}`) |
| `db()` | The PDO-wrapped `Database` instance |
| `cache()` | File-based key/value cache |
| `make(AgentService::class)` | The desktop `AgentService` |
| `log($msg, $context?, $level?)` | Log line tagged with your app slug |
| `view($template, $data = [])` | Render a PHP template from `views/` |
| `asset($relativePath)` | URL to a bundled asset under `assets/` |
| `schema($sql)` | Run a CREATE / ALTER (idempotent table setup) |
| `make($class)` | Resolve anything from the container |
| `config($key, $default = null)` | Read a config value |
| `request()` / `session()` | Current request / session |
| `slug()` / `path()` / `manifest()` | App metadata |

Settings written via `setSetting()` are stored in the `settings` table under
the group `app:{slug}`, so they can never collide with core or other apps. On
uninstall, all app-owned settings are removed for you automatically.

Bundled assets are served by Basehim itself at
`/content/apps/{slug}/assets/{path}`, with ETags and correct MIME types — no
route needed. Use `$this->asset('css/style.css')` to build the URL.

### Permissions

Apps declare what they need in `app.json`, and the admin sees and approves that
list before activation. See
[`docs/APP-PERMISSIONS.md`](docs/APP-PERMISSIONS.md).

Each app with an admin area also gets an `access_app:{slug}` capability, so
access can be granted or denied per role and per user.

### Installing an app

**From the admin:** `/admin/apps` → **Upload app** → pick a `.zip`. The
installer validates the archive is readable and under 16 MB, finds `app.json`
at the archive root or one level deep, rejects archives containing `..` or
absolute paths (zip-slip protection), validates the manifest, refuses to
overwrite an existing app with the same slug, extracts to a temp directory,
then atomically renames into place.

The app appears as **inactive**. Click **Activate** to fire `onActivate()`,
where you'd typically create your tables.

**From the marketplace:** `/admin/apps/marketplace` — browse and install
directly, every download SHA-256 verified.

### Lifecycle

- **Deactivate** — stops booting the app; data is preserved.
- **Delete** — fires `onUninstall()`, removes app-scoped settings, drops the DB
  row, and removes files from disk.

### Worked examples

- `content/apps/wp-migrator/` — the bundled WordPress Migrator, and the most
  complete example in the tree: a batched multi-step wizard, its own tables
  created on activate and dropped on uninstall, admin views with a sidebar
  entry, app-scoped settings, and bundled assets.
- `docs/examples/system-monitor/` — a compact reference app showing lifecycle
  hooks, a scheduled task, the permission manifest, and a shipped desktop
  module.

Full guides live in [`docs/`](docs/):
[app development](docs/APP-DEVELOPMENT.md),
[the app API](docs/APP-API.md),
[permissions](docs/APP-PERMISSIONS.md),
[the agent API](docs/AGENT-API.md).

## Themes

A theme is a folder under `content/themes/` with a `theme.json` manifest and a
`templates/` directory. No compile step, no framework to learn — ship one by
zipping a folder.

```
content/themes/my-theme/
├── theme.json
├── templates/
│   ├── index.php           # Post feed
│   ├── single.php          # Single post
│   ├── page.php            # Static page
│   └── partials/
└── assets/
```

Themes can declare widget areas through a `widget_areas` map in `theme.json`,
which then appear on `/admin/widgets/areas` for placement.

Two ship with core: `default` and `dark-night`.

## Scheduling

Scheduled tasks are registered by apps and run through a cron entry point:

```cron
*/5 * * * * curl -s "https://your-site.com/api/v1/schedule/run?token=…"
```

`/schedule/run` sits outside the authenticated group deliberately — a crontab
sends no cookies and holds no token, so it is guarded by an unguessable token
instead. List tasks with `GET /api/v1/schedule`, or run one on demand with
`POST /api/v1/schedule/{app}/{key}/run`.

## Security

- All requests funnel through `index.php`. `.htaccess` blocks direct access to
  `app/`, `config/`, `storage/`, `database/`, `.env` and `bootstrap.php`.
  **If you run nginx you must replicate those denials yourself.**
- Passwords hashed with bcrypt, cost 12.
- CSRF tokens on every form (`_csrf` field, `X-CSRF-Token` header).
- JWTs (HS256) are short-lived; refresh tokens are stored as SHA-256 hashes and
  can be revoked.
- API keys are stored as SHA-256 hashes — the plaintext key is shown once, at
  creation, and never again.
- Sessions are file-based with `HttpOnly`, `SameSite=Lax`, and `Secure` when
  HTTPS is detected.
- SQL via PDO prepared statements throughout, with emulation disabled.
- Upload validation: MIME type, extension and size limit, configurable in
  `config/cms.php`.
- App zips are checked for zip-slip before extraction.

Reporting a vulnerability and the operator hardening checklist:
[SECURITY.md](SECURITY.md).

## Contributing

Bug reports and pull requests are welcome. See
[CONTRIBUTING.md](CONTRIBUTING.md) for local setup, code style, and the
compatibility constraints that come with targeting shared hosting: no Composer
requirement, no build step, no daemons, PHP 8.1 floor.

## License

Released under the [MIT License](LICENSE).
