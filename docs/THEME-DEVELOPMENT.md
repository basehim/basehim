# Building a Basehim Theme

A theme decides what the public site looks like. It declares what it needs, core
supplies the data, and the templates render it.

A theme cannot break the site. Templates and partials are isolated, a failing
one costs the page section rather than the installation, and a malformed
declaration costs that declaration. That is deliberate: a theme is the part of a
site most likely to be edited by hand on a live server, so it is the part that
gets the most protection.

The other side of that bargain is that a theme cannot register routes, hooks or
database tables. Anything of that shape belongs in an app, which has the
lifecycle and permissions to be trusted with it. A theme that needs custom
behaviour ships an app alongside it.

## Layout

```
content/themes/my-theme/
├── theme.json                 required — the manifest
├── templates/
│   ├── index.php              post lists: home and paginated archives
│   ├── single.php             one post
│   ├── page.php               one static page
│   ├── archive.php            category, tag and author archives
│   ├── search.php             search results
│   ├── 404.php                not found
│   └── partials/              anything the templates share
│       ├── header.php
│       └── footer.php
├── assets/
│   ├── my-theme.css
│   └── my-theme.js
└── widgets.php                optional — widgets this theme provides
```

Only `theme.json` is strictly required, but ship all six templates. A template
that does not exist falls back to **`404.php`**, not to `index.php` — so a theme
missing `archive.php` serves a not-found page on every category, which is a
confusing thing to debug. If both are missing the visitor gets a bare
"Template Not Found" heading.

## Manifest

```json
{
  "name": "My Theme",
  "slug": "my-theme",
  "version": "1.0.0",
  "author": "You",
  "description": "One sentence about what this theme is for.",
  "requires": { "basehim": ">=1.2.0" },

  "menu_locations": {
    "primary": "Main menu",
    "utility": "Small links above the header",
    "footer_legal": "Footer — legal column"
  },

  "widget_areas": {
    "sidebar": {
      "name": "Sidebar",
      "description": "Beside the content.",
      "before_widget": "<section id=\"%1$s\" class=\"widget %2$s\">",
      "after_widget": "</section>",
      "before_title": "<h2 class=\"widget__title\">",
      "after_title": "</h2>"
    }
  },

  "customizer": {
    "colors": {
      "label": "Colours",
      "options": {
        "accent": { "type": "color", "label": "Accent", "default": "#2563eb" }
      }
    }
  }
}
```

`slug` must match the directory name.

`menu_locations` and `widget_areas` appear in the admin as soon as the theme is
active. A widget area may be a plain string — the label — or an object with the
wrapper markup above, which is worth doing so widgets inherit the theme's own
styling instead of each inventing its own.

## Templates

Templates are plain PHP. Core hands them the data as variables.

```php
<?php $partial('header'); ?>

<div class="wrap">
    <?php foreach ($posts as $post): ?>
        <?php $partial('card', ['post' => $post]); ?>
    <?php endforeach; ?>

    <?php $partial('pagination'); ?>
</div>

<?php $partial('footer'); ?>
```

**`$partial('name', $overrides)`** includes `templates/partials/name.php`. It
passes the template's own data automatically, so only an override needs listing.

`$this` inside a template is the ThemeService, not a view engine. There is no
`$this->include()`; use `$partial()`.

A partial cannot include another partial — `$partial` is only defined in the
top-level template. Keep partials flat.

### What a template receives

Every template:

| | |
|---|---|
| `$base` | URL prefix — `''` at a domain root, `/sub` in a subdirectory |
| `$site_title`, `$tagline` | from the Customizer |
| `$logo_url`, `$favicon_url`, `$footer_text` | from the Customizer |
| `$primary_menu`, `$footer_menu` | menu trees, ready for `menu_html()` |
| `$seo` | `title`, `description`, `canonical`, `robots`, `og_*` |
| `$current_url` | the request path |

List templates — `index`, `archive`, `search`:

| | |
|---|---|
| `$posts` | the rows for this page |
| `$meta` | `['page' => 1, 'per_page' => 10, 'total' => 2930, 'last_page' => 293]` |
| `$query` | the search term, on `search` |

Single templates — `single`, `page`: `$post`, with `content` already rendered to
HTML whatever format it was written in.

