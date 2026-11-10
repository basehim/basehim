# Basehim 1.2.107

Data table UI/UX overhaul, focused on small screens. No database changes. The
data-table stylesheet is now compiled from a Tailwind source.

## Why

The desktop data table is strong, but on phones and tablets three things were
missing or scattered:

- **No sorting.** Sort lived only in the clickable table headers. Below 820px
  the table is replaced by cards, so the headers — and every sort control —
  disappeared. There was no way to sort a list on a phone.
- **Filters stacked down the page.** Status, category and tag each sat as a
  separate control in the toolbar and wrapped onto their own rows, pushing the
  list down before you could see it.
- **The floating bulk bar** used ad-hoc pill buttons and raw colours instead of
  the admin's button system, and loading dimmed the table under a spinner.

## The change

### One "Filter & sort" button + a drawer (phones/tablets)

Below 820px the inline filters, the view switch and the Screen Options cog
collapse into a single **Filter & sort** button (with an active-filter count).
It opens a right-hand drawer containing:

- a **Sort by** control built from the table's sortable columns, with an
  ascending/descending toggle — so phones can sort;
- **every filter** (status, category, tags, and any a table declares), moved in
  from the toolbar and shown expanded inline;
- **rows per page**.

Desktop is unchanged: the inline filters, header sorting and Screen Options work
exactly as before, and the drawer button never shows.

### Rebuilt bulk bar

The floating selection bar (1.2.104) is rebuilt on the button system: a bold
count chip on the accent, a hairline divider, labelled actions with leading
icons in a primary / secondary / danger hierarchy, and a dedicated Clear. On a
phone it becomes a full-width bar with the actions on their own row.

### Skeleton loading

Every load now paints a shimmer skeleton shaped like the current view — the same
columns and density in the table, the same card shape in List/Grid and on
phones, at the current rows-per-page — so the real rows land exactly where the
skeleton sat, with no dim-and-spin blink.

### Card polish

Compact cards get a hairline hover lift and a softer resting shadow, so a list
reads as a stack of tappable surfaces.

## Compiled from a Tailwind source

`admin/assets/css/datatable.css` is now compiled from
`admin/assets/css/src/datatable.css` (new/restyled sections use `@apply` with
the admin's accent/slate tokens, so the table tracks the palette). Build with
`npm run build:datatable`. The main `tailwind.min.css` bundle is untouched.

## Files

- `admin/assets/css/src/datatable.css` — Tailwind source (edit here).
- `admin/assets/css/datatable.css` — compiled output (shipped).
- `admin/assets/js/datatable.js` — drawer, mobile sort, skeleton, rebuilt bulk
  bar, three new icons.
- `admin/views/partials/datatable.php` — the Filter & sort button and the
  bulk bar's separator and Clear.
- `package.json` — `build:datatable` / `build` scripts.

## Scope

The Media Library is a separate component (not the shared data table) and is not
part of this change; it already has inline sort and a responsive grid.

## Tests

- `tests/Unit/Release12107Test.php` — the shipment: the button and bulk markup,
  the drawer/sort/skeleton builders, and the compiled stylesheet.
- `tests/js/quick-edit.test.js` — two new cases drive the drawer's Sort control
  (ascending/descending tokens) and the skeleton (rows in table view, cards in
  card view, replaced by the real data). The bulk-bar cases were updated for the
  new count chip and Clear button.
