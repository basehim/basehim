# Content types, taxonomies and custom fields

Since 1.2.94. An app adds its own kinds of content (events, products,
recipes), the taxonomies that group them, and the extra fields each item
carries. Core gives them an admin list and editor, public pages, a sitemap
entry, site search, the REST API and theme helpers, with nothing for the
app to build.

```php
public function boot(): void
{
    $this->registerPostType('event', [
        'label'      => 'Events',
        'singular'   => 'Event',
        'icon'       => 'calendar',
        'supports'   => ['title', 'content', 'excerpt', 'thumbnail'],
        'taxonomies' => ['category'],          // core's, shared with posts
    ]);
    $this->registerTaxonomy('event-type', [
        'label' => 'Event types', 'singular' => 'Event type',
        'hierarchical' => true, 'post_types' => ['event'],
    ]);
    $this->registerFields('event', [
        ['key' => 'starts_at', 'label' => 'Starts', 'type' => 'date', 'required' => true],
        ['key' => 'venue', 'label' => 'Venue', 'type' => 'text', 'maxlength' => 120],
        ['key' => 'seats', 'label' => 'Seats', 'type' => 'number', 'min' => 1, 'default' => 50],
        ['key' => 'online', 'label' => 'Online event', 'type' => 'checkbox'],
        ['key' => 'stream_url', 'label' => 'Stream link', 'type' => 'url', 'show_if' => ['online' => '1']],
    ], ['title' => 'Event details']);
}
```

That's the whole app side. Its events are now at `/events/summer-meetup`,
listed at `/events`, edited under **Events** in the admin sidebar, and their
fields are in the editor's sidebar.

Registrations are per request, from `boot()`. When the app is switched off,
its types, taxonomies and fields stop existing; the content, terms and field
values stay in the database, and come back when the app does.

## Content types

`registerPostType($key, $args)`. The key is lower-case letters, digits, `-`
and `_` and becomes `posts.type`. `post` and `page` are core's.

| Argument | Default | Meaning |
|---|---|---|
| `label`, `singular` | `{Key}s`, `{Key}` | Names in the admin and on archive pages |
| `description` | `''` | Shown on the archive page by themes that print it |
| `icon` | `document-text` | Sidebar icon (Heroicons name) |
| `public` | `true` | `false`: admin only. No URLs, not in the sitemap or search, no REST |
| `rewrite` | key + `s` | URL prefix: items at `/{rewrite}/{slug}`, archive at `/{rewrite}`. `false`: no core URLs (serve them yourself) |
| `has_archive` | `true` | Whether `/{rewrite}` lists the published items |
| `per_page` | Settings → Reading | Items per archive page |
| `supports` | all | Editor panels: `excerpt`, `thumbnail` (featured image), `comments` (Discussion, and whether comments are accepted). Title, content, status and slug are always there |
| `taxonomies` | `[]` | Taxonomies for this type: `category`, `tag`, or an app's |
| `searchable` | `public` | Whether published items appear in site search |
| `menu` | `true` | `false` hides it from the sidebar; a string names the sidebar section |
| `capability_type` | `post` | Capability family: `edit_posts`, `publish_posts` … |

A prefix that is one of core's paths (`admin`, `api`, `posts`, `page`,
`category`, `tag`, `search`, …) or another type's is refused: the type then
has no URLs, and the reason is in `PostTypeRegistry::rejected()`.

URL routes for types are tried **after** apps' own routes and before the
`/{slug}` page lookup, so an app that already serves `/events` keeps it. A page
whose slug is a type's prefix moves to `/page/{slug}`, as for any route.

Item slugs are unique within their type.

## Taxonomies

`registerTaxonomy($key, $args)`:

