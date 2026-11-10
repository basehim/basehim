# Basehim 1.2.111

Fixes for the sticky data-table chrome shipped in 1.2.110, from install
feedback. No database changes.

## 1. Column header hidden / header mid-table

The admin layout auto-wraps every `<table>` in a `.bh-table-scroll`
(`overflow-x: auto`) container for horizontal scrolling. An overflow container
traps `position: sticky`, so the data table's sticky header was caught by it —
hidden, or stuck mid-table after a re-render.

`wrapTables()` in `admin/views/layouts/app.php` now **skips the data table**
(`.bh-dt-table` / inside `.bh-dt-wrap`): it has its own responsive design (cards
on small screens) and manages its own header, so it must not be wrapped.

## 2. Screen Options not scrollable

The popover had no height limit, so its lower items (and **Apply**) could sit
off-screen. It now has `overflow-y: auto` and a **measured** max-height
(`--dt-panel-max`, the space below its top, set in `openPanel()`), with a CSS
`calc()` fallback. Long column lists also scroll within the panel
(`.bh-dt-collist`).

## 3. Popover mispositioned with sticky off

The popover anchors to the controls region (`.bh-dt-top`). That region was only
positioned while sticky was on, so with sticky off it fell back to the card and
opened at the bottom. `.bh-dt-top` is now **always** `position: relative`;
sticky is layered on top when enabled.

## 4. Jumpiness while scrolling

The offsets are no longer assumed. The script **measures** the app's sticky top
bar height (from the real element) for `--dt-sticky-top`, and measures the
controls' **shrunk** height once (transitions suppressed) for `--dt-top-h`, so
the header sticks a constant distance down and does not jump as the controls
shrink. The stuck state is toggled from a `requestAnimationFrame`-throttled
scroll handler (no `IntersectionObserver`, no hardcoded pixel offset).

## 5 / 6. Scroll to the first row

Changing the page already scrolled up; now **changing rows-per-page** does too
(`applyPanel()` and the footer selector call `scrollToListTop()`), which uses
the measured top-bar offset.

## 7. Phones: flat controls under the bar

The sticky controls drop their rounded top at ≤820px and, because the top-bar
offset is measured, sit flush under the bar instead of partly behind it.

## Files

- `admin/views/layouts/app.php` — `wrapTables()` skips the data table.
- `admin/assets/js/datatable.js` — measured offsets (`appBarHeight`, shrunk-height
  measurement), rAF scroll handler, `--dt-panel-max`, `scrollToListTop()` on
  rows-per-page.
- `admin/assets/css/src/datatable.css` + compiled `datatable.css` — always-
  relative controls, panel max-height + scroll, no mobile radius, authored with
  `@apply` for the static utilities.

## Tests

- `tests/Unit/Release12111Test.php`; `tests/Unit/Release12110Test.php` updated.
