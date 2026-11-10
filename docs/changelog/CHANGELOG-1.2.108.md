# Basehim 1.2.108

Data table mobile refinements on top of 1.2.107, from install feedback. No
database changes. `datatable.css` is compiled from `src/datatable.css`
(`npm run build:datatable`).

## Single-row toolbar (phones/tablets)

The toolbar is now one compact row — **Select all · Search · Filter & sort ·
Screen Options** — instead of the search and filters stacking down the page.

- **Select all** moved into the toolbar as a compact checkbox; the cards' own
  "Select all" header is dropped on small screens. It stays in sync with the
  table header and the bulk bar.
- **Filter & sort** and **Screen Options** become icon-only buttons.
- **Search expands on focus**: focusing it adds `is-searching` to the toolbar,
  which folds the other controls away (animated max-width/opacity) so the field
  fills the row; blurring an empty field restores them.

## Screen Options reachable again

The Screen Options cog was hidden on small screens in 1.2.107. It is back — so
**column choices** (which also change what each card shows) and **rows per
page** are available on phones, from the same panel as on desktop. Rows-per-page
was therefore removed from the Filter & sort drawer to avoid two homes for one
setting; the drawer is now Sort + Filters.

## Smoother drawer

A CSS transition cannot run from `display:none`, so the drawer used to pop open.
It now mounts first and adds `is-open` on the next frame so the panel slides in,
and unmounts after the slide-out — a clean open/close.

## Skeleton: a minimum visible time

The skeleton now stays up for at least 300 ms on every load (sort, filter,
search, page, rows-per-page), so a fast response reads as a load rather than a
flicker, and the rows still land where the skeleton sat.

## Sort "Default order" fix

The server always reports an *effective* sort, so choosing "Default order"
(which sends no sort) made the control snap back to the default column. The Sort
control now follows an explicit choice (`sortChoice`) rather than the server
echo, so Default stays Default and the direction toggles disable correctly.

## Type

Data-table UI text is standardised to 14px (0.875rem) for primary and 13px
(0.8125rem) for secondary. The one 16px rule — the search input — is deliberate,
so iOS doesn't zoom when it's tapped.

## Files

- `admin/views/partials/datatable.php` — toolbar Select all, icon-only Filter &
  sort.
- `admin/assets/js/datatable.js` — toolbar Select all wiring, search focus
  state, smooth drawer open/close, skeleton minimum time, `sortChoice`, drawer
  rows-per-page removed, three sort/skeleton helpers.
- `admin/assets/css/src/datatable.css` + compiled `datatable.css` — single-row
  toolbar, expand-on-focus, compact Select all, restored Screen Options, font
  sizes.

## Tests

- `tests/Unit/Release12108Test.php`.
- `tests/js/quick-edit.test.js` — new cases for the "Default order" fix and the
  skeleton's minimum-time timer; existing drawer/skeleton cases updated for the
  two-frame open and the timer.
