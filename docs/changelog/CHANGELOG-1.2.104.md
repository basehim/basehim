# Basehim 1.2.104

Data table: the bulk-action bar now floats instead of pushing the table down.
No database changes.

## The problem

Selecting a row made the bulk bar appear as a new row inside the toolbar card,
which shifted the whole table down — the content jumped under the pointer, and
on a long list the row you were aiming at moved. Every list screen (Posts,
Pages, Media, Comments, Users, Sites, and any app's Data Table) shared this.

## The change

The selection bar (`.bh-dt-bulk`) is now a floating pill, fixed to the bottom
of the viewport and centered, that slides up over the content when a selection
exists and slides away when it is cleared. The table never moves.

- `admin/assets/js/datatable.js` moves the bar to `<body>` on init, so
  `position: fixed` is relative to the viewport even if an ancestor is
  transformed (the mobile drawer uses transforms), and the table's layout can't
  be affected by it. **Escape** now clears the selection.
- `admin/assets/css/datatable.css`: the bar is a rounded pill with a shadow and
  a slide/fade transition (honoring `prefers-reduced-motion`). On phones it
  spans the width with side gutters, the count on its own line and the actions
  wrapping below, so nothing is clipped.
- `admin/views/partials/datatable.php`: the bar carries `role="region"`,
  `aria-label="Bulk actions"`, and an `aria-live` count.

The buttons, the per-selection logic, and the `bh:dt-bulk` event are unchanged,
so nothing an app declares needs to change.

## Tests

`tests/js/quick-edit.test.js` updated to find the bar on `<body>` (it asserts
the selection look, Clear, and the live count). 40/40 JS tests pass; the PHP
suite is unchanged.
