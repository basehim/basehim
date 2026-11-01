# Basehim 1.2.95

2026-10-31 · Patch release · installs on 1.2.94

Fixes a 1.2.94 regression in the editor's taxonomy panels (BH-081), and two
smaller issues found while documenting content types. No database changes.

## BH-081: taxonomy panels empty; a save dropped their terms

`admin/assets/js/post-tags.js` drew only the first
`[data-bh-categories-mount]`, and `admin/views/posts/edit.php` loaded the
script only when the type uses tags. A content type with categories and an app
taxonomy but no tags (the example in docs/CONTENT-TYPES.md) showed both panels
empty, and a save posted no `term_ids[]`, so the item lost its terms.

- Every mount is drawn; the script is loaded whenever the type has any
  taxonomy.
- `post-fields.js`: an empty taxonomy says "No {label} yet." instead of
  always "No categories yet.".

Posts (categories and tags) and pages (no taxonomies) were not affected.

## Missing taxonomy rows

`TaxonomyRegistry::ensureStored()` stores an app taxonomy's row whatever the
cache says. The admin term screen and term creation call it when the row is
missing (removed by hand, a restored backup), so the screen opens instead of a
404 and terms can be added.

## Default theme

`single.php`: the custom fields list has its own grid (inline styles); 1.2.94
used Tailwind classes the theme's compiled CSS doesn't contain, so it
rendered as one cramped column.

## Files

`admin/assets/js/post-tags.js`, `admin/assets/js/post-fields.js`,
`admin/views/posts/edit.php`, `app/Services/TaxonomyRegistry.php`,
`app/Http/Controllers/Admin/TaxonomyController.php`,
`content/themes/default/templates/single.php`,
`tests/Integration/ContentTypesTest.php`, `index.php`, `CHANGELOG.md`, this
changelog and the release notes.

## Tests

The content-types integration test now checks the taxonomy script is loaded
and both panels are present, and that a missing taxonomy row is recreated by
its term screen. Checked in the browser that both panels draw and a save keeps
the terms.
