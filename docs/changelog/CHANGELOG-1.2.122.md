# Basehim 1.2.122

The posts' and pages' trash now use the Data Table, like their lists. No
database changes.

## Trash on the Data Table

`/admin/posts?view=trash` and `/admin/pages?view=trash` were the old
server-rendered table: a bulk-actions dropdown, an Apply button, browser
confirm boxes and Prev/Next links. They are now the same component as the
lists, with everything that comes with it:

- **Search and sort.** Search the trash, filter by status, sort by title
  or by **Deleted**. Recently deleted comes first by default.
- **Columns:** image, title (plain text: a trashed item has no editor until
  it's restored), author, **Restores as** (the status it goes back to) and
  **Deleted** (when it was moved there, in the site's time zone).
- **Table, list and grid views**, Screen Options, rows per page, and phone cards.
  Each trash keeps its own preferences.
- **Row actions:** **Restore** and **Delete permanently**. On desktop they show
  as links when you hover a row, and in the ⋮ menu on cards and phones.
- **Selection:** the bulk bar offers **Restore** (primary) and **Delete
  permanently** (danger), using the same selection as the lists (hover ticks
  on desktop, Select mode on phones).
- **Confirmations** use the admin's danger dialog, with the focus starting on Cancel:
  "Permanently delete 2 posts? This can't be undone."
- **Empty state:** "Trash is empty", with a trash icon. Searches still show "No matches".
- **Messages** read plainly: "2 posts restored.", "1 page deleted
  permanently.", "Trash emptied: 3 posts deleted permanently." Empty trash
  now returns you to the trash instead of the list.

Each row offers only what you may do with it. Restore needs your own item or
`edit_others_*`, and Delete permanently needs your own item or
`delete_others_*`. An author sees the actions on their own items and none on
other people's.

## Fixes found on the way

- **Empty trash needed only `delete_*`.** An author could permanently delete
  everyone's trashed posts. It now needs `delete_others_*`, and the button
  shows only to people who have it.
- **Restore and Delete permanently could reach live posts.** A hand-made bulk
  request could permanently delete a post that wasn't in the trash. Both
  now skip anything that isn't trashed.
- The Empty trash confirmation was a native `confirm()` box. It now goes through
  the admin's dialog, and its wording is plain ("Delete all 3 posts in the trash
  permanently? This cannot be undone.").

## Engine

- Tables can now set their own empty state:
  `'empty' => ['icon' => 'trash'|'inbox', 'title' => …, 'text' => …]`. It's
  validated in `TableRegistry`, sent in the payload, and drawn the same way by
  the partial and the script. The other tables are unchanged.
- `PostRepository` sorts `deleted_desc` and `deleted_asc` (whitelisted).
- New `PostTrashTableProvider` and `PageTrashTableProvider`. They force the
  trashed filter (a request can't widen it) and set `row_flags` per user.
- `icons.js` gains `arrow-uturn-left`.
- `docs/DATA-TABLE.md`: "Empty state (1.2.122)".

`?view=classic` still gives the old list for the main list.

## Files

- `index.php`
- `routes/tables.php`
- `app/Services/Table/PostTrashTableProvider.php`, `PageTrashTableProvider.php` (new)
- `app/Repositories/PostRepository.php`
- `app/Http/Controllers/Admin/PostController.php`
- `app/Core/Table/TableDefinition.php`, `TableRegistry.php`, `TableService.php`
- `admin/views/posts/index.php`
- `admin/views/partials/datatable.php`
- `admin/assets/js/datatable.js`, `admin/assets/js/icons.js`
- `docs/DATA-TABLE.md`

## Tests

- `tests/Integration/TrashTableTest.php` (4 tests) covers:
  - Only trashed items of the right type are listed, newest-deleted first, and a filter can't leave the trash.
  - The per-user row flags.
  - The page renders the table, and Empty trash is hidden from authors.
  - Subscribers get 403.
  - Restore and Delete permanently work, and never touch a live post or someone else's.
  - Empty trash is refused for authors and only empties its own type.
- `tests/Unit/Release12122Test.php` (4 tests).
- Full suite with the database: 782 passed, 2 failed. The 2 are the known
  BH-047 stylesheet checks (see 1.2.90).
- In a browser:
  - Desktop (1366):
    - columns, hover links and the bulk bar;
    - restoring a row and two in bulk;
    - Delete permanently with the danger dialog;
    - sorting by Deleted, search and no-match;
    - the pages' trash, Empty trash, and the "Trash is empty" state.
  - As an author: actions only on their own item, no Empty trash.
  - Phone (iPhone 13): cards with the ⋮ menu (Restore, Delete permanently), no sideways scroll.
  - No script errors.