> `$meta` is the pagination, and it is easy to guess wrong. There is no
> `$pagination` variable; a theme that looks for one finds nothing, returns
> silently, and shows only its first ten posts with no error to explain why.

## Pagination

Links use `?page=N`. A path like `/page/2` collides with the static-page route
`/page/{slug}` and resolves to a page named "2".

```php
<?php
$page  = max(1, (int) ($meta['page'] ?? 1));
$pages = max(1, (int) ($meta['last_page'] ?? 1));
if ($pages < 2) return;

// Keep the rest of the query string — rebuilding from the path alone drops the
// search term on page two.
$path  = strtok((string) ($_SERVER['REQUEST_URI'] ?? '/'), '?');
$url = function (int $n) use ($path) {
    $q = $_GET;
    if ($n <= 1) unset($q['page']); else $q['page'] = $n;
    $s = http_build_query($q);
    return htmlspecialchars($path . ($s !== '' ? '?' . $s : ''), ENT_QUOTES);
};
?>
```

On a site with hundreds of pages, show a window around the current page with the
first and last always reachable, rather than every number.

## The head and the footer

Two calls. Everything core and every installed app needs on the front end
arrives through them.

```php
<head>
    …
    <?= bh_head() ?>
</head>
<body class="<?= bh_body_class('my-theme') ?>">
    …
    <?= bh_footer() ?>
</body>
```

`bh_head()` emits the Customizer's CSS variables, the site's custom CSS, any
stylesheet an app registered, and whatever apps contribute. `bh_footer()` emits
deferred scripts and footer contributions.

A theme that calls neither still works — it simply receives nothing, so an
analytics app installed on that site will do nothing and there will be no
indication why. Call both.

## Customizer options

A theme declares options and core renders the screen. There is no admin code to
write.

```json
"customizer": {
  "brand": {
    "label": "Brand",
    "description": "Shown above the fields.",
    "options": {
      "accent":  { "type": "color", "label": "Accent", "default": "#e63329" },
      "width":   { "type": "range", "label": "Page width",
                   "min": 1000, "max": 1600, "step": 20,
                   "default": 1240, "unit": "px" },
      "layout":  { "type": "select", "label": "Post list",
                   "choices": { "grid": "Grid", "list": "List" },
                   "default": "grid" },
      "hero":    { "type": "image", "label": "Hero image" },
      "compact": { "type": "toggle", "label": "Compact spacing", "default": false }
    }
  }
}
```

Types: `text`, `textarea`, `color`, `select`, `toggle`, `number`, `range`,
`image`, `url`, `font`.

Per option: `label`, `default`, `help`, `placeholder`; `min`/`max`/`step` for
numbers and ranges; `unit` appended to the CSS value; `choices` for a select;
`rows` and `mono` for a textarea; `css_var` to override the generated property
name.

### Options reach CSS on their own

Every colour, range, number and font option becomes a custom property:

```
accent  →  --bh-accent
width   →  --bh-content-width   (with its unit)
```

Write the stylesheet against them, with the declared default as the fallback:

```css
:root {
    --t-accent: var(--bh-accent, #e63329);
    --t-width:  var(--bh-content-width, 1240px);
}
.button { background: var(--t-accent); }
```

This is what makes the live preview instant. Reading an option in PHP to style
something means the preview must reload to show a colour change; a custom
property updates with no request at all.

Options whose value changes the markup — a select, a toggle, an image — reload
the preview frame instead. Core decides which by type, and `"preview": "css"` or
`"preview": "reload"` overrides it.

### Reading an option in PHP

For the ones that genuinely are not CSS:

```php
<?php if (bh_theme_option('show_author', true)): ?>
    <span><?= htmlspecialchars($post['author_name']) ?></span>
<?php endif; ?>
```

`bh_theme_option()` returns the pending value inside a Customizer preview and
the saved one everywhere else, so a preview shows what is being chosen.

### What a theme cannot do to the Customizer

A declaration that is malformed is dropped, with a line in the log, and the rest
of the screen still works. A theme cannot overwrite a core section either — it
would be able to hide the logo field and leave the site owner stuck.