| Argument | Default | Meaning |
|---|---|---|
| `label`, `singular` | | Names |
| `hierarchical` | `false` | Terms can have parents |
| `post_types` | `[]` | Types it applies to (a type's own `taxonomies` list attaches it too) |
| `public` | `true` | Term pages at `/{rewrite}/{term}` |
| `rewrite` | the key | URL prefix for term pages; `false` for none |

The taxonomy gets its `{taxonomies}` row the first time it's registered, so
terms are added in the admin (under the type's menu), through
`api()->terms()->create('event-type', [...])`, and stored in `post_term` like
categories. In the editor every taxonomy of a type is a panel of checkboxes;
`category` and `tag` use their usual panels.

Core's category and tag pages also list a type's items when the type takes
`category` or `tag`.

## Custom fields

`registerFields($type, $fields, $group)` declares a group of fields for a
type: an app's, or `post` / `page`. Each group is one panel in the editor
(`$group['title']`, default "Fields"; `description`). Calling it again with
the same title replaces the group.

Fields use the settings schema of frontend widgets
([FRONTEND-WIDGETS.md](FRONTEND-WIDGETS.md#settings-schema)): `key`, `label`,
`type`, `default`, `required`, `help`, `options`, `min` / `max` / `step` /
`maxlength`, `show_if`, `capability`, `private`. Types: text, textarea, html,
number, checkbox, select, multiselect, radio, url, color, date, terms (with
`taxonomy`, `multiple`), posts, media.

- **Keys** are lower-case letters, digits and `_`, unique per type. A key
  another app declared on the same type is refused, so apps can't overwrite
  each other's data. Keys starting with `_` are core's.
- **Saving.** The editor form, the editor's AJAX draft save and
  `saveFields()` all validate against the schema. An invalid value is not
  saved and keeps its previous value; the post itself is saved, and the editor
  says which fields need attention. A required field that is empty doesn't
  stop publishing; the editor warns.
- **Reading** goes through the schema too: every declared field is present,
  of its declared type, with its default when nothing was saved.
- **Private** fields (`'private' => true`) are left out of the theme helpers,
  the REST API and MCP; the app reads them with `fields()`.
- Storage is `{post_meta}`, one row per field (lists as JSON). Moving a post
  to the trash keeps them; deleting it permanently removes them.

## In themes

Item pages render `single-{type}.php`, else `single.php`; archives
`archive-{type}.php`, else `archive.php`; term pages `taxonomy-{key}.php`,
else `archive.php`. Templates get `$post`, `$fields` (the post's public
fields), `$post_type` (the type's definition) and, on archives, `$posts`,
`$meta`, `$archive_type` (`'post_type'` or `'taxonomy'`) and `$taxonomy`.

| Helper | Returns |
|---|---|
| `bh_field('venue', $post = current, $default = null)` | one field's value |
| `bh_fields($post = current)` | every public field, key => value |
| `bh_field_rows($post = current)` | `[{key, label, value, type}]`, values as display text, for a details list |
| `bh_url($post)` | the item's address (`''` when it has none) |
| `bh_query(['type' => 'event', 'limit' => 3, 'order' => 'newest', 'term' => ['taxonomy' => 'event-type', 'slug' => 'workshops']])` | published items, fields loaded |
| `bh_terms($post, 'event-type')` | the post's terms with `name`, `slug`, `url` |

The default theme prints `bh_field_rows()` as a details list on item pages.

## In apps

```php
$events = $this->api()->content('event');
$id = $events->create(['title' => 'Summer meetup', 'status' => 'published']);
$errors = $events->saveFields($id, ['venue' => 'Hall 2', 'seats' => 80]);   // [] when saved
$values = $events->fields($id);                                            // private fields included
```

`saveFields()` writes only the fields given; the rest keep their values.
Both need the `posts.read` / `posts.write` permissions.

## REST and MCP

- `GET /api/v1/content/{type}` and `/api/v1/content/{type}/{slug}`: published
  items of a public type, each with `url` and `fields` (public ones).
  Read-only.
- `GET /api/v1/posts/{slug}` and `/pages/{slug}` include `fields`.
- MCP `get_post` / `get_page` include `fields`.

## Admin

- Each type has its own list (data table `content.type.{key}`, dashes as
  underscores), with Quick Edit, search, filters and bulk actions, and columns
  that follow `supports` and taxonomies. Before 1.2.94 every type's list
  showed posts.
- The editor shows the panels the type uses, its taxonomies and its field
  groups, and a permalink line under the title: the address, **Edit** to
  change the slug in place, and **Preview** / **View**. Preview saves the draft
  first when it has changes (or was never saved) and opens it in a new tab; on
  a phone it's also in the ⋮ menu.

## Limits

- Field values are not versioned (core has no revisions yet).
- `posts` and `media` fields are entered as IDs (media has a Choose button).
- Required fields warn on publish; they don't block it.
- Fields are not searchable through site search.
