# Building a Basehim App

Companion docs: **APP-API.md** (the `$this->api()` surface) and
**APP-PERMISSIONS.md** (declaring and enforcing permissions).

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
  "author": "Your Name",
  "vendor": "yourcompany",
  "namespace": "YourCompany\\EventManager",
  "src": "src",
  "entry": "App",
  "icon": "heroicon:calendar",
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
| `icon` | no | `heroicon:name`, `fa-name`, or `assets/icon.svg` |
| `permissions` | no | Declaring **any** opts you into enforcement — see APP-PERMISSIONS.md |
| `requires` | no | Checked at install **and** activate |

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

## Hooks

`$this->addAction($name, $callback, $priority = 10)` for side effects,
`$this->addFilter($name, $callback, $priority = 10)` to transform a value.

**Content:** `post.before_create` `post.created` `post.before_update`
`post.updated` `post.before_delete` `post.deleted` `post.before_force_delete`
`post.restored` `post.content` (filter — the rendered body)

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
**Admin** `addAdminMenu` `addAdminStyle` `addAdminScript` `adminView`
**Editor** `addEditorScript` `addEditorStyle` `addEditorConfig` `registerBlockRenderer`
**Widgets** `registerWidget`
**Views/assets** `view` `asset`
**Settings** `getSetting` `setSetting` `deleteSetting` `allSettings`
**Database** `table` `legacyTable` `renameLegacyTable` `schema` `db`
**Core API** `api` `schedule`
**Utility** `cache` `config` `request` `session` `log` `allowed` `make`

Your own tables should use `$this->table('things')` → `app_myapp_things`.

## Packaging

ZIP the app folder with `app.json` at the root (or one folder deep). Install via
Admin → Apps → Upload, or publish through CloudHim.

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
