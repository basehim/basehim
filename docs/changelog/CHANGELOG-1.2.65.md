# Basehim 1.2.65

The post and page lists on phones and tablets follow the requested design:
one line per post with picture, title and category, author, views and date,
then ⋮; no frame around the list on phones; a compact pager. Columns now say
where they go on that card, so other tables and apps can use the same layout.
On desktop the table and the List and Grid cards are as in 1.2.64; List and
Grid gain a Select all bar (7).

No database changes. Installs on 1.2.64.

## What changed

1. **A compact card at 820px and below.** Where cards are the only view, a
   card is one line: checkbox · picture · title, with the category and any
   status that needs attention under it · author (avatar and name) · views
   and date with eye and calendar icons · ⋮. The slug and tags stay in the
   table. Before, a card listed every column as a label and a value under the
   title. Wider screens keep that card for the List and Grid views:
   `buildCard()` picks `buildCompactCard()` or `buildFullCard()` (the 1.2.64
   card) from a `(max-width: 820px)` media query, and redraws only the cards,
   keeping the selection, when the window crosses it. The compact styles are
   all scoped to `.bh-dt-card--compact`.

2. **Columns place themselves.** A column definition takes `card`
   (`media`, `title`, `sub`, `under`, `side`, `meta`, `row`, `hide`) and, for
   `meta`, `card_icon`. `Column::CARD_SLOTS` lists them. `TableRegistry` keeps
   only known slots and icon names matching `[a-z0-9-]{1,40}`, so a bad value
   is dropped, not fatal. `toClientArray()` sends them. Posts, Pages,
   Templates and Comments are placed in `routes/tables.php`. A table that
   places nothing gets the picture, title and status by id and the rest as
   label/value pairs, as before, so app tables keep working.

3. **Statuses that need attention.** The status badges mark the usual state
   with `data-card-quiet` (Published for posts and pages, Approved for
   comments), and cards leave those off. Draft, Pending, Scheduled, Private,
   Spam and so on still show. Empty cells (nothing, or a lone `—`) are left off
   cards. A picture or avatar counts as content even though it has no text.

4. **The compact card fits its width.** A CSS container query sizes the
   layout to the card itself:
   - Under 345px of content the author shows as a picture only.
   - Under 300px (the smallest phones) the author and the values move below
     the title.
   - From 520px (a phone on its side) nothing wraps.

   On a 412px phone everything fits on one line, with the author's name.

5. **Phones (≤ 600px).**
   - The list loses its outer frame: the toolbar, each card and the pager
     stand on the page by themselves.
   - The pager is its own small card with three page numbers.
   - Rows per page is left to Screen Options.
   - All / Trash and New Post sit at either end of their line
     (`.bh-list-actions`).

6. **The count names the items:** "Showing 1–20 of 50 posts" (the table's
   `label`), on every screen size, in the first page from the server and
   after every reload.

7. **Select all for cards.** Cards have no table header, so the List and
   Grid views, phones and tablets had no way to select every row. A **Select
   all** bar now sits above the cards wherever they show, hidden when there
   are no rows. It and the table header's checkbox show the same state,
   including "some selected" (`syncSelectAll()` now counts the page's rows
   instead of the table's checkboxes). On phones the bulk bar puts the count
   on its own line and the actions on the next.

8. **Fix: the ⋮ menu did not toggle.** Tapping the same ⋮ again reopened the
   menu instead of closing it; only a click outside closed it. Now the same ⋮
   closes its menu, another ⋮ moves it, and a click outside still closes it.
   The button carries `aria-expanded`.

9. **Icons:** `calendar` and `chat` are added to the table's icon set.

## Files

Changed:
- `app/Core/Table/Column.php`, `app/Core/Table/TableRegistry.php`: `card`
  and `card_icon`.
- `routes/tables.php`: card places for the core tables; `data-card-quiet`.
- `admin/assets/js/datatable.js`: `buildCompactCard()` beside the
  1.2.64 `buildFullCard()`, `cardSlot()`, `blankCell()`, `countText()`, the
  phone pager, icons.
- `admin/assets/css/datatable.css`: the compact card and its container
  queries; the frameless phone list.
- `admin/views/partials/datatable.php`: the count text.
- `admin/views/posts/index.php`: `.bh-list-actions`.
- `docs/DATA-TABLE.md` ("Views, cards and phones"; the column reference),
  `CHANGELOG.md`, `index.php` (1.2.65).
- Tests: `tests/Unit/TableRegistryTest.php`, `tests/js/quick-edit.test.js`.

New:
- `docs/changelog/CHANGELOG-1.2.65.md`, `RELEASE-NOTES-1.2.65.md`.

## Tests

`php tests/run.php` with a database (Dolt): 341 passed, 3 skipped, 1 failed.
The failure is the pre-existing `editor: a double click … creates one post`
(a Dolt full-text limitation; it fails the same way without this release).

New:
- The registry keeps `card` and `card_icon`, drops an unknown slot or an
  unsafe icon name, and lets app columns place themselves.
- With the real `datatable.js`:
  - Each column lands in its place, including a picture with no text.
  - The tags stay off the card and an unplaced column goes below.
  - Icons, with the label still read out.
  - Empty cells left off; Published marked quiet; Draft shown.
  - A table without `card` settings falls back by id.
  - A Quick Edit save redraws the card in the same layout.
  - Wide screens keep the full card (title and status on top, every column
    below, tags included).
  - Crossing 820px swaps the layout and keeps the selection.
  - The cards' Select all selects every card and fills the bulk bar, stays in
    step with the table header (all, some, none), and hides when the page is
    empty.
  - The ⋮ button toggles its menu, another ⋮ takes it over, and a click
    outside closes it.
  - The count text with and without a label.
  - Three page numbers on phones.

Checked in Chromium with the real stylesheet and script at 360, 390 and
412px, 768px (two columns) and 1280px (Table, List and Grid as in 1.2.64).
