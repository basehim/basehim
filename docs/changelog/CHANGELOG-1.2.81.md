# Basehim 1.2.81: loading placeholders, phone greeting buttons, dragging down on phones

Three adjustments asked for on 21 October 2026 after 1.2.80.

## Greeting buttons on phones

New post, Upload and Customize are the default size (36 px) on phones, each
as wide as its words plus 12 px a side, sharing what is left of the row.
Below 360 px Customize shows its icon only (its name stays for screen
readers). Desktop unchanged.

## Loading placeholders in the shape of the content

- A loading card shows its view's shape instead of three grey lines: stat
  tiles in the same tile grid (At a glance: five tiles in one row on desktop,
  2×2 with People across on phones), list and table rows, feed and status
  rows with their dots, chart bars, and the summary strip.
- Each card remembers the shape of its last data (`rememberShape()`: tile
  count, which tile is wide, row or bar count, summary), in memory and in
  `localStorage` (`bh.dashboard.shapes`; private browsing falls back to
  memory). The first time, the widget's view decides; the catalogue now
  carries `view` (`DashboardService::catalog()`).
- Refresh, Try again and a changed setting show the placeholder again (for at
  least 450 ms, so it doesn't flicker), then the numbers count up from zero
  and the charts grow in again. Automatic refreshes (`refresh` seconds) and
  returning to the tab keep the content and count on from the old numbers.
- Reduced motion: the placeholder doesn't shimmer, nothing animates.

## Dragging a card on phones

On phones the customize bar docks to the bottom of the screen, but the drag
code took its bottom edge as the top of the visible area, so nearly every
finger position counted as "near the top": the page scrolled up and never
down. The visible area is now worked out from where the bar actually is (and
the sticky top bar), and scrolling runs every frame while the pointer is near
either edge, faster closer to it, also when the finger is held still; the drop
position follows the scroll.

## Files

- `admin/assets/js/dashboard.js`, `admin/assets/css/src/tailwind.css`,
  `admin/assets/css/tailwind.min.css`
- `app/Services/Dashboard/DashboardService.php` (catalogue `view`)
- `tests/Integration/DashboardCardsTest.php`
- `docs/DASHBOARD-WIDGETS.md`, `CHANGELOG.md`, `index.php`

## Checked

370 passed, 4 skipped (Dolt). Placeholders and Refresh captured with the data
requests slowed; a phone drag (390×844, touch) scrolled down from 0 to
1,322 px held at the bottom edge, stayed put mid-screen, and scrolled up at
the top edge. Screenshots reviewed before release. Tile icon variants were
mocked up and the current icons kept.
