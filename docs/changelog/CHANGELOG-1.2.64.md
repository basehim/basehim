# Basehim 1.2.64

The data table takes on the Quick Edit dialog's look, cards first; on phones
and tablets, where only cards show, the Table / List / Grid switch is gone.

No database changes, no server changes. Installs on 1.2.63.

## What changed

1. **Cards.** The cards used on phones and tablets (and by the List and Grid
   views on wide screens) are restyled after the Quick Edit dialog:
   - .5rem corners (were .8rem).
   - The title at 15px, two lines at most, the slug under it, then the status.
   - The ⋮ button is a 2.25rem target with the dialog's hover, like its ×.
   - The other visible columns are label-over-value pairs in two columns
     (labels 12px semibold, as Quick Edit's), separated from the top by a
     rule. Before, they were one key/value line per column.
   - A selected card has a blue outline and tint; a selected table row has a
     tint. `markSelected()` sets `is-selected` on both twins, and
     `buildRow()`/`buildCard()` keep it when a row is redrawn, as after a
     Quick Edit save.

2. **Phones and tablets (≤ 820px) show only cards**, so the view switch is
   hidden, and so is Screen Options' Density row, which only changes table
   rows. The saved view is not changed and applies again on a wide screen.
   Before, Grid on a phone was a one-column grid with different spacing from
   List; now all views look the same there: two columns of cards on tablets
   and one on phones.

3. **The phone toolbar.** The search box and Screen Options (icon only, with
   an `aria-label`) share the first line, with the filters on the next. The
   Categories, Tags and Screen Options panels span the toolbar instead of
   running off a narrow screen, and scroll when they are tall. Menu items,
   pagination buttons and card checkboxes are larger touch targets. The search
   box uses 16px text so iOS does not zoom in.

4. **Corners**, as in Quick Edit (1.2.63): `.bh-dt` defines `--dt-r` (.5rem)
   and `--dt-r-in` (.375rem). The table, cards, panels, the row menu, the
   view switch and the bulk and page buttons use the first. Status badges
   (were pill-shaped), chips, menu items and list rows use the second.

## Files

Changed:
- `admin/assets/css/datatable.css` — radius variables, cards, the ≤ 820px and
  ≤ 600px rules.
- `admin/assets/js/datatable.js` — `is-selected` on rows and cards; the
  Density row's class.
- `admin/views/partials/datatable.php` — Screen Options' label in a span, so
  phones can show the icon alone.
- `docs/DATA-TABLE.md` ("Views, cards and phones"), `CHANGELOG.md`,
  `index.php` (1.2.64).
- `tests/js/quick-edit.test.js` — three tests.

New:
- `docs/changelog/CHANGELOG-1.2.64.md`, `RELEASE-NOTES-1.2.64.md`.

## Tests

`php tests/run.php` with a database (Dolt): 332 passed, 3 skipped, 1 failed.
The failure is the pre-existing `editor: a double click … creates one post`
(a Dolt full-text limitation; it fails the same way without this release).

New, running the real `datatable.js` in `tests/js/mini-dom.js`:
- A selected item has `is-selected` on its card and its table row, keeps it
  through a Quick Edit save, and loses it on Clear.
- Select all marks every card.
- Screen Options marks exactly the Density row.

The layout was checked in Chromium at 390px, 768px and 1280px, with the real
stylesheet and script on a sample Posts page.
