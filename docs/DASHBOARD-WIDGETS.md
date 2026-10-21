# Dashboard widgets

Since 1.2.68 the admin dashboard is a grid of widgets. Core's cards and every
app's cards are registered the same way, each person arranges their own
dashboard, and the layout is kept in the database so it follows them to every
browser and device.

This page is for app and theme developers. A complete, working example app is
in [`docs/examples/dashboard-widgets`](examples/dashboard-widgets/src/App.php):
copy the folder to `content/apps/dashboard-example` and activate it.

- [The short version](#the-short-version)
- [How it fits together](#how-it-fits-together)
- [Registering a widget](#registering-a-widget)
- [The definition](#the-definition)
- [Native views and their data](#native-views-and-their-data)
- [Settings](#settings)
- [Actions](#actions)
- [Data from your own AJAX action](#data-from-your-own-ajax-action)
- [States: loading, empty, unavailable, error](#states-loading-empty-unavailable-error)
- [Permissions](#permissions)
- [Caching and refresh](#caching-and-refresh)
- [Layouts, defaults and your users' preferences](#layouts-defaults-and-your-users-preferences)
- [Renaming or removing a widget](#renaming-or-removing-a-widget)
- [HTML widgets written before 1.2.68](#html-widgets-written-before-1268)
- [Security rules](#security-rules)
- [Reference: AJAX actions](#reference-ajax-actions)

## The short version

```php
class App extends \App\Core\App
{
    public function boot(): void
    {
        $this->registerWidget('orders', [
            'surfaces'    => ['dashboard'],
            'title'       => 'Orders today',
            'description' => 'New orders since midnight.',
            'icon'        => 'shopping-cart',            // a heroicon name
            'capability'  => 'access_app:' . $this->slug(),
            'dashboard'   => [
                'view' => 'stat',
                'size' => 'third',
                'data' => function (\App\Services\Dashboard\WidgetContext $ctx): array {
                    return ['items' => [
                        ['label' => 'Orders', 'value' => $this->countOrdersToday(), 'href' => $ctx->url('/admin/shop')],
                    ]];
                },
            ],
        ]);
    }
}
```

That's all. The card appears on the dashboard of everyone who holds the
capability, in **Customize → Add widgets** under your app's name, with Refresh,
Collapse and Remove. You never touch a core file or write card markup.

## How it fits together

| Concern | Where | What it does |
|---|---|---|
| Registration | `App\Core\WidgetRegistry` | Validates definitions, owns keys, refuses collisions. |
| Engine | `App\Services\Dashboard\DashboardService` | Who may see what, each user's layout, running providers and actions in isolation. |
| Storage | `App\Services\Dashboard\UserMetaLayoutStore` | `users.meta` → `dashboard` per user; `settings` → `dashboard.default_layout` for the site. |
| Transport | `routes/ajax.php`, `DashboardActions` | Seven `widgets.dashboard_*` actions on the AJAX gateway. |
| Presentation | `admin/assets/js/dashboard.js`; styles in `admin/assets/css/src/tailwind.css` (compiled into `tailwind.min.css`, 1.2.77) | Draws cards and views, customize mode, drag and drop. |

The engine contains no knowledge of posts, comments or any app. Core's own six
cards live in `App\Services\Dashboard\CoreDashboardWidgets` and are registered
through the same public API as yours.

The page loads with the layout already in it and asks for every card's data in
one batched request. A provider that is slow, throws or returns junk affects its
own card only.

## Registering a widget

**From an app**, in `boot()`:

```php
$this->registerWidget('key', [
    'surfaces' => ['dashboard'],   // dashboard only; omit for editor + frontend + dashboard
    // …
]);                                // → '{app-slug}.key'
```

The key is namespaced under your slug unless it already contains a dot, and
the owner is always set to your app — an owner passed in the definition is
ignored.

> There is no separate `registerDashboardWidget()` helper on the `App` base
> class, on purpose. Adding a method to `App` breaks every installed app that
> already declares a method of that name as `private` (PHP refuses to load the
> class, and with it the site). If your app has its own helper with that name,
> it keeps working.

**From a theme**, in the theme's `widgets.php` (keys become
`theme.{slug}.{key}`), with `'surfaces' => ['dashboard']`.

**From core**, register on the registry with `'source' => 'core'` and a key
under `core.dashboard.` — see `CoreDashboardWidgets::register()`.

Registration happens on every request, so a deactivated app's widgets simply
don't exist on the next request and never run.

### Keys and ownership

- Keys are lower-case `a-z 0-9 - _` with up to three dots: `my-app.orders`.
- `core.*` belongs to core. An app registering `core.anything` is refused.
- A key belongs to whoever registered it first. Another owner trying the same
  key is refused; the same owner registering again replaces its definition (a
  notice is logged so an accidental double registration is visible).
- A refused registration returns `false` (`registerWidget()` stays `void` for
  compatibility) and the reason is written to **your app's log** (Admin → Apps
  → Logs) and the core log. Nothing fails silently.

## The definition

```php
[
    'title'       => 'Orders today',            // required
    'description' => 'Shown under the title, and in Add widgets.',  // one short line
    'icon'        => 'shopping-cart',          // heroicon name (app/Core/icons.php)
    'surfaces'    => ['dashboard'],            // or with 'editor', 'frontend'
    'capability'  => 'edit_posts',             // string or list (all required); default access_app:{your slug} (1.2.73)
    'fields'      => [ … ],                    // settings — see Settings
    'render'      => fn(array $settings, string $surface): string => '…',  // HTML widgets only
    'dashboard'   => [
        'view'        => 'stat',               // stat|list|table|chart|feed|status|html
        'data'        => callable,             // fn(WidgetContext $ctx): array
        'endpoint'    => 'my-app.widget-data', // instead of data — see below
        'sizes'       => ['third', 'half'],    // allowed widths; default: all
        'size'        => 'third',              // default width
        'priority'    => 100,                  // default order, lower first
        'default'     => true,                 // placed on new dashboards (false = only offered)
        'multiple'    => false,                // may be added more than once
        'refresh'     => 0,                    // auto-refresh seconds (0 = off, min 60)
        'cache'       => 0,                    // seconds, or ['ttl' => 300, 'scope' => 'user'|'role'|'global']
        'tone'        => 'blue',               // the icon square's colour (1.2.77): blue|green|amber|red|violet|indigo|slate
        'collapsible' => true,                 // ⋮ → Collapse
        'removable'   => true,
        'refreshable' => true,
        'actions'     => [ … ],                // see Actions
        'empty'       => ['title' => '…', 'text' => '…', 'action' => ['label' => '…', 'url' => '/admin/…']],
        'configure'   => callable,             // extra settings validation — see Settings
        'visible'     => callable,             // fn(array $user): bool, a rule on top of capability
        'aliases'     => ['my-app.old-key'],   // see Renaming
    ],
]
```

### Sizes

The dashboard is a 12-column grid on desktops, 6 on tablets, one column on
phones.

| Size | Desktop | Tablet | Phone |
|---|---|---|---|
| `small` | 3 / 12 | half | full |
| `third` | 4 / 12 | half | full |
| `half` | 6 / 12 | full | full |
| `two-thirds` | 8 / 12 | full | full |
| `full` | 12 / 12 | full | full |

List only the sizes your content works at. A user can't pick a size you don't
list, and a stored size you drop later falls back to your default.

## Native views and their data

Return plain data; the dashboard draws it. Strings are always put on the page as
text, never as HTML, so you don't escape anything. Lists are capped (stat 8
items, list/feed/table 50 rows, chart 60 points × 3 series) and long strings
are trimmed.

Links (`href`, `url`) must be a site path (`/admin/…`) or `http(s)://`. Anything
else — `javascript:`, `data:`, `//host` — is dropped. Use `$ctx->url('/admin/x')`
to get the install's base path right.

Every view also accepts `summary` (a strip under the content, with
`summary_icon`, any icon name) and
`footer => ['text' => '…', 'link' => ['label' => '…', 'url' => '…']]`.

**The card (1.2.77).** Its header shows your `icon` in a square of the
widget's `tone`, the `title`, and the `description` as a one-line subtitle;
then Refresh, the inline setting (below) and ⋮ (your actions, Collapse,
Settings, Remove). Icons are any name from core's set (`app/Core/icons.php`):
names your data uses arrive with it, so you don't register them; a name core
doesn't have is dropped.

**stat** — key numbers, as tiles: an icon square, the label, a pill at the
top right, the number (counted up when it loads), the hint, and small bars in
the tile's colour.

```php
['items' => [
    ['label' => 'Orders', 'value' => 128, 'hint' => '12 waiting', 'href' => '/admin/shop',
     'tone' => 'blue',                                  // slate|blue|green|amber|red|violet|indigo
     'icon' => 'shopping-cart',                         // 1.2.77; default chart-bar
     'trend' => ['direction' => 'up', 'text' => '+8%', 'good' => true]],   // the pill
    ['label' => 'Refunds', 'value' => 3, 'tone' => 'amber', 'icon' => 'receipt-refund',
     'badge' => ['text' => '3', 'tone' => 'amber', 'icon' => 'clock']],   // a count that needs attention, instead of a trend
    ['label' => 'Customers', 'value' => 840, 'tone' => 'indigo', 'icon' => 'user-group',
     'wide' => true,                                    // spans the row, with a quiet illustration
     'people' => ['items' => [['name' => 'Ayesha Khan', 'url' => '/uploads/a.jpg'], ['name' => 'Bilal']], 'more' => 838]],
]]
```

Tiles sit two to a row, four on a full-width card; the last one fills a row it
would leave half empty. Keep the trend text short (`+12%`): narrow tiles drop
its arrow and the sign carries the direction.

**list** — rows with a title, meta line and badge.

```php
['items' => [
    ['title' => 'Order #1042', 'href' => '/admin/shop/1042', 'meta' => 'Ayesha Khan',
     'time' => '2026-10-19 08:30:00',                          // UTC; shown as "2 hours ago"
     'badge' => ['text' => 'Paid', 'tone' => 'green']],
]]
```

**table** — columns and rows; the first column links to `_href`.

```php
['columns' => [['key' => 'name', 'label' => 'Product'], ['key' => 'qty', 'label' => 'Sold', 'align' => 'right']],
 'rows'    => [['name' => 'Mug', 'qty' => 31, '_href' => '/admin/shop/products/7']]]
```

**chart** — bar or line, up to three series. Drawn with plain HTML/SVG on an
axis of round numbers with dashed guides; bars grow in one after another and
lines draw themselves when data arrives (not for people who ask for reduced
motion). The values are also read out to screen readers.

```php
['type' => 'bar', 'labels' => ['Mon', 'Tue', 'Wed'], 'unit' => 'orders',
 'series' => [['name' => 'Orders', 'values' => [4, 9, 6]]]]
```

**feed** — an activity timeline.

```php
['items' => [['actor' => 'Ayesha', 'text' => 'placed an order', 'quote' => '2 × Mug',
              'time' => '2026-10-19 08:30:00', 'tone' => 'green', 'href' => '/admin/shop/1042']]]
```

**status** — checks with ok / warn / error / unknown.

```php
['summary' => 'All good.',
 'items' => [['label' => 'Payment gateway', 'state' => 'ok', 'detail' => 'Connected', 'href' => '/admin/shop/settings']]]
```

**html** — your own markup, for what the native views can't show.

```php
['html' => '<p>Anything <strong>simple</strong></p>']
```

The HTML from a `data` provider is passed through core's `HtmlSanitizer` (the
same filter as an author's post): no scripts, no event handlers, no
`javascript:` links. If you need script, ship it with `addAdminScript()` and
draw into your card from there, or use a native view.

If you don't set `dashboard.view`, return it as `'view' => 'list'` in the data.

### The provider

```php
'data' => function (WidgetContext $ctx): array {
    $ctx->user();            // the signed-in user (no password hash, no security meta)
    $ctx->userId();
    $ctx->can('edit_others_posts');
    $ctx->settings();        // this card's settings, validated, defaults filled in
    $ctx->setting('count', 5);
    $ctx->forced();          // true when the user pressed Refresh (cache skipped)
    $ctx->instanceId();      // this card on this user's dashboard
    $ctx->url('/admin/x');   // with the install base
    $ctx->make(Service::class);
    return [...];
}
```

It runs on the server, only after the engine has checked the widget's
capability and visibility rule for this user. **Which records this user may see
is still your job** — the engine knows nothing about your data. Core's Recent
posts card, for example, shows only the user's own posts unless they hold
`edit_others_posts`.

## Settings

Declare fields; the dashboard draws the form (⋮ → Settings), validates what's
sent against them, stores the result per card and hands it to your provider.
A widget with no fields has no Settings item.

```php
'fields' => [
    ['key' => 'count',  'label' => 'Rows',   'type' => 'number', 'min' => 3, 'max' => 20, 'default' => 5],
    ['key' => 'status', 'label' => 'Show',   'type' => 'select', 'default' => 'open',
     'options' => ['open' => 'Open', 'all' => 'Everything']],
    ['key' => 'mine',   'label' => 'Only mine', 'type' => 'checkbox'],
    ['key' => 'link',   'label' => 'Link',   'type' => 'url', 'help' => 'Where the title goes.'],
],
```

A `select` field marked `'inline' => true` is also shown in the card's header
(1.2.77), like Publishing activity's period: changing it there saves it and
reloads the card. Use it for the one choice people change often. It is hidden
on narrow cards, where Settings still has it.

Types: `text` (200 chars), `textarea` (2000), `number` (`min`, `max`, `step`),
`select` (`options` as `value => label`, a list, or a callable returning
either; numeric values such as `'6' => 'Last 6 months'` work since 1.2.77), `checkbox`, `url` (http(s) or a site path), `color` (`#rgb`/`#rrggbb`).
Unknown keys are dropped. A bad value from the form is an error shown under its
field; a stored value that no longer fits (you changed the schema) quietly
falls back to the default. Settings are capped at 4 KB per card.

For rules the schema can't express:

```php
'configure' => function (array $settings, WidgetContext $ctx): array {
    if ($settings['status'] === 'all' && !$ctx->can('manage_shop')) {
        throw new \InvalidArgumentException('Only shop managers can show every order.');
    }
    return $settings;   // may adjust; the result is validated again
},
```

The same `fields` are shared with the editor and widget areas when a widget is on
those surfaces too.

## Actions

Actions appear in the card's ⋮ menu.

```php
'actions' => [
    ['id' => 'all', 'label' => 'Open orders', 'url' => '/admin/shop', 'icon' => 'list-bullet'],
    ['id' => 'sync', 'label' => 'Sync now', 'icon' => 'arrow-path', 'capability' => 'manage_shop',
     'confirm' => 'Fetch orders from the payment provider now?',
     'handler' => function (WidgetContext $ctx): array {
         $n = $this->sync();
         return ['message' => "Synced {$n} orders.", 'refresh' => true];
     }],
],
```

A `url` action is a link. A `handler` action runs on the server through
`widgets.dashboard_action`: the card must be on the user's dashboard and
visible to them, and the action's own `capability` is checked there (the menu
also hides actions the user can't run, but that's convenience, not security).
Throw `\InvalidArgumentException('message')` or `WidgetUnavailable` to show the
user a message; anything else is logged and they see a generic failure. The
card's cache is cleared after the action and, unless you return
`'refresh' => false`, the card reloads.

## Data from your own AJAX action

If the data already has an AJAX action, point the widget at it instead of
writing a provider:

```php
$this->ajax('widget-data', [
    'handler'    => [$this, 'widgetData'],
    'methods'    => ['GET'],
    'capability' => 'access_app:' . $this->slug(),
    'input'      => ['instance' => 'string|max:41', 'settings' => 'string|max:4000'],
]);
$this->registerWidget('live', [
    'surfaces'    => ['dashboard'],
    'title'     => 'Live',
    'dashboard' => ['endpoint' => $this->slug() . '.widget-data', 'refresh' => 60],
]);

public function widgetData(AjaxContext $ctx): array
{
    return ['view' => 'stat', 'items' => [...]];   // the same payloads as above
}
```

The browser calls it through the gateway with the card's `instance` id and its
`settings` as JSON, so your action's capability, CSRF check and rate limit apply.
The endpoint must be one of your own actions. Two differences from a provider:
your action validates its own input (treat `settings` as untrusted), and the
`html` view is refused for endpoints — their output hasn't been through the
server-side sanitizer. Throw `new AjaxException('UNAVAILABLE', '…', 503)` for
the unavailable state.

## States: loading, empty, unavailable, error

You don't build these; the card does.

| State | When | What shows |
|---|---|---|
| Loading | Before data arrives | A skeleton. On Refresh the old content stays until the new arrives. |
| Empty | The view has nothing (no items/rows, a chart of zeros) | `dashboard.empty` title, text and optional button. |
| Unavailable | Your provider threw `App\Services\Dashboard\WidgetUnavailable('…')` | Your message, neutral styling, not logged. For "not connected yet", "service offline". |
| Error | Any other exception, a non-array return, an unknown view | "This widget couldn't load its data." with **Try again**. The real error goes to your app's log, never to the browser. |
| Gone | The card's widget isn't registered or permitted any more | A short note; the rest of the dashboard is unaffected. |

## Permissions

Customising a dashboard and seeing data are separate. Every signed-in user may
arrange their own dashboard, but only with widgets they may see:

| Check | Where it's enforced |
|---|---|
| Discover a widget (Add widgets) | `capability` + `visible` rule, server-side |
| Have it on a dashboard | the same — a saved card a user no longer qualifies for is never rendered (it's kept, dormant, in case they regain it) |
| Fetch its data | the same, per request, against the user's stored layout |
| Open settings / save them | the same, plus your `configure` rule |
| Run an action | the same, plus the action's `capability` |
| Make a layout the site default | `manage_settings` |
| Preview it in the editor (`widgets.render`) | the widget's `capability` (since 1.2.68) |

`capability` can be any core capability, a list of them (all required), or your
app's `access_app:{slug}` — held by administrators by default and grantable per
user under Users → Access. **Without a `capability`, an app's widget is for
the people who can use the app** (`access_app:{slug}`) since 1.2.73; before,
the default was `read`, every signed-in user, subscribers included. To show a
widget to everyone signed in, say so: `'capability' => 'read'`.

Hiding a card in the browser is never the protection: the browser only ever
names a card id, and the server works out from the user's own stored layout
which widget and settings that is.

## Caching and refresh

- `cache` keeps a provider's result for that many seconds (max a day). Scope
  `user` (default) caches per person; `role` per set of capabilities; `global`
  once for everyone — only for data that doesn't depend on who's asking.
  Refresh and actions bypass and clear it.
- `refresh` reloads the card on its own every N seconds (minimum 60), only while
  the tab is visible and nobody is customizing. Cards due at the same moment go
  in one request. Leave it at 0 unless the data really changes that often.
- Manual Refresh is limited to once every few seconds per card, and the
  gateway's rate limits apply on top.

## Layouts, defaults and your users' preferences

What's stored, per user, in `users.meta` under `dashboard`:

```json
{ "v": 1,
  "items": [ { "id": "w-3f9c1a2b7d", "widget": "my-app.orders", "size": "third",
               "visible": true, "collapsed": false, "settings": { "count": 5 } } ],
  "seen": [ "core.dashboard.at-a-glance", "my-app.orders" ],
  "saved_at": "2026-10-19 21:30:00" }
```

Position is the order in `items`. Because it's in the users table it is scoped
to this installation (its `DB_PREFIX`) and to the user's id, and it is the same
on every device. Nothing about the layout is kept in the browser.

How a dashboard is decided:

1. **The user saved one** → theirs, reconciled with what exists now.
2. **Otherwise, a site default** (an administrator chose Customize → Use as site
   default) → that, filtered to what this user may see, plus any widget
   registered since that's on by default.
3. **Otherwise** → every widget this user may see that has `default => true`, in
   `priority` order, at its default size.

What happens to a saved layout when things change:

| Change | Result |
|---|---|
| You register a new widget | Their layout is untouched. The widget is offered in Add widgets, marked **New**. People who never customised get it automatically if it's on by default. |
| Your app is switched off or removed | Its cards don't render and don't run. They stay in the stored layout (with their settings) and come back where they were when the app returns. |
| The user loses the capability | Same as above. |
| A user removes a card | It's hidden, not deleted: its settings are kept and Add widgets offers **Restore**. |
| You change `sizes` / `fields` | Stored values that no longer fit fall back to your defaults. |
| You rename the key | Use `aliases` — see below. |

Collapse/expand, remove and settings save immediately. Order, size, adding and
restoring happen in Customize mode, which works on a copy until **Save
changes** (Cancel discards it). **Reset to default** forgets the user's layout.

## Renaming or removing a widget

Renaming a key would otherwise orphan everyone's card. Keep the old key as an
alias:

```php
$this->registerWidget('order-stats', [
    'surfaces'    => ['dashboard'],
    // …
    'dashboard' => ['aliases' => ['my-app.orders'], /* … */],
]);
```

Saved cards for `my-app.orders` become `my-app.order-stats`, settings included.
An alias can't shadow a live key or one under `core.`.

To retire a widget, stop registering it. Users' cards for it go dormant; they
cost nothing and disappear from view.

## HTML widgets written before 1.2.68

Nothing to change. A widget with a `render` callback and no `dashboard.data`
still works: its HTML is rendered into the page once on load (so any inline
`<script>` runs as before) and inside its own card. It gains the card toolbar,
sizes, layout persistence and isolation for free. Its old `dashboard.width` is
read as `size`, and `half` now really is half the width (it was two thirds).

Two things are new for it: give it a `capability` if it shows anything not
everyone should see (it defaults to `read`, as before), and consider moving to
a `data` provider so it gets loading/empty/error states and caching.

Its HTML is trusted, as it always was: it comes from your PHP. Escape what you
output with `htmlspecialchars()`.

## Security rules

- The browser names card ids only. Widget keys and settings sent by the client
  are checked against the registry and the widget's schema; settings are never
  taken from a layout save.
- Every data, settings and action request re-checks capability and visibility
  on the server.
- A provider's exception message is logged, never sent. Write `WidgetUnavailable`
  messages for the user.
- Strings from providers are drawn as text; links are restricted to site paths
  and http(s); `html` from providers is sanitized. App code still runs in
  Basehim's process — this protects the page from bad *data*, not from a
  malicious app (see APP-PERMISSIONS.md).
- Layout writes are CSRF-checked and rate-limited, capped at 40 cards and
  4 KB of settings per card.

## Reference: AJAX actions

All require a signed-in user. Writes are POST with CSRF.

| Action | Method | Input | Returns |
|---|---|---|---|
| `widgets.dashboard_state` | GET | — | `layout`, `catalog`, `has_site_default` |
| `widgets.dashboard_data` | GET | `ids` (comma list, ≤ 30), `force` | `widgets: {id: result}` |
| `widgets.dashboard_save` | POST | `layout` (JSON list of `{id, widget, size, visible, collapsed}`; `id: "new"` adds) | state |
| `widgets.dashboard_update` | POST | `id` + any of `collapsed`, `visible`, `settings` (JSON) | `item` |
| `widgets.dashboard_reset` | POST | — | state |
| `widgets.dashboard_action` | POST | `id`, `action` | `message`, `refresh` |
| `widgets.dashboard_default` | POST | `clear` | `has_site_default` (needs `manage_settings`) |

A data result is `{ok: true, view, data, empty, fetched_at, cached?}` or
`{ok: false, state: 'error'|'unavailable'|'gone', message}`.

From your own admin scripts, `Basehim.dashboard.refresh(id)` reloads one card
and `Basehim.dashboard.items()` returns the current layout.
