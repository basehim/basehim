# Building a Basehim Theme

A theme decides what the public site looks like. It declares what it needs, core
supplies the data, and the templates render it.

A theme cannot break the site. Templates and partials are isolated, a failing
one costs the page section rather than the installation, and a malformed
declaration costs that declaration. That is deliberate: a theme is the part of a
site most likely to be edited by hand on a live server, so it is the part that
gets the most protection.

The other side of that bargain is that a theme cannot register routes or
database tables. Anything of that shape belongs in an app, which has the
lifecycle and permissions to be trusted with it. A theme that needs custom
behaviour ships an app alongside it. Since 1.2.45 a theme may register
**filters** from an optional `boot.php`, which is how it adjusts what core
produces (SEO tags, for example) without owning it.

A theme is about presentation. Two things a theme used to do itself are now
core's job, so every theme gets them right:

- **SEO and social tags**: title, description, canonical, robots, Open Graph,
  X (Twitter), JSON-LD and the generator tag. A theme prints none of them. See
  [SEO and social tags](#seo-and-social-tags).
- **Dates in the site's timezone**: times are stored in UTC and shown with
  `bh_date()` and its siblings. See [Dates and times](#dates-and-times).

## Layout

```
content/themes/my-theme/
├── theme.json                 required: the manifest
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
├── widgets.php                optional: widgets this theme provides
└── boot.php                   optional: filters (seo.*, …), run once per request
```

Only `theme.json` is strictly required, but ship all six templates. A template
that does not exist falls back to **`404.php`**, not to `index.php`. A theme
missing `archive.php` therefore serves a not-found page on every category,
which is confusing to debug. If both are missing, the visitor gets a bare
"Template Not Found" heading.

## Manifest

```json
{
  "name": "My Theme",
  "slug": "my-theme",
  "version": "1.0.0",
  "author": "You",
  "description": "One sentence about what this theme is for.",
  "requires": { "basehim": ">=1.2.45" },

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
active. A widget area may be a plain string (the label) or an object with the
wrapper markup above. The object form is worth using, so widgets inherit the
theme's own styling instead of each inventing its own.

An optional `"seo"` key hands SEO tag groups to the theme. Leave it out unless
the theme really must print a group itself; see
[Handing a group to the theme](#handing-a-group-to-the-theme).

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

A partial cannot include another partial, because `$partial` is only defined in
the top-level template. Keep partials flat.

### What a template receives

Every template:

| | |
|---|---|
| `$base` | URL prefix: `''` at a domain root, `/sub` in a subdirectory |
| `$site_title`, `$tagline` | from the Customizer |
| `$logo_url`, `$favicon_url`, `$footer_text` | from the Customizer |
| `$primary_menu`, `$footer_menu` | menu trees, ready for `menu_html()` |
| `$seo` | the page's final `title`, `description`, `canonical`, `robots`, `og_title`, `og_description`, `og_image`, for **showing** (a breadcrumb, a share button). Core prints the tags itself |
| `$current_url` | the request path |

List templates (`index`, `archive`, `search`):

| | |
|---|---|
| `$posts` | the rows for this page |
| `$meta` | `['page' => 1, 'per_page' => 10, 'total' => 2930, 'last_page' => 293]` |
| `$query` | the search term, on `search` |

Single templates (`single`, `page`): `$post`, with `content` already rendered to
HTML whatever format it was written in.

Every time in `$post`, `$posts`, `$comments` and the rest
(`published_at`, `created_at`, `updated_at`) is **UTC**. Show it with
`bh_date()`, never `date(…, strtotime(…))`. See [Dates and times](#dates-and-times).

> `$meta` is the pagination, and it's easy to guess wrong. There is no
> `$pagination` variable. A theme that looks for one finds nothing, fails
> silently, and shows only its first ten posts with no error to explain why.

## Pagination

Links use `?page=N`. A path like `/page/2` collides with the static-page route
`/page/{slug}` and resolves to a page named "2".

```php
<?php
$page  = max(1, (int) ($meta['page'] ?? 1));
$pages = max(1, (int) ($meta['last_page'] ?? 1));
if ($pages < 2) return;

// Keep the rest of the query string. Rebuilding from the path alone drops the
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
first and last always reachable, rather than every number. Core's canonical URL
keeps `?page=N` past page one, and titles get "– Page N" on their own.

## The head and the footer

A theme's `<head>` holds what only the theme knows: charset, viewport, its
stylesheets, fonts, favicon. Everything else arrives through two calls:

```php
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($seo['title'] ?? $site_title) ?></title>
    <link rel="stylesheet" href="…/my-theme.css?v=…">
    <?= bh_head() ?>
</head>
<body class="<?= bh_body_class('my-theme') ?>">
    …
    <?= bh_footer() ?>
</body>
```

`bh_head()` emits the Customizer's CSS variables, the site's custom CSS, any
stylesheet or head script an app registered, AI discovery links (`llms.txt`,
the agent catalogue), and whatever apps add through `bh.head`. `bh_footer()`
emits deferred scripts, footer contributions and the in-page AI tools.

**SEO and social tags are not part of `bh_head()`.** Core adds them to every
finished page itself, right after `<meta charset>`, whether or not the theme
calls `bh_head()`. The `<title>` line above is only a fallback: core replaces
its text with the page's title. Print nothing else of that kind.

A theme that calls neither function still renders, but it receives nothing. An
analytics app installed on that site will do nothing, with no indication why.
Call both.

## SEO and social tags

Core prints these on every page, built from the post, its SEO fields in the
editor, and **Settings → SEO**:

| Group | What it prints |
|---|---|
| `title` | `<title>`: the post's SEO title, or the title format (`%title% %sep% %site%` …); the home page title on the home page |
| `description` | `<meta name="description">`: SEO description, excerpt, then the defaults |
| `canonical` | `<link rel="canonical">`, absolute, keeping `?page=N` past page one |
| `robots` | `<meta name="robots">`: the post's own setting; `noindex` on search, 404 and previews; `max-image-preview:large` |
| `opengraph` | `og:locale`, `og:type`, `og:title`, `og:description`, `og:url`, `og:site_name`, `og:image` (+ size, alt, type), and on posts `article:published_time`, `article:modified_time`, `article:section`, `article:tag` |
| `twitter` | `twitter:card`, `twitter:title`, `twitter:description`, `twitter:image`, `twitter:image:alt`, `twitter:site` |
| `jsonld` | one `<script type="application/ld+json">` with an `@graph` of `Organization`, `WebSite` (with the search box), `WebPage` / `CollectionPage` / `ProfilePage` / `SearchResultsPage`, `BlogPosting` on posts, and `BreadcrumbList`, linked by `@id` |
| `generator` | `<meta name="generator" content="Basehim CMS">`, without a version |

The share image is the social image set in the post's SEO panel, then its
featured image, then the default share image in Settings → SEO. URLs are always
absolute. Dates are ISO 8601 in the site's timezone.

**A theme prints none of these.** If an older theme still does, core removes
the theme's copies from the finished page, so crawlers see one consistent set.
The **Remove SEO tags printed by the theme** switch in Settings → SEO controls
this. Tags that apps add through `bh_head()` are never removed.

### Settings

Settings → SEO turns the whole service off (core then adds nothing and leaves the
theme's tags alone) or each group and each JSON-LD type on and off. It also
holds the home page title and description, the title format and separator,
the default description and share image, and the X (Twitter) handle.

### Changing tags from a template

While a page renders, `bh_seo()` adjusts that page. The head is assembled after
the template has run, so this works anywhere in a template, not just in the
header:

```php
<?php
bh_seo()->set('title', 'Pricing – Acme');               // the whole <title>, used as is
bh_seo()->set('description', 'Plans for every team.');
bh_seo()->set('image', ['url' => $base . '/uploads/2026/10/pricing.png', 'width' => 1200, 'height' => 630]);
bh_seo()->set('robots', 'noindex, follow');
bh_seo()->set('og_type', 'product');
bh_seo()->addJsonLd(['@type' => 'Product', 'name' => 'Acme Pro', 'offers' => ['@type' => 'Offer', 'price' => '9', 'priceCurrency' => 'USD']]);
bh_seo()->disable('twitter');      // no twitter:* tags on this page, from anyone
bh_seo()->handOff('jsonld');       // this template prints its own JSON-LD; core stays out
?>
```

`set()` keys: `title`, `description`, `canonical`, `robots`, `image`
(a URL or `['url','width','height','alt','type']`), `og_type`, `og_title`,
`og_description`, `twitter_card`. `bh_seo_value('title')` reads a value back.

### Changing tags for every page: filters

Register filters in the theme's `boot.php` (or, in an app, in `boot()` with
`$this->addFilter()`). Each filter receives the value and the page context;
pass `2` as `acceptedArgs` (the `10, 2` below) to get `$ctx` as well:

```php
<?php
// content/themes/my-theme/boot.php — $app and $hooks are in scope.

// Add a node to the JSON-LD graph on the home page.
$hooks->addFilter('seo.jsonld', function (array $nodes, array $ctx): array {
    if ($ctx['type'] === 'home') {
        $nodes[] = ['@type' => 'SoftwareApplication', 'name' => 'Acme',
                    'applicationCategory' => 'DeveloperApplication',
                    'offers' => ['@type' => 'Offer', 'price' => '0', 'priceCurrency' => 'USD']];
    }
    return $nodes;
}, 10, 2);

// Prefix archive titles.
$hooks->addFilter('seo.title', fn(string $t, array $ctx) => $ctx['type'] === 'archive' ? 'Topic: ' . $t : $t, 10, 2);

// A share image for the home page.
$hooks->addFilter('seo.image', function (?array $img, array $ctx) {
    return $ctx['type'] === 'home' ? ['url' => '/content/themes/my-theme/assets/og-home.png', 'width' => 1200, 'height' => 630] : $img;
}, 10, 2);
```

| Filter | Value | |
|---|---|---|
| `seo.enabled` | `bool` | `false` turns the whole service off for this request |
| `seo.groups` | `['title' => 'core', 'opengraph' => 'off', …]` | per group: `core`, `off` (nobody prints it) or `external` (core leaves it alone) |
| `seo.title`, `seo.description`, `seo.canonical`, `seo.robots` | `string` | |
| `seo.image` | `?array` (`url`, `width`, `height`, `alt`, `type`) | `null` for no image |
| `seo.jsonld` | `array` of nodes | the `@graph`; add, change or remove nodes |
| `seo.head` | `['values', 'tags', 'jsonld', 'states', 'context']` | the whole result, last word: `tags` is a list of `['group', 'tag' => 'meta'\|'link', 'attrs' => […]]` |

`$ctx` holds `type` (`home`, `post`, `page`, `archive`, `author`, `search`,
`404`, `other`), `template`, `post`, `terms`, `term`, `author`, `query`, `page`
(the page number), `is_preview`, and `seo` (what the route worked out).

### Handing a group to the theme

Rarely, a theme has to print a group itself. Say so in `theme.json`, and core
will neither print that group nor remove the theme's copy:

```json
"seo": { "jsonld": false }
```

`"seo": false` hands everything to the theme (core adds no SEO tags while that
theme is active). Settings → SEO shows the site owner when the active theme
does this. Prefer the filters above: they keep everything in one place and
leave the site owner's switches working.

### Moving an older theme over

Delete from the theme's `<head>`: `<meta name="description">`,
`<link rel="canonical">`, `<meta name="robots">`, every `og:*`, `article:*` and
`twitter:*` tag, `<script type="application/ld+json">` blocks and
`<meta name="generator">`. Keep a plain `<title>` as a fallback. Anything the
theme was customising (a fixed home page title, a logo as the share image, a
`SoftwareApplication` schema) moves to Settings → SEO or to a filter in
`boot.php`. Until then nothing breaks: core removes the old tags itself.

## Dates and times

Basehim stores every time in **UTC**. **Settings → General** sets the site's
timezone, date format and time format, and everything a visitor or editor sees
is converted on the way out. Changing the timezone changes every date on the
site at once, with nothing rewritten in the database.

PHP itself runs in UTC, so `date('M j', strtotime($post['published_at']))`
shows the UTC date, which is wrong for most of the world. Use the helpers:

```php
<?= bh_date($post['published_at']) ?>                 <!-- October 10, 2026   (site date format) -->
<?= bh_date($post['published_at'], 'M j') ?>          <!-- Oct 10            (your format, site timezone) -->
<?= bh_time($post['published_at']) ?>                 <!-- 1:32 pm -->
<?= bh_datetime($comment['created_at']) ?>            <!-- October 10, 2026 1:32 pm -->
<?= bh_time_ago($comment['created_at']) ?>            <!-- 5 minutes ago; the date after 7 days -->
<?= bh_time_tag($post['published_at'], 'M j, Y') ?>   <!-- <time datetime="2026-10-10T13:32:00+05:00" title="…">Oct 10, 2026</time> -->
```

| | |
|---|---|
| `bh_date($t, $format = null)` | date in the site timezone; site date format by default |
| `bh_time($t, $format = null)` | time; site time format by default |
| `bh_datetime($t, $format = null)` | both |
| `bh_time_ago($t, $maxDays = 7)` | "5 minutes ago", "in 2 days"; the date once older than `$maxDays` |
| `bh_time_tag($t, $format = null, $relative = false)` | a `<time datetime>` element, with the full date as its title |
| `bh_iso8601($t)` | `2026-10-10T13:32:00+05:00`, for `datetime=` attributes and structured data |
| `bh_local_time($t)` | a `DateTimeImmutable` in the site timezone, for anything else |
| `bh_to_utc($local)` | a time a person typed in the site timezone, as the UTC string to store |
| `bh_now()` | now, UTC, `Y-m-d H:i:s`, for storing |
| `bh_timezone()` | `Asia/Karachi` |

`$t` can be a stored `Y-m-d H:i:s` string (read as UTC), any string with its own
offset, a Unix timestamp or a `DateTimeInterface`. Empty input gives `''`.

The same lives in PHP as `App\Core\Time` (`Time::date()`, `Time::local()`,
`Time::info()` …) for apps. Scripts can ask the API:

| | |
|---|---|
| `GET /api/v1/time` | timezone, offset, abbreviation, date/time formats, week start, now in UTC and local |
| `GET /api/v1/timezones` | every timezone with its current offset |
| `GET /api/v1/time/convert?value=…&to=local\|utc[&format=…]` | convert one time |

In the admin, scripts get the same through `BasehimTime.format()`,
`.date()`, `.time()` and `.ago()` (from `admin/assets/js/time.js`). Those use
the site's timezone, not the browser's.

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

This is what makes the live preview instant. If you read an option in PHP to
style something, the preview has to reload to show a colour change. A custom
property updates with no request at all.

Options whose value changes the markup (a select, a toggle, an image) reload
the preview frame instead. Core decides which by type, and `"preview": "css"` or
`"preview": "reload"` overrides it.

### Reading an option in PHP

For the ones that genuinely aren't CSS:

```php
<?php if (bh_theme_option('show_author', true)): ?>
    <span><?= htmlspecialchars($post['author_name']) ?></span>
<?php endif; ?>
```

`bh_theme_option()` returns the pending value inside a Customizer preview and
the saved one everywhere else, so a preview shows what is being chosen.

### What a theme cannot do to the Customizer

A malformed declaration is dropped, with a line in the log, and the rest of the
screen still works. A theme cannot overwrite a core section either; if it could,
it could hide the logo field and leave the site owner stuck.

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
array**. Core reads it, namespaces the keys and registers them:

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

It's a returned array rather than a call with side effects, so core decides when
to read it, can validate it, and a mistake costs the widget rather than the
request.

## Boot file

`boot.php` at the theme's root runs once per request, after apps boot and
before anything renders. `$app` and `$hooks` are in scope. Use it to register
filters (the `seo.*` ones above, `comment_form.args`, `author_box.html` …), not
to output anything. A failure in it is logged and the site carries on.

## Comments

Core provides the comment form. One call, wherever the template has the post:

```php
<?= bh_comment_form($post) ?>
```

It carries everything the comment handler checks (the CSRF token, the post id,
the reply target and the spam honeypot) and submits without a page reload. A
theme that builds its own form has to get every one of those right. A missing
token means a rejected comment, and a missing honeypot means a spam filter that
never fires.

**Signed-in members are not asked who they are.** Guests see name, email and
(optionally) website fields. A member sees "Commenting as *name*" and the
comment is recorded under their account. The handler enforces the same rule,
so an old theme form that still shows the fields cannot let a member post
under another name.

When comments are off (for the site, for this post, or because the post isn't
published), it returns the closed text instead.

### Styling it

The form uses `bh-comment-form__*` classes and ships a small stylesheet at zero
specificity, so any rule your theme writes wins. To put your own classes on the
elements:

```php
<?= bh_comment_form($post, [
    'class'          => 'card',              // wrapper
    'title_class'    => 'card__title',
    'field_class'    => 'field',
    'label_class'    => 'field__label',
    'input_class'    => 'input',
    'textarea_class' => 'input input--area',
    'button_class'   => 'btn btn--primary',
    'styles'         => false,               // drop core's stylesheet entirely
]) ?>
```

Text: `title` (empty for none), `title_tag`, `label_name`, `label_email`,
`label_url`, `label_comment`, `placeholder`, `label_submit`,
`label_submitting`, `label_reply`, `label_cancel`, `logged_in_text` (`%s` is the
member's name), `closed_text` (empty for nothing). Behaviour: `show_url`,
`rows`, `show_logout`, `id` (needed only for a second form on one page).

### Replies

Any element with `data-bh-reply` turns the form into a reply to that comment:

```php
<button type="button" data-bh-reply data-id="<?= (int) $c['id'] ?>"
        data-name="<?= htmlspecialchars($c['author_name']) ?>">Reply</button>
```

Replies arrive as `parent_id` on each comment in `$comments`; render them nested
however the theme likes. Show each comment's time with
`bh_time_tag($c['created_at'], null, true)` ("5 minutes ago", in the site
timezone).

### The count

`$comments_count` holds the number of approved comments, and
`bh_comment_count($post)` returns it anywhere else. Mark the element and the
form updates it in place when a comment is published immediately:

```php
Comments <span data-bh-comment-count="<?= (int) $post['id'] ?>"><?= (int) $comments_count ?></span>
```

A comment that is published immediately also reloads the page at that comment,
so it appears in the theme's own markup. To handle it yourself instead, listen
for `bh:comment-posted` on the form and call `preventDefault()`. The event's
`detail` has `status`, `pending`, `message`, `comment`, `count` and `postId`.

Apps can adjust the form through the `comment_form.args` and
`comment_form.html` filters.

## Authors

Core provides the author box. One call on a single-post template:

```php
<?= bh_author_box($post) ?>
```

It shows the author's avatar (their uploaded picture, otherwise initials),
their name linked to their archive, their bio, and a link to their other
posts. It returns nothing when **Settings → Reading → Author box on posts** is
off, so a theme never needs its own switch.

Style it like the comment form (`bh-author-box__*` classes, a default
stylesheet at zero specificity, `'styles' => false` to drop it), or pass your
classes:

```php
<?= bh_author_box($post, [
    'title'        => 'About the author',   // empty by default
    'class'        => 'card',
    'avatar_class' => 'avatar',
    'name_class'   => 'card__title',
    'bio_class'    => 'card__text',
    'link_class'   => 'link',
    'avatar_size'  => 56,                   // px
    'link_text'    => 'All %d posts',       // %d is the post count
]) ?>
```

Other options: `title_tag`, `show_bio`, `show_link`. Apps can change the
output through the `author_box.html` filter.

### Linking to an author

```php
<?php if ($url = bh_author_url($post)): ?>
    <a href="<?= htmlspecialchars($base . $url) ?>" rel="author"><?= htmlspecialchars($post['author_name']) ?></a>
<?php else: ?>
    <?= htmlspecialchars($post['author_name']) ?>
<?php endif; ?>
```

`bh_author_url()` is empty when author archives are switched off
(**Settings → Reading → Author archive pages**) or the author has no published
posts, so the fallback matters.

`bh_author($post)` returns the author's public profile: `display_name`, `bio`,
`slug`, `url`, `avatar_url`, `post_count`.

### The author archive

`/author/{slug}` renders `archive.php` with `$archive_type === 'author'` and
`$author` holding that same public profile. The slug comes from the display
name and is never the login name; authors can change it on their profile.
`$author['username']` holds the public slug too, so older templates that print
it don't reveal a login name. `$author` never contains an email address.

## Helpers

| | |
|---|---|
| `bh_head()`, `bh_footer()` | everything core and apps need on the page |
| `bh_seo()` | this page's SEO head: `set()`, `get()`, `addJsonLd()`, `disable()`, `handOff()` |
| `bh_seo_value($key)` | a value of this page's SEO head |
| `bh_date()`, `bh_time()`, `bh_datetime()` | a stored time in the site timezone and formats |
| `bh_time_ago()`, `bh_time_tag()`, `bh_iso8601()` | relative time, `<time>` element, ISO 8601 |
| `bh_local_time()`, `bh_to_utc()`, `bh_now()`, `bh_timezone()` | conversions and the site timezone |
| `bh_body_class($extra)` | `is-home`/`is-inner`, the theme slug, preview state |
| `bh_theme_option($key, $default)` | a theme option, preview-aware |
| `bh_setting($group, $key, $default)` | a site setting |
| `bh_is_preview()` | true inside the Customizer's preview frame |
| `menu_html($items, $opts)` | a menu as nested lists |
| `menu_at($location)` | items for any declared location |
| `menu_has_children($items)` | whether any item has children |
| `menu_assets()` | dropdown CSS and behaviour, once per request |
| `widget_area($key)`, `has_widget_area($key)` | widget areas |
| `bh_comment_form($post, $args)` | the standard comment form |
| `bh_comment_count($post)` | approved comments on a post |
| `bh_author_box($post, $args)` | the author box |
| `bh_author_url($post)` | link to the author's archive, or empty |
| `bh_author($post)` | the author's public profile |
| `bh_post_image($post, $size, $attrs)` | a responsive featured image |
| `bh_image_url($post, $size)`, `bh_image_sizes($post)` | featured image URLs |
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
- A `boot.php` that throws is logged; the site carries on without its filters.
- An `seo.*` filter that throws is skipped; the page keeps core's values.

None of this makes a mistake acceptable. It makes it survivable and legible.
Check the log; the reason is there.

## Before shipping

- Render every template. A syntax check cannot catch a call to a method that
  doesn't exist, which is the most common way a theme fails on its first load.
- Test pagination past page one, and with a search term, which is where a
  half-built URL shows up.
- Try each Customizer option and confirm it does something.
- Look at it below 1000px. Sidebars usually want to disappear rather than stack.
- Confirm `bh_head()` and `bh_footer()` are both present.
- Confirm the `<head>` prints no description, canonical, robots, `og:*`,
  `twitter:*`, JSON-LD or generator tags of its own. View source on a post:
  there should be one set, under `<!-- SEO (Basehim) -->`.
- Change the timezone in Settings → General and check that every date on the
  theme moves with it. Any date that doesn't move is a `date(…, strtotime(…))`
  left over.
- Post a comment signed out and signed in. Signed in, the form should not ask
  for a name or email.

## Packaging

Zip the theme directory itself, so the archive contains `my-theme/theme.json`
and not `theme.json` at its root. Install through Appearance → Themes → Upload.

## Reference

`content/themes/default` is a small, plain theme worth reading first.
`content/themes/circuits-diy` is a fuller one: a mega menu, two optional
sidebars, twenty-five Customizer options, and a windowed pager over hundreds of
pages.

## Featured images in the right size

Core makes three versions of every uploaded image, set under
**Settings → Media**: `thumbnail` (a square crop, 150 px by default), `medium`
(fits 300 px) and `large` (fits 1024 px). `full` is the original. Posts loaded
by core carry them, so no extra query is needed.

```php
// A responsive <img>: src is the size you ask for, srcset lists the other
// sizes of the same shape, so the browser picks the smallest sharp file.
<?= bh_post_image($post, 'medium', [
    'class' => 'card__img',
    'sizes' => '(max-width: 600px) 100vw, 320px',   // how wide the slot is
]) ?>

// Just a URL (falls back to the original when a size was not made):
$url = bh_image_url($post, 'thumbnail');

// Everything, smallest first: name => ['url', 'width', 'height']
$all = bh_image_sizes($post);
```

`bh_post_image()` returns `''` when a post has no featured image, adds width
and height (no layout shift) and `loading="lazy"` unless you pass
`'loading' => 'eager'`. The square `thumbnail` is never mixed into the
srcset of the other sizes.

Images imported from another system may have no sizes yet: **Settings → Media
→ Regenerate thumbnails** builds them in batches, and can be stopped and
resumed.

The featured image is also the page's share image (Open Graph, X, JSON-LD)
unless the post's SEO panel sets a different one. Nothing to do in the theme.
