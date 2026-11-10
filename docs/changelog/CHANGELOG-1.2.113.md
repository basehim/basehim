# Basehim 1.2.113

Two sticky data-table polish fixes from install feedback. CSS/JS only, no
database changes.

## 1. Phones: sticky chrome poked through the side-nav backdrop

The admin's off-canvas menu dims the page behind it with `#bh-backdrop`
(`z-index: 15`). The data table's sticky controls, column header and
pagination sit above that (`z-30` / `z-20` / `z-25`) so they are not covered by
the table rows scrolling under them — but that also left them **above the
backdrop**, poking through bright and sharp while everything else dimmed.

The backdrop is not an ancestor of the data table, so there is no containing
element to scope against. The layout now flags the open menu on the root
element — `openMobile()` / `closeMobile()` in `admin/views/layouts/app.php`
toggle `document.documentElement.classList` `bh-nav-open` alongside the existing
`#bh-backdrop.is-open` — and the data-table CSS drops the sticky chrome below
the backdrop only while that flag is set:

```css
.bh-nav-open .bh-dt--sticky .bh-dt-top,
.bh-nav-open .bh-dt--sticky .bh-dt-table thead th,
.bh-nav-open .bh-dt--sticky-foot .bh-dt-foot { @apply z-0; }
```

Three classes outrank the two-class base sticky rules, so no `!important` is
needed and normal stacking is untouched when the menu is closed (verified:
`z-index` returns to 30 / 20 on close). With the menu open the whole content
column, sticky chrome included, dims as one.

## 2. Hairline gap between the controls and the column header

`measure()` fed `--dt-top-h` (the distance the header sticks below the
controls) from `topEl.offsetHeight`, which is **rounded to an integer**. When
the controls' real height is fractional — e.g. 54.25px on a 2× display — the
header stuck a sub-pixel below the controls' bottom edge, showing a hairline of
page behind it. It now measures `getBoundingClientRect().height` (fractional),
so the header sits exactly flush: measured gap `0.000px` both at rest and while
stuck.

## Files

- `admin/views/layouts/app.php` — `openMobile()` / `closeMobile()` toggle
  `bh-nav-open` on the root element.
- `admin/assets/js/datatable.js` — `measure()` uses fractional
  `getBoundingClientRect().height` for `--dt-top-h`.
- `admin/assets/css/src/datatable.css` + compiled `datatable.css` — `.bh-nav-open`
  override dropping the sticky chrome below the backdrop, authored with `@apply`.

## Tests

- `tests/Unit/Release12113Test.php`.
