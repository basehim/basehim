# Building a Basehim App

Companion docs: **APP-API.md** (the `$this->api()` surface),
**APP-PERMISSIONS.md** (declaring and enforcing permissions) and
**APP-AJAX.md** (AJAX actions called from the browser).

## Layout

```
content/apps/my-app/
  app.json              manifest (app.json still accepted)
  src/App.php           entry class
  views/                your templates
  assets/               css, js, images — served at /content/apps/my-app/assets/…
```

## Manifest

```json
{
  "name": "Event Manager",
  "slug": "event-manager",
  "version": "1.0.0",
  "description": "Adds an Events content type with ticketing.",
  "developer": { "name": "Your Name", "url": "https://you.example.com" },
  "company": { "name": "Your Company", "url": "https://yourcompany.example.com" },
  "vendor": "yourcompany",
  "namespace": "YourCompany\\EventManager",
  "src": "src",
  "entry": "App",
  "icon": "assets/icon.png",
  "permissions": ["posts.write", "terms.write", "mail.send"],
  "requires": {
    "php": ">=8.1",
    "basehim": ">=1.37.0",
    "apps": { "some-other-app": ">=2.0" }
  }
}
```

| Field | Required | Notes |
|---|---|---|
| `name`, `slug`, `version` | yes | slug: letters, digits, dash, underscore, 2–80 chars |
| `namespace` | recommended | Without it, autoloading won't find your class |
| `entry` | no | Defaults to `App`, falling back to `App` |
| `src` | no | Defaults to `src` |
| `icon` | no | An image: `assets/icon.png` — see **App listing details** below. `heroicon:name` and `fa-name` still work |
| `description` | no | One or two sentences, shown on the Apps, Updates and marketplace screens. Up to 600 characters |
| `developer` | no | `{ "name": "…", "url": "https://…" }` — the person who wrote the app. Replaces `author`, which is still read |
| `company` | no | `{ "name": "…", "url": "https://…" }` — the business behind it, when there is one |
| `permissions` | no | Declaring **any** opts you into enforcement — see APP-PERMISSIONS.md |
| `requires` | no | Checked at install **and** activate |

### App listing details (1.2.36)

These fields are how an app introduces itself on the Apps screen, on the
Updates page when a new version is waiting, and in the marketplace. All are
optional; an app without them still works and shows a plain card with its
initial.

**Icon.** A PNG or GIF, square, **256×256 or 512×512**, at most **1 MB**,
inside the app's `assets/` folder — the only folder served to the browser:

```json
"icon": "assets/icon.png"
```

An icon that breaks a rule is still shown where possible, and the Apps screen
lists what to fix under "App details need attention". A missing file, or one
over 1 MB, falls back to the app's initial. SVG, JPEG and WebP icons from older
apps keep showing, with a note recommending PNG or GIF.

**Developer and company.** Each is an object with a `name` and an optional
`url`. Names link to their URL on every screen; a URL must be a full `http://`
or `https://` address or it is ignored. Flat spellings are read too:

```json
"developer": "Your Name",
"developer_url": "https://you.example.com",
"company": "Your Company",
"company_url": "https://yourcompany.example.com"
```

An app that only has `"author": "Your Name"` shows that name as its developer,
exactly as before.

**Description.** Plain text; markup is shown as text.

**Version constraints** understood in `requires`: `>=1.2`, `<=`, `>`, `<`, `!=`,
`^1.2` (>=1.2, <2.0), `1.4.*`, comma-separated (`">=1.0, <2.0"`), and a bare
`"8.1"` meaning a minimum. Anything unparseable is treated as satisfied rather
than blocking an install on syntax Basehim doesn't know.

Dependencies must be **installed and active**, not merely present.

## Entry class

```php
<?php
namespace YourCompany\EventManager;

use App\Core\App;

class App extends \App\Core\App
{
    public function boot(): void
    {
        // Runs on every request while active. Register everything here.
        $this->addAction('post.created', [$this, 'onPostCreated']);
        $this->addAdminMenu(['url' => '/admin/events', 'label' => 'Events']);
        $this->adminGet('/events', [$this, 'screen']);
    }

    public function onInstall(): void {}    // first time detected
    public function onActivate(): void {}   // operator switched it on
    public function onDeactivate(): void {} // switched off
    public function onUpgrade(string $from, string $to): void {}
    public function onUninstall(): void {}  // before removal
}
```

