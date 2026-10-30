# Frontend widgets

Since 1.2.93. The blocks a site owner places in a theme's sidebars, footers
and other **widget areas**: a search box, recent posts, categories, an app's
"latest products". Core, apps and themes all write widgets the same way, and
any theme can show any of them.

The admin dashboard's cards are a separate framework with its own rules
([DASHBOARD-WIDGETS.md](DASHBOARD-WIDGETS.md)). Nothing on this page changes
how they work.

## Three separate things

| | What it is | Who provides it | Where it lives |
|---|---|---|---|
| **Definition** | What a widget is: its settings, data and template | core, an app, a theme | `WidgetRegistry`, per request |
| **Instance** | One configured copy of a widget, in one area | the site owner, in Appearance → Widgets | the `widgets` / `areas` setting |
| **Area** | A place in the theme where instances go | the theme (or an app) | `WidgetAreaRegistry`, per request |

The same widget can be placed several times with different settings. Switching
themes never deletes instances: the old theme's areas are kept, listed in the
admin as *not in the active theme*, and come back if a theme with the same area
keys is activated. Switching an app off never deletes its widgets' instances
either; they are shown as *unavailable* and skipped on the site.

## Writing a widget

An app, in `boot()`:

```php
$this->registerWidget('latest-products', [
    'title'       => 'Latest products',
    'description' => 'Your newest products, with prices.',
    'icon'        => 'shopping-bag',
    'surfaces'    => ['frontend'],
    'fields'      => [
        ['key' => 'title', 'label' => 'Title', 'type' => 'text', 'default' => 'New in'],
        ['key' => 'count', 'label' => 'How many', 'type' => 'number', 'default' => 4, 'min' => 1, 'max' => 12],
        ['key' => 'show_price', 'label' => 'Show prices', 'type' => 'checkbox', 'default' => '1'],
    ],
    'frontend'    => [
        'category' => 'content',
        'version'  => '1.0.0',
        'data'     => fn(array $s, array $ctx) => ['products' => $this->latest($s['count'])],
        'template' => $this->path() . '/views/widgets/latest-products.php',
        'cache'    => 300,
        'assets'   => ['css' => [$this->asset('css/widgets.css')]],
    ],
]);
```

The key becomes `my-app.latest-products`. A theme does the same from its
`widgets.php` (keys become `theme.{slug}.{key}`), and its template paths must
be inside the theme's folder; an app's inside the app's.

The template gets `$settings`, `$data`, `$widget`, `$instance` (`id`,
`class`), `$area` (`key`, `name`, title markup), `$context` and `$e()` for
escaping:

```php
<ul class="products">
<?php foreach ($data['products'] as $p): ?>
    <li><a href="<?= $e($p['url']) ?>"><?= $e($p['name']) ?></a>
        <?php if ($settings['show_price'] === '1'): ?><span><?= $e($p['price']) ?></span><?php endif; ?></li>
<?php endforeach; ?>
</ul>
```

The framework prints the title (with the area's `before_title` /
`after_title`) and the area's wrapper; the template prints only the widget's
own markup. A template that prints nothing hides the widget, wrapper and all.

### The `frontend` options

Every key is optional.

| Key | Meaning |
|---|---|
| `category` | `content`, `navigation`, `search`, `media`, `social`, `ads`, `utility` or `other` (default). Groups the admin's picker; areas can accept whole categories. |
| `version` | The definition's version. Instances remember the version they were saved with. |
| `migrate` | `fn(array $settings, string $from): array`, run when an instance was saved under another version (`'0'` for instances saved before it had one). Its result is used for rendering, and stored on the next save. |
| `data` | `fn(array $settings, array $context): array`, the data provider. Its result is `$data` in the template. |
| `template` | The default template: an absolute `.php` path inside the owner's folder, or `fn(array $vars): string`. |
| `title` | `true` (default for template widgets): the framework prints the title. |
| `cache` | Seconds, or `['ttl' => s, 'vary' => ['path' or 'url'] or fn($settings, $context): ?string]`. At most a day. See *Caching*. |
| `areas` | Area keys this widget may be placed in (default: any). |
| `assets` | `['css' => [urls], 'js' => [urls], 'inline_css' => '…', 'inline_js' => '…']`, printed once per page before the widget's first instance, and only on pages that show it. |
| `requires` | App slugs that must be active. Otherwise the widget is unavailable. |
| `aliases` | Old keys of this widget, so instances saved under them keep working after a rename. |
| `preview` | `false`: the admin shows no live preview (for widgets that need the page around them). |

