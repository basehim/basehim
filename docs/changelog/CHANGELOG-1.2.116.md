# Basehim 1.2.116

Selection without a wall of tick boxes, and a fix for the phone filter
button's count. CSS/JS only, no database changes.

## Selection

A tick box on every row made long lists look like a form. Selection now
follows the device:

**Desktop (a mouse — `(hover: hover) and (pointer: fine)`)**

- A row's tick shows while the row is hovered or has keyboard focus
  (`:focus-within`), so tabbing still reaches every box.
- Once anything is ticked the root gets `.has-selection` and every tick shows,
  so the selection is visible at a glance.
- The header **Select all** always shows.
- Ticks hide with `opacity`, never `display`, so nothing shifts.

**Touch (`(hover: none)`)**

- No ticks at rest; cards get the full width back.
- **Select** (a check-circle button where the toolbar's Select all sat), or a
  **long press** (450ms, cancelled by a 10px move) on an item, enters select
  mode (`.is-selecting`). The toolbar then shows Select all; the bulk bar shows
  with "Tap items to select" and ×, which is **Done** (it also clears).
- In select mode a tap anywhere on an item toggles it instead of opening it;
  the ⋮ hides until you're done. Escape also leaves.
- A long press doesn't open the browser's link menu
  (`-webkit-touch-callout: none` on cards).
- Ordering detail: the long press ticks its item *before* setting the flag
  that swallows the press's own click, and the flag expires 400ms after the
  touch ends — many browsers send no click after a long press, and it would
  otherwise eat the next real tap.

**The tick on the picture**

- Items with a picture (a featured image, an avatar) carry their tick on the
  picture (`.bh-dt-check--over`), so there is no checkbox column at all. Card
  ticks are round, like a photo picker's; a ticked picture fills with the
  accent and a white check. The table keeps square boxes.

The table's selection state, Select all, and the bulk bar are unchanged
underneath; this is presentation plus the select-mode state.

## Phone filter button

In 1.2.115 the icon-only "Filter & sort" button became a 40px square, but its
count badge stayed in the flow and pushed the icon 14px off-centre. The count
is now a corner badge (absolute, white ring), inside the button — the tool
group clips its overflow for the search-focus fold.

Also: on touch screens a tapped title no longer stays blue (`:hover` sticks
after a tap; the hover colour now needs a real pointer).

## Files

- `admin/assets/js/datatable.js` — `has-selection`, select mode (`setSelecting`,
  long press, tap-to-toggle), ticks on pictures, bulk bar hint/Done.
- `admin/assets/css/src/datatable.css` + compiled `datatable.css`.
- `admin/views/partials/datatable.php` — the Select button.

## Tests

- `tests/Unit/Release12116Test.php`.