### `onUpgrade` — migrating your own data

Fires when your version changes on disk, **however** the files arrived: the
marketplace upgrade flow, a ZIP upload, an FTP drop, or a core patch that
bundled them. Fired at most once per version change per request.

It also fires on **downgrades** — only you know whether unwinding your schema is
safe — so compare rather than assume:

```php
public function onUpgrade(string $from, string $to): void
{
    if (version_compare($from, '2.0.0', '<')) {
        $this->schema("ALTER TABLE {$this->table('events')} ADD COLUMN venue VARCHAR(200)");
    }
}
```

A throw is logged and swallowed, so a bad handler can't lock you out of the Apps
screen.

## Custom content types

```php
$this->registerPostType('event', [
    'label'      => 'Events',
    'singular'   => 'Event',
    'icon'       => 'calendar',
    'supports'   => ['title', 'content', 'thumbnail'],
    'taxonomies' => ['category'],
]);
```

Gives you a sidebar entry and the full list / create / edit / trash screens at
`/admin/content/event`, plus `$this->api()->content('event')` for CRUD.

Registration is per-request and nothing is persisted — deactivating your app
removes the screens, the content stays in the database, and reactivating brings
them back.

Custom types use the **`post` capability family** by default (`edit_posts`,
`publish_posts`…). No role in an existing install holds `edit_events`, so a type
demanding its own capabilities would be invisible to every user on the site.
Override with `capability_type` only if you've created matching capabilities.

`post` and `page` are owned by core and cannot be re-registered.

## Content formats

A post's `content_format` says how its `content` is stored:

| Format | Stored as | On the site |
|---|---|---|
| `blocks` | the block editor's JSON | rendered block by block |
| `html` (default) | HTML | printed as it is |
| `markdown` | Markdown | converted to HTML |

Write whichever suits the source: `$this->api()->posts()->create([... 'content_format' => 'markdown'])`,
or the same field through REST and MCP.

**Core trusts the content over the label.** Block JSON saved as `html` is
still rendered as blocks, and since 1.2.48 Markdown saved as `html` (the
default, so a common slip for AI tools and scripts) is rendered as Markdown
(`Markdown::looksLikeMarkdown()`). The check is strict: any block-level HTML
tag or WordPress block comment means HTML, and plain prose with a stray
asterisk is never taken for Markdown.

**Markdown opens in the visual editor.** When someone edits a Markdown post,
it is converted to blocks on the spot (headings, lists, code, tables,
images, quotes; a nested list stays one Custom HTML block). Nothing changes in
the database until they save; **Keep Markdown** puts the original back
untouched. So it is safe to keep generating drafts in Markdown: authors get
normal blocks to edit.

## Hooks

`$this->addAction($name, $callback, $priority = 10)` for side effects,
`$this->addFilter($name, $callback, $priority = 10)` to transform a value.

