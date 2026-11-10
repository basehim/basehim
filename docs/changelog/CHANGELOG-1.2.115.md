# Basehim 1.2.115

A measured design audit of the data table (390 / 900 / 1366px; rest, stuck,
selection, drawer, Screen Options, empty), with each finding fixed at its root
cause, and the table moved onto core components. Core gains button/link
segments and field-height parity, and the core stylesheet can now be rebuilt
safely. No database changes.

## Core components

- **Fields are button height.** `.bh-input` / `.bh-select` were `py-2` plus the
  line height (37.3px) beside 36px `.bh-btn`s, so every toolbar and form row was
  off by 1.3px. One-line fields are now `h-9 py-0`; list boxes
  (`select[multiple]`, `[size]`) keep their height. Applies across the admin.
- **`.bh-seg` takes buttons and links.** `.bh-seg > button[aria-pressed]` and
  `.bh-seg > a[aria-current="page"]`, plus `.bh-seg--icon` for icon-only
  segments — 36px tall, level with buttons. Documented in `docs/ADMIN-UI.md`.
- **The core stylesheet rebuilds safely.** The committed `tailwind.min.css` held
  516 classes that themes, blocks and apps add at runtime, which no scanned
  file names — so `npm run build:css` silently dropped them and rebuilding any
  core component broke sites. They are now in a generated
  `tailwind.safelist.js` loaded by `tailwind.config.js`; a fresh build is a
  verified superset of the 1.2.114 bundle.

## The table's one-off controls → core components

| Was | Now |
| --- | --- |
| Bulk bar: rounded-full pill buttons, tinted-red Delete (`.bh-dt-bulkbtn--*`) | `.bh-btn--sm` primary / secondary / danger (solid red, as Delete is elsewhere in the admin); bar radius 16px = 8px button radius + 8px padding |
| Pager `.bh-dt-page` (28px, own colours) | `.bh-btn--sm` secondary, current page primary + `aria-current`, level with the 32px rows-per-page select |
| View switch (bordered group, 33.6px) | `.bh-seg.bh-seg--icon`, `aria-pressed` |
| Drawer sort direction (third segmented style) | `.bh-seg.bh-seg--icon`, `aria-pressed` |
| ⋮ row menu, bulk Clear ×, drawer ×, Quick Edit × | `.bh-btn--ghost.bh-btn--icon` |
| Term-filter **Clear** — the round 32px bulk-close class with text in it | `.bh-btn--ghost.bh-btn--sm`, disabled when nothing is selected |
| All / Trash (bordered group, solid-blue active) | `<nav class="bh-seg">` with `aria-current` |

## Phones (≤600px)

1. **Sticky controls touched the top nav** (reported). A phone rule set
   `.bh-dt > .bh-card--pad { padding: 0 0 .75rem !important }`. The controls are
   now an app bar: an even 10px top and bottom, edge to edge across the page
   gutter (`-mx-4 / px-4`, the same token as `<main class="p-4">`), the page's
   own background at rest (it was a stray white strip), frosted with a hairline
   when stuck.
2. **Toolbar heights** were 36 / 40 / 40 with non-square icon buttons (37×40):
   all 40×40 through one `--dt-ctl-h` token.
3. **Card tick boxes had a 17.6px hit area** (WCAG 2.5.8 asks 24px; 44px is the
   touch guideline). They are `<label>`s padded to ~42px, with an equal negative
   margin so nothing moves.
4. **The stuck footer was two rows and 80px tall**, with rounded corners against
   the screen edge. It is one 53px row ("1–8 of 25" + pager), square and edge to
   edge, and pads for the iPhone home indicator (`env(safe-area-inset-bottom)`).
5. **The search placeholder was cut mid-letter**; it now ends in an ellipsis.
6. The frameless phone list kept its card shadow, which drew faint edges down
   both sides; removed.

## Desktop

7. **The Views column sat top-aligned.** The column cell and the view switch
   shared `.bh-dt-views`, so the cell inherited `display:inline-flex` and a
   border. The column is `.bh-dt-col-views` now.
8. **Four toolbar control heights** (37.3 / 36 / 37 / 33.6px) → all 36px.
9. **Dates wrapped to three lines** ("November 10, / 2026 / 6:12 am"): the date
   is one line, the time beneath it. Rows dropped from ~106px to ~86px.
10. **Slugs wrapped over two or three lines**: clamped to one line, breaking
    anywhere so the slug never sets the column's width.
11. **The header tick box sat ~2px high** (the browser's checkbox margin).
12. **Empty results said "Showing 0–0 of 0 posts"** with a lone "1" between two
    dead arrows: they say "No posts", and a one-page list shows no pager.

## Tablet / wide grid

13. **The toolbar wrapped to two rows at ~900px** (124px). A container query on
    the toolbar's own width makes Screen Options icon-only first, so it stays one
    77px row.
14. **The grid squeezed columns** (titles a word or two per line, tags stacked):
    titles keep an 11rem measure, other cells stay on one line and scroll, and
    the right edge fades while more columns are hidden.
15. **Grid mode could never switch back off.** Its one-line cells widen the
    table, and detection measured that widened table. It now measures the normal
    layout (class off, read, set — in one frame).

## Files

- `admin/assets/css/src/tailwind.css` + compiled `tailwind.min.css` — field
  height parity, button/link segments.
- `tailwind.config.js`, new `tailwind.safelist.js` — reproducible core build.
- `admin/assets/css/src/datatable.css` + compiled `datatable.css`.
- `admin/assets/js/datatable.js` — core classes, footer count/pager, card tick
  labels, view/sort `aria-pressed`, grid detection, right-edge hint.
- `admin/views/partials/datatable.php` — view switch, bulk clear, Views column.
- `admin/views/posts/index.php` — All / Trash on `.bh-seg`.
- `docs/ADMIN-UI.md` — the new segment forms.

## Tests

- `tests/Unit/Release12115Test.php`; `tests/Unit/Release1299Test.php` updated
  (the ⋮ is a core ghost button).