A widget with a `render` callable and no template works as it did before
1.2.93: it gets its settings plus `__before_title` / `__after_title` and prints
its own title. A theme can still override it with a template.

### Settings schema

`fields` draws the admin form, validates what is saved and cleans what is
rendered. No widget writes its own form or checks.

| Field key | Meaning |
|---|---|
| `key`, `label`, `type` | `key` is letters, digits, `-`, `_`. |
| `default` | The value for new instances, and for any stored value that is no longer valid. |
| `required` | Saving fails without a value (only while the field is shown). |
| `help` | A line under the field. |
| `options` | For `select`, `multiselect`, `radio`: an array, or a callable (called only when the form is shown or a value is checked). |
| `min`, `max`, `step`, `maxlength` | Limits. Saving outside them fails; rendering clamps. |
| `taxonomy`, `multiple` | For `terms` (and `multiple` for `posts`). |
| `show_if` | `['other_field' => value or [values]]`: shown, and required, only then. |
| `capability` | Who may change it. Others keep the saved value (core's HTML widget uses `unfiltered_html`). |
| `private` | Never returned by the public API. |

Types and the values a template receives:

| Type | Value |
|---|---|
| `text`, `textarea`, `html`, `url`, `color`, `date` | string (`url`: http(s) or site-relative only; `color`: `#rgb`/`#rrggbb`; `date`: `YYYY-MM-DD`) |
| `number` | int, or float when `step` is fractional |
| `checkbox` (alias `toggle`) | `'1'` or `'0'`, as widgets have always read them |
| `select`, `radio` | string, one of the options |
| `multiselect` | list of strings |
| `terms`, `posts` | int, or list of ints with `multiple` |
| `media` | int (0 = none) |

On save, an invalid value is refused with a message per field (HTTP 422 from
the admin). When rendering, nothing fails: an invalid stored value falls back
to the field's default, and unknown keys are dropped, so a template never sees
a value its schema does not allow.

## Areas

A theme declares areas in `theme.json`:

```json
"widget_areas": {
  "sidebar": {
    "name": "Sidebar",
    "description": "Beside the content.",
    "before_widget": "<section id=\"%1$s\" class=\"widget %2$s\">",
    "after_widget": "</section>",
    "before_title": "<h2 class=\"widget__title\">",
    "after_title": "</h2>"
  },
  "header-ad": {
    "name": "Header ad",
    "supports": ["ads"],
    "widgets": ["core.html"],
    "max_widgets": 1
  }
}
```

In `before_widget`, `%1$s` is the instance id, `%2$s` the classes
(`widget-{key}` plus the instance's CSS class), `%3$s` the widget key.
`supports` (categories) and `widgets` (keys) limit what the area accepts;
without either it takes anything. `max_widgets` caps it (at most 50).

In templates:

```php
<?php if (has_widget_area('sidebar')): ?>
    <aside class="sidebar"><?= widget_area('sidebar') ?></aside>
<?php endif; ?>
<?= widget_area('footer', ['class' => 'footer-grid']) ?>   // extra class on the wrapper
<?= widget_area('footer', ['wrapper' => false]) ?>         // instances only
```

`has_widget_area()` is true only when something will show: an enabled
instance of an available widget.

## Overriding templates in a theme

The theme's file wins over the widget's own template, in this order:

1. `templates/widgets/{area}/{key}.php`, for one area
2. `templates/widgets/{key}.php`, everywhere

Core widgets also answer to their short name: `templates/widgets/recent-posts.php`,
`search.php`, `html.php`, `categories.php`, `tags.php`. Copy the default from
`app/Core/Widgets/templates/` and edit the copy; the variables are listed at
the top of each file. An app's widget: `templates/widgets/my-app.latest-products.php`.

## Core widgets

| Key | Settings (new in 1.2.93 in bold) |
|---|---|
| `core.html` | title, html (needs `unfiltered_html` to change) |
| `core.search` | title, placeholder, **button text** |
| `core.recent-posts` | title, count (1–20), order (latest, most viewed, most commented), category, **tag**, **author**, thumbnails (size, shape), date, excerpt (**length**), leave out the post being read |
| `core.categories` | title, display (list, dropdown, **cloud**), count, order, **direction**, counts, hide empty, **leave out**, **sub-categories under their parent**, **levels** |
| `core.tags` | the same, without the hierarchy; cloud **text size range** |

Keys, setting names and stored values are unchanged, and the markup is the
same as before, so placed widgets and theme CSS keep working. Dates now use the
site's timezone (Settings → General); before, they were shown in UTC.

## Caching

A widget with `cache` has its output stored for visitors who are **not signed
in**, on GET requests outside the Customizer preview. Signed-in users are never
served from it and never fill it, so nothing personal can leak between
visitors. The key covers the widget and its version, the instance's settings
and options, the theme, the area and its title markup, the template file and
its modification time, and the `vary` part (the page's path or URL, or what
the `vary` callable returns; `null` there means "don't cache this time").

Everything cached is cleared when a post, term, comment, media item or user
changes, and when any widget is saved, added, moved or removed. A widget
whose output depends on something else should keep its TTL short.

Assets are never cached with the output, so a cached widget still gets its
CSS and JS on every page.

## Failures

One widget failing costs that widget. A throwing data provider or template is
caught, its output discarded (output buffers are restored), the error logged
as `[widgets] Widget "key" (instance …) failed in area "…"`, and the rest of
the area renders. Visitors see an HTML comment, never the error.

## Admin

Appearance → Widgets → Widget areas (`/admin/widgets/areas`, `manage_apps`).

- Add a widget from a picker grouped by category, with search. Widgets an area
  does not take, or that are unavailable, are listed with the reason.
- Drag a widget by its handle to reorder it or move it to another area. With
  the keyboard: focus the handle and press Arrow Up / Arrow Down; "Area" in the
  settings moves it to another area. Moves are announced to screen readers.
- Settings forms come from the schema, with `show_if`, inline errors from the
  server, Restore defaults and a preview (sandboxed, scripts off, without the
  theme's styles).
- Show or hide each widget without deleting it, hide its title, add CSS
  classes, duplicate it, remove it.

Every change goes through the `widgets.*` AJAX actions (`state`, `add`,
`save`, `options`, `remove`, `move`, `order`, `duplicate`, `preview`), each
requiring `manage_apps` and a CSRF token, and each saved under a row lock, so
two people editing at once both keep their changes. Each answers with the
whole new state. The pre-1.2.93 form routes under `/admin/widgets/areas/…`
still work, and now validate.

## APIs

- `GET /api/v1/widget-areas/{area}` (public) lists what visitors see: hidden
  and unavailable instances are left out, and so are `private` and `html`
  settings.
- MCP `get_widget_area` (`settings:read`) lists every instance with its
  settings as the widget reads them, `enabled` and `unavailable`.
- In PHP: `App\Core\Widgets\FrontendWidgets` (`renderArea`, `hasWidgets`,
  `add`, `save`, `move`, `order`, `duplicate`, `remove`, `setOptions`,
  `flush`). `WidgetAreaService` keeps its pre-1.2.93 methods and delegates to it.
- Hooks: filter `widget.output` (`$html, $key, $instance, $areaKey`), action
  `widgets.changed` (`$what, $id`).

## Limits

- The preview shows the widget without the theme's stylesheet.
- `posts` and `media` fields are typed as IDs in the admin; there is no picker
  yet.
- Placements are one settings row; an area holds at most 50 instances.
