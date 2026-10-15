# Basehim 1.2.55

Fixes and polish for the **Data Table** shipped in 1.2.54, and reusable
avatar/thumbnail components.

## Fixes

- **Mobile list was blank; Screen Options would not open.** The client looked
  for the table's config on the wrong element, so it never initialised: the
  desktop table still showed (it is server-rendered) but the JavaScript-built
  mobile cards and the Screen Options panel did nothing. Fixed the mount
  selector in `admin/assets/js/datatable.js`; cards, the panel, sorting,
  filtering, pagination and bulk selection now work on every screen.
- **JS-drawn icons were missing.** The sort carets, pagination arrows, the
  Screen Options drag handles and the row-edit icon are now drawn with inline
  SVG inside the table script, so they no longer depend on which names the
  shared JS icon set happens to include.

## Assets cache-bust on change

The table's CSS and JS are versioned by their file modification time, so an
updated file is picked up by browsers immediately — no release bump and no hard
refresh needed.

## UI matches the design

The Posts and Pages tables now match the intended design: a search box with
category and status filters, a selection bar with Publish / Unpublish / Delete
actions, sortable headers, numbered pagination with a rows-per-page control, a
Screen Options panel (drag to reorder, tick to show/hide, rows per page,
density), and a card layout on phones.

- **Thumbnail column** — a small featured-image thumbnail (placeholder when a
  post has none), on by default and hideable in Screen Options.
- **Author column** — the author's avatar (their photo, or initials on a
  colour) next to their name.
- **Category filter** — the Posts/Pages tables can now be filtered by category;
  `PostRepository::paginate()` accepts a `category` term id (a bound subquery).

## Reusable components

New `App\Core\Admin\Components` renders the avatar, the author cell, a media
thumbnail and a status pill, so any admin view or table column shows them the
same way. Also available as the globals `bh_avatar_html()`, `bh_user_cell()`
and `bh_thumb()`.

## Files

New:
- `app/Core/Admin/Components.php`
- `tests/Unit/ComponentsTest.php`

Changed:
- `admin/assets/js/datatable.js` — mount-selector fix, inline icons, bulk
  action buttons, card layout, rows-per-page, select-all state.
- `admin/assets/css/datatable.css` — restyled to the design; avatar, thumbnail
  and pill components.
- `admin/views/partials/datatable.php` — search + category + status filters,
  selection bar with action buttons, footer with rows-per-page, filemtime
  cache-busting, live category options.
- `routes/tables.php` — thumbnail and author (avatar) columns via Components.
- `app/Repositories/PostRepository.php` — `category` filter (bound subquery).
- `app/Services/Table/PostTableProvider.php` — `category` filter key.
- `bootstrap.php` — `bh_avatar_html()`, `bh_user_cell()`, `bh_thumb()` globals.
- `index.php`, `install.php` — version 1.2.55.

## Tests

- `tests/Unit/ComponentsTest.php` — avatar (image vs initials), user cell,
  thumbnail, status pill, escaping.
- `tests/Integration/DataTableTest.php` — adds a category-filter case.

Full suite: 234 passing, 0 failing.