Values are validated on the way in, because they are written into a stylesheet.
A colour must be a hex colour, a select value must be one the theme offered, a
font may not contain braces or semicolons, and a number is clamped to its
declared range. Anything rejected is reported rather than dropped quietly.

## Menus

```php
<?= menu_html($primary_menu, ['class' => 'my-menu', 'aria' => 'Primary']) ?>
```

Emits nested lists to three levels with `aria-haspopup`, `aria-expanded` and a
caret on parents. `menu_assets()` in `<head>` adds the positioning and the
open/close behaviour; restyle `.bh-submenu` to make it look like your theme.

For a location the theme declared itself:

```php
<?= menu_html(menu_at('utility')) ?>
```

Core only passes `$primary_menu` and `$footer_menu` as variables. Any other
location needs `menu_at()`.

## Widgets

```php
<?php if (has_widget_area('sidebar')): ?>
    <aside class="sidebar"><?= widget_area('sidebar') ?></aside>
<?php endif; ?>
```

Guard with `has_widget_area()`. An area with no widgets renders as an empty
string, and reserving a column for it leaves a gap on the page.

A theme can provide its own widgets from `widgets.php`, which **returns an
array** — core reads it, namespaces the keys and registers them:

```php
<?php
return [
    'my_promo' => [
        'title'  => 'Promo box',
        'fields' => [
            'heading' => ['type' => 'text', 'label' => 'Heading'],
            'text'    => ['type' => 'textarea', 'label' => 'Text', 'rows' => 4],
        ],
        'render' => function (array $s): string {
            $h = trim((string) ($s['heading'] ?? ''));
            return $h === '' ? '' : '<div class="promo">' . htmlspecialchars($h) . '</div>';
        },
    ],
];
```

A returned array rather than a call with side effects: core decides when to read
it, can validate it, and a mistake costs the widget rather than the request.

## Helpers

| | |
|---|---|
| `bh_head()`, `bh_footer()` | everything core and apps need on the page |
| `bh_body_class($extra)` | `is-home`/`is-inner`, the theme slug, preview state |
| `bh_theme_option($key, $default)` | a theme option, preview-aware |
| `bh_setting($group, $key, $default)` | a site setting |
| `bh_is_preview()` | true inside the Customizer's preview frame |
| `menu_html($items, $opts)` | a menu as nested lists |
| `menu_at($location)` | items for any declared location |
| `menu_has_children($items)` | whether any item has children |
| `menu_assets()` | dropdown CSS and behaviour, once per request |
| `widget_area($key)`, `has_widget_area($key)` | widget areas |
| `link_to($path)` | a URL that respects a subdirectory install |
| `icon($name, $class)` | a Heroicon as inline SVG |
| `theme_asset($rel)` | a URL under this theme's `assets/` |

## Assets

```php
<link rel="stylesheet" href="<?= $base ?>/content/themes/my-theme/assets/my-theme.css?v=<?= urlencode(BASEHIM_VERSION) ?>">
```

Version the query string. Without it a browser keeps the previous stylesheet
after an update, which looks exactly like the update not having worked.

## What happens when something breaks

- A template that throws shows a plain page naming the file and line to
  administrators, and a short message to everyone else. The site stays up.
- A partial that throws costs that partial.
- A widget that throws costs that widget, not the area.
- A malformed Customizer declaration costs that option.

None of this makes a mistake acceptable — it makes it survivable and legible.
Check the log; the reason is there.

## Before shipping

- Render every template. A syntax check cannot catch a call to a method that
  does not exist, which is the most common way a theme fails on its first load.
- Test pagination past page one, and with a search term, which is where a
  half-built URL shows up.
- Try each Customizer option and confirm it does something.
- Look at it below 1000px. Sidebars usually want to disappear rather than stack.
- Confirm `bh_head()` and `bh_footer()` are both present.

## Packaging

Zip the theme directory itself, so the archive contains `my-theme/theme.json`
and not `theme.json` at its root. Install through Appearance → Themes → Upload.

## Reference

`content/themes/default` is a small, plain theme worth reading first.
`content/themes/circuits-diy` is a fuller one: a mega menu, two optional
sidebars, twenty-five Customizer options, and a windowed pager over hundreds of
pages.
