# Basehim 1.2.110

Sticky data-table chrome. No database changes. `datatable.css` is compiled from
`src/datatable.css`.

## What's new

On a long list, the controls and the column header now stick under the app's
top bar, and the pagination sticks to the bottom:

- **Sticky controls** (`.bh-dt-top`): the search/filter row sticks at the top
  bar's edge (`top: var(--dt-sticky-top)`, 4rem) and **shrinks** once stuck
  (`.is-stuck`, reduced padding + a soft shadow), via an `IntersectionObserver`
  on a 1px sentinel.
- **Sticky column header** (`thead th`): sticks just under the controls. Its
  offset is `calc(4rem + var(--dt-top-h))`, where `--dt-top-h` is the controls'
  live height, kept current by a `ResizeObserver` so it stays correct as they
  shrink or wrap.
- **Sticky, compact pagination** (`.bh-dt-foot`): sticks to the bottom and was
  tightened (smaller page buttons and padding) to take minimum space.
- **Page change scrolls to the first row**: the page buttons call
  `scrollToListTop()` before loading, which scrolls up to the top of the list
  (never nudges down when already near the top).

The table's wrapper changed from `overflow-x: auto` to `overflow: visible` so
the sticky header isn't trapped in a scroll container; wide tables stay in hand
through responsive columns and Screen Options.

## Toggles in Screen Options (on by default)

Both sticky behaviours can be turned off under **Screen Options → Sticky**:

- **Header & controls** → the `bh-dt--sticky` class.
- **Pagination** → the `bh-dt--sticky-foot` class.

They are **on by default** and apply at once (no Apply needed). The choice is a
view-chrome preference, kept per-browser in `localStorage` (`bh_dt_sticky:*`,
`bh_dt_stickyFoot:*`) rather than as a server-side pref, so there is no schema
change. The sticky CSS is gated behind these classes.

## Files

- `admin/assets/css/src/datatable.css` + compiled `datatable.css` — sticky
  rules (gated), `.is-stuck` shrink, compact pagination.
- `admin/assets/js/datatable.js` — `stickyChrome()` (sentinel + observers),
  `scrollToListTop()`, the `--dt-top-h` tracker, the `sticky`/`stickyFoot`
  state, `applySticky()`, and the two Screen Options switches (`stickyRow`).

## Tests

- `tests/Unit/Release12110Test.php`.
