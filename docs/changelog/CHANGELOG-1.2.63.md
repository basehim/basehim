# Basehim 1.2.63

Quick Edit opens in a modal dialog; the keyboard no longer closes on phones;
View/Preview first in the row menu; Quick Edit's corners match the admin menu.

No database changes, no server changes. Installs on 1.2.62.

## The phone keyboard closing at once

Tapping a Quick Edit field on a phone opened the keyboard and closed it again.
Cause: the inline form listened for `resize` so it could follow its row
between the table and the card layouts (`quickEdit.rehost()`), and moving it
meant removing it from the page and inserting it again. A phone's keyboard
opening resizes the window, so the first tap triggered that move; removing
the focused field from the page blurs it, and a blurred field closes the
keyboard.

The dialog never moves while it is open, and the `resize` handler,
`rehost()` and the inline hosting are gone, so nothing can take a focused
field out of the page. Two related touch details: no field is focused when
the dialog opens on a coarse pointer (the heading is, so the keyboard does not
cover the form before it is seen), and fields use 16px text on phones so iOS
does not zoom in when one is tapped.

## What changed

1. **Quick Edit is a modal `<dialog>`** (`showModal()`, the top layer, a
   blurred backdrop — the same treatment as the admin's confirm and item
   dialogs). One per table, reused. A header with "Quick Edit", the item's title
   and ×; a scrolling body; Cancel and Save in a footer that stays in view.
   Escape (the dialog's `cancel` event), × , Cancel and a click on the backdrop
   all go through `close()`, which asks before discarding changes. Focus goes
   back to the row's ⋮ button. On phones (≤ 600px) it fills the screen
   (`100dvh`, safe-area padding for the footer). The form's fields, validation,
   saving, in-place row update and twelve-column compact layout are unchanged.

2. **View / Preview first** in the ⋮ menu, then Edit, Quick Edit and the
   status actions.

3. **Corners.** The admin sidebar's items use `rounded-lg` (.5rem). Inside the
   dialog everything follows it: the dialog, fields, buttons, the category list,
   tag chips (were fully round), the tag suggestions (10px), the Publish switch
   (6px / 4px), messages. Nested parts (a segment of the switch, a suggestion
   row, a chip's ×) use .375rem. Scoped to `.bh-qe`, so the block editor's
   sidebar keeps its own look.

## Files

Changed:
- `admin/assets/js/datatable.js` — the dialog; menu order; no `resize`
  re-hosting; touch focus.
- `admin/assets/css/datatable.css` — dialog styles, radii, phone layout.
- `docs/DATA-TABLE.md`, `CHANGELOG.md`, `index.php` (1.2.63).
- Tests: `tests/js/mini-dom.js` (`<dialog>`; focus drops to `<body>` when the
  focused element is removed, as in browsers — the original bug reproduces in
  it), `tests/js/quick-edit.test.js`.

New:
- `docs/changelog/CHANGELOG-1.2.63.md`, `RELEASE-NOTES-1.2.63.md`.

## Tests

`php tests/run.php` with a database (Dolt), on the published 1.2.62 with this
patch applied: 329 passed, 3 skipped, 1 failed — the pre-existing
`editor: a double click … creates one post` (Dolt full-text limitation; fails
identically before this release).

The Quick Edit interface tests (25, real `datatable.js` and `post-fields.js`)
now cover: opening as a modal dialog named after the item; Cancel before Save;
Escape (`cancel`), ×, backdrop and Cancel closing, with the discard question;
one dialog reused; **a focused field staying connected and focused through a
window resize** (the harness reproduces the bug: moving a focused form the way 1.2.62 did drops focus to `<body>`);
no field focused on a coarse pointer; View/Preview first in the menu. The
published tree has none of the 1.2.60–1.2.62 tests, so they are included.