**Content:** `post.before_create` `post.created` `post.before_update`
`post.updated` `post.before_delete` `post.deleted` `post.before_force_delete`
`post.restored` `post.content` (filter — the rendered body)
`post.unfiltered_html` (filter — may this writer publish HTML as written)
`html.iframe_hosts` (filter — where a filtered post's iframes may point)

Post HTML from anyone without the `unfiltered_html` capability (editors and
administrators have it) is filtered when it is saved: scripts, event handlers,
`javascript:` links, forms and iframes from unknown sites are removed (since
1.2.52). That includes posts your app writes with `$this->api()->posts()` or
`PostService` while no one is signed in, such as from a scheduled task. If your
app writes HTML it trusts, return `true` from `post.unfiltered_html`; to allow
iframes from another provider, add its `host/path` to `html.iframe_hosts`:

```php
$this->addFilter('html.iframe_hosts', fn(array $h) => [...$h, 'player.example.org/embed/']);
```

**Users:** `user.before_create` `user.created` `user.updated` `user.deleted`

**Comments:** `comment.before_create` `comment.created` `comment.status_changed`
`comment.deleted`

**Taxonomy:** `term.created` `term.updated` `term.deleted`

**Media:** `media.uploaded` `media.deleted`

**Mail:** `mail.before_send` `mail.sent` `mail.failed`

**Admin:** `admin.menu` (filter) `admin.styles` `admin.scripts`
`admin.area_policy` (filter)

**Editor & blocks:** `editor.config` `editor.enqueue` `editor.scripts`
`editor.styles` `blocks.pre_render` `blocks.render.{name}` `blocks.rendered`

**Other:** `activity.logged`

## What the base class gives you

**Hooks** `addAction` `addFilter` `doAction` `applyFilters`
**Routes** `get` `post` `route` `routeGroup` `adminGet` `adminPost`
**AJAX** `ajax` `useAjaxClient` — actions browsers call through `POST /ajax` (APP-AJAX.md)
**Admin** `addAdminMenu` `addAdminStyle` `addAdminScript` `adminView`
**Editor** `addEditorScript` `addEditorStyle` `addEditorConfig` `registerBlockRenderer`
**Widgets** `registerWidget`
**Views/assets** `view` `asset`
**Settings** `getSetting` `setSetting` `deleteSetting` `allSettings`
**Database** `table` `legacyTable` `renameLegacyTable` `schema` `db`
**Core API** `api` `schedule`
**Utility** `cache` `config` `request` `session` `log` `allowed` `make`

### Admin routes and access

`adminGet()` / `adminPost()` register under `/admin` behind the same checks as
core's admin screens: the user must be signed in **and** hold your app's access
capability, `access_app:{slug}` — roles with `manage_apps` or `manage_options`
have it; anyone else needs it granted under Users → Access (since 1.2.51; before
that any signed-in account could open these routes). To open a route to more
roles, map its path with the `admin.area_policy` filter, e.g.
`['/admin/events/my-tickets' => 'read']`. Check CSRF in every POST handler
(`$this->session()->verifyCsrf((string) $request->input('_csrf'))`), or use
`$this->ajax()` actions, which check it for you.
Routes you register with plain `get()`/`post()`/`route()` get no checks at all.

Your own tables should use `$this->table('things')` → `app_myapp_things`.

## Packaging

ZIP the app folder with `app.json` at the root (or one folder deep). Install via
Admin → Apps → Upload, or publish through CloudHim.

Once published through CloudHim, every site with the app installed sees a new
version on **Admin → Updates** (and in the sidebar's update count) at its next
check, with that version's notes, and can install it on its own or with
**Update all**. An update replaces the app's files as a whole and keeps its
settings, tables and active state; `onUpgrade()` runs as usual. A version that
declares new permissions keeps running and is flagged for the site owner to
review.

## The post editor's Save draft (1.2.47)

**Save draft** (and Ctrl+S on a draft) saves over AJAX through the
`posts.save_draft` action and keeps the page open. It sends the editor's own
fields only. If your sidebar panel puts a **named** field inside the post
form, the editor sees a field it does not know and posts the whole form
instead, as before, so your field is never lost. You can let the AJAX path
run by saving your panel's data yourself:

```js
BasehimEditor.on('saved', function (post) {        // after every AJAX save
    Basehim.ajax.request('my-app.save-panel', { post_id: post.id, value: myField.value });
});
document.addEventListener('bh:post-saved', function (e) { /* e.detail is the same object */ });
```

`post` holds `id`, `created` (true on the first save of a new post),
`status`, `slug`, `edit_url`, `view_url`, `tags` and `pending_tags`. Fields
without a `name` attribute are never sent anywhere, which is usually what a
panel wants.

Tags typed in the editor that do not exist yet are kept with the post and
created when it is published (`PostEditorService::settleNewTags()`), by
whoever publishes it.

## Gotchas

- **`boot()` runs on every request.** Register cheaply; do real work in a route,
  a hook, or a scheduled task.
- **Declaring one permission opts you into enforcement of all of them.** Declare
  everything you need or nothing at all.
- **`db()` needs the `db.raw` permission** once you declare any permissions. Your
  own settings helpers don't.
- **Scheduled tasks need traffic** unless a real crontab is configured — see
  APP-API.md.
- **Assets are served from a route,** not directly by Apache, so they respect
  the install's base path. Always build URLs with `$this->asset()`.
- **For browser calls that return JSON, register an AJAX action** with
  `$this->ajax()` rather than an `adminPost()` route. The action gets the
  session, CSRF, capability, rate-limit and input checks without you writing
  them; a hand-written route has to remember every one (APP-AJAX.md).
