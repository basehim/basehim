# Basehim 1.2.114

A new wide-table mode for the data table, and a corrected fix for the mobile
side-nav backdrop. CSS/JS only, no database changes.

## 1. Wide-table mode — a self-contained grid

When a table has more columns than fit, the wrap now becomes a self-contained
scroll box: the header row freezes to its top and the leading identity columns
freeze to its left, so you scroll both ways inside the box like a spreadsheet
grid. The rest of the list below it stays put.

- **Activation is measured, not assumed.** After each render `measureGrid()`
  compares `r.table.scrollWidth` with `r.wrap.clientWidth` and toggles
  `.bh-dt--xscroll` only when the table actually overflows. Desktop/tablet
  table only — phones already show cards.
- **The box is bounded to the viewport.** `--dt-grid-max` is set from the
  measured app-bar height, the controls' shrunk height and the footer height,
  so the controls + grid + pagination sit within one screen.
- **The header row freezes to the box top** (`position: sticky; top: 0`),
  overriding the page-sticky offset while in this mode, whatever the
  Screen Options sticky toggle.
- **The leading identity columns freeze to the left.** `frozenCount()` freezes
  the select cell plus the data columns up to and including the primary
  (`card: 'title'`) column — so a thumbnail **and** the title stay pinned while
  the rest scroll — capped near the front so an unusual column order can't
  freeze half the table. Each frozen cell gets a `.bh-dt-frz--k` class and a
  **measured cumulative** left offset (`--dt-frzL-k`), so varying column widths
  line up exactly.
- **Freeze boundary.** The last frozen cell carries a hairline border and, once
  scrolled, a soft right shadow so it reads as "the other columns continue
  under here" (`.is-xscrolled`, toggled from the wrap's scroll).

Stacking inside the box: regular header `z-3`, frozen body cells `z-2`, frozen
header corner `z-4`, body rows `z-0` — all below the admin shell (see §2).

## 2. Mobile side-nav backdrop — corrected

1.2.113 tried to stop the sticky controls/header/pagination poking through the
off-canvas nav's backdrop by flagging `bh-nav-open` and dropping them to `z-0`
while the menu was open. That was wrong: `z-0` put the sticky header **at the
same level as its own table rows**, which come later in the DOM, so it painted
*under* the rows — looking hidden — and the class toggle caused a repaint jerk.

The real cause was that the sticky chrome used `z-20`–`z-30`, **above** the
admin shell (backdrop `z-15`, sidebar `z-20`). Page content should never
out-rank the navigation. The chrome now uses low single digits — controls
`z-6`, pagination `z-5`, header `z-4` — still above the table rows (`z-0`) but
below the shell. The backdrop dims the whole list, sticky chrome included, with
**no `bh-nav-open` flag, no z-toggle and no jerk**; `openMobile()` /
`closeMobile()` are back to their pre-1.2.113 form. (The 1.2.113 flush-header
gap fix is unaffected.)

## Files

- `admin/assets/js/datatable.js` — wide-table detection (`measureGrid`),
  `frozenCount()`, frozen-cell classes + measured cumulative offsets, wrap
  scroll shadow; `remeasureSticky()` after each render.
- `admin/assets/css/src/datatable.css` + compiled `datatable.css` —
  `.bh-dt--xscroll` grid mode; sticky chrome z-indexes lowered below the shell;
  the `bh-nav-open` override removed.
- `admin/views/layouts/app.php` — `bh-nav-open` class toggle removed.

## Tests

- `tests/Unit/Release12114Test.php`; `tests/Unit/Release12113Test.php` trimmed
  to the gap fix it still owns.
