# Basehim 1.2.112

Visual fix for the sticky data-table chrome. No database changes.

## Controls + header as one container

When stuck, the shadow sat *under the controls*, between them and the column
header, so the header looked like a detached second strip. The controls and the
header now read as one sticky container:

- the controls' stuck shadow is removed (only the hairline divider into the
  header remains);
- the shadow now sits under the **column header** — the bottom edge of the
  combined unit — lifting controls + header together off the scrolling rows.

CSS only, in `admin/assets/css/src/datatable.css` (the `.bh-dt-top.is-stuck`
shadow moved to `.bh-dt-top.is-stuck ~ .bh-dt-wrap … thead th`).
