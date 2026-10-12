# Basehim 1.2.43 — security update

**Upgrade every site.** This release fixes REST API authorization that let any
signed-in account, down to a subscriber, rewrite, unpublish or delete any post
or page. It also closes a reopenable installer. Nothing needs to be configured
after updating. Read "Behaviour changes" below if you have integrations
that use the REST API.

## Critical

### REST API writes checked sign-in, not permissions

`POST/PUT/PATCH/DELETE /api/v1/posts` and `/pages`, the term endpoints and the
media endpoints only checked that *someone* was signed in. With a JWT from
`/api/v1/auth/login`, a subscriber could edit, unpublish or delete any post or
page, create and rename categories and tags, and upload files. On a site with
"Allow registration" on, anyone could create such an account.

Each endpoint now checks the same capabilities as the admin screens:

| Action | Needs |
|---|---|
| Create a post/page | `edit_posts` / `edit_pages` |
| Edit someone else's | `edit_others_posts` / `edit_others_pages` |
| Publish, or edit a published one | `publish_posts` / `publish_pages` (without it, "published" becomes "pending", as in the admin) |
| Delete own / others' | `delete_posts` / `delete_others_posts` (and `_pages`) |
| Terms: create, edit, delete | `manage_taxonomies` |
| Media: list, upload, edit or delete own | `upload_media` |
| Media: edit or delete others' | `delete_media` |

`DELETE /api/v1/pages/{id}` no longer deletes a *post* with that id (and the
same the other way around).

### The installer could be run again on an installed site

`install.php` checked `INSTALLED === 'true'`, but the `.env` reader turns
`true` into a boolean, so the check never matched. On every installed site
the setup wizard stayed reachable, and anyone could run it again: point the
site at a database of their own, create an admin, and have `.env` rewritten.
The installer now redirects home whenever `.env` marks the site installed.

## High

### API key scopes are enforced on the REST API

Scopes were stored on each key but only the MCP server checked them. On
`/api/v1`, a `posts:read` key could do anything its owner's role allowed,
including changing the owner's email and password. Each request now needs the
matching scope: `posts:*` for posts and pages, `media:*`, `users:*` (also for
changing your own profile through `/me`), `comments:*`, `taxonomies:*`,
`menus:*`, and `settings:*` for settings, apps, cache and the scheduler.
`GET`/`HEAD` need `:read`; everything else needs `:write`.

### Drafts and pending posts were publicly listable

`GET /api/v1/posts?status=draft` (or `pending`) returned unpublished content
to anyone. The public list endpoints now return published content only.

### A partial update wiped the post

`PUT`/`PATCH /api/v1/posts/{id}` filled every field the request left out with
a default, so sending just a title emptied the content, reset the slug and
moved the post to draft. Only the fields you send are changed now. Unknown
`status` values are refused (`draft`, `pending`, `published`).

### Updates and marketplace downloads now verify TLS certificates

Core update, app and theme downloads ran with certificate checks turned off.
Anyone able to intercept a site's traffic could have served it a package of
their own, and the SHA-256 check didn't help, because the hash came over the
same connection. Certificates are now verified. If a host's PHP has no
working CA store, Basehim retries once with the system CA bundles it can
find. If the hub is still unreachable, a site owner can set
`HTTP_TLS_VERIFY=false` in `.env` as a temporary escape hatch.

### Browser sessions on the REST API need a CSRF token

A plain form post carrying the admin's session cookie could create content
through `/api/v1`. Requests authenticated **by cookie** that change
something must now send the session's CSRF token in an `X-CSRF-Token`
header. API keys and bearer tokens are unaffected.

## Medium

### CORS no longer grants cookie access to every website

The API echoed any `Origin` back with `Access-Control-Allow-Credentials:
true`. Credentials are now allowed only for the site's own origin
(`APP_URL` or the request host) and any origins listed in a new optional
`.env` setting:

```
CORS_ALLOWED_ORIGINS=https://app.example.com,https://www.example.org
```

Session cookies are also ignored on `/api/v1` requests from any other
origin. Any origin can still call the API with a key or bearer token, and the
public endpoints answer everyone as before.

### API keys couldn't be created on MySQL/MariaDB in strict mode

The `api_keys.key_prefix` column was `VARCHAR(10)` but holds 16 characters,
so "Create API key" failed with a database error on most modern servers.
Migration `012_api_key_prefix_length` widens it; it runs automatically during
the update.

## Low

- `php -S` dev server: `/.well-known/*` was refused along with dotfiles,
  so OAuth/MCP discovery failed against a local site. It's served now; other
  dotfiles stay blocked.
- The installer's "start clean" step now also drops `migrations`,
  `auth_remember_tokens` and the MCP OAuth tables, so a retried install no
  longer stops on a duplicate migration record.
- The `.env` the installer writes ends with a newline, so a line appended
  later no longer merges into `INSTALLED=true`.
- Quoted `.env` values are kept as text: `DB_PASSWORD="null"` is the
  password `null`, not PHP `null`. Unquoted `true`/`false`/`null` still cast.
- `per_page` / `page` that aren't numbers fall back to the defaults
  (`per_page=abc` used to mean 1 per page).
- `POST /api/v1/posts` with no title or content (for example an unparsed
  body) answers 422 instead of creating an empty "Untitled" draft.
- Search with an array query (`?q[]=x`) no longer logs a PHP warning.

## Behaviour changes for integrations

- **API keys** need the scopes for what they do. A key that wrote posts
  while holding only `posts:read` now gets
  `403 {"title":"Insufficient scope","required_scope":"posts:write"}`.
  Edit the key's scopes under API → Keys.
- **Browser JavaScript using the admin cookie against `/api/v1`** must send
  `X-CSRF-Token` on writes and be served from the site itself or an
  origin in `CORS_ALLOWED_ORIGINS`.
- **Lower roles** get `403 Requires the … capability.` for actions their role
  doesn't allow in the admin either.

## Files

New: `app/Core/OriginPolicy.php`, `app/Core/Tls.php`,
`database/migrations/012_api_key_prefix_length.sql`.

Changed: `app/Core/Env.php`, `app/Http/Middleware/Authenticate.php`,
`app/Http/Middleware/Cors.php`, `app/Http/Controllers/Api/ApiController.php`,
`PostController.php`, `MediaController.php`, `TaxonomyController.php`,
`CommentController.php`, `SearchController.php`, `UserController.php`,
`app/Http/Controllers/Web/SearchController.php`, `app/Services/UpdateService.php`,
`AppService.php`, `ThemeService.php`, `install.php`, `server.php`, `index.php`.
