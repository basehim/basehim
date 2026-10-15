# Basehim 1.2.56

Data-table fixes and additions from review feedback.

1. **Templates showed posts.** The Templates screen is `type = 'template'`, but
   the table it loaded was `content.posts`. Added a `content.templates` table
   (`TemplateTableProvider`, a leaner column set) and mapped each screen to the
   right table key.
2. **Screen Options checkboxes did not match the table.** Hiding a column, then
   reopening the panel, showed it ticked again. Two causes: the panel fell back
   to a column's *default* visibility instead of the current set, and Apply
   raced the save (the reload read the pre-save preferences and revived the
   column). The panel now works on a draft of the current set, and Apply saves
   **before** it reloads.
3. **Per-row action menu.** The actions column is now a three-dots menu: Edit,
   **Publish** (when not published) or **Move to Draft** (when published), and
   **Move to Trash**. Each uses the feature's existing CSRF-protected bulk
   route for one row.
4. **Views is sortable.** Added `views_desc` / `views_asc` to
   `PostRepository`'s sort map and the provider's allowed sorts.
5. **Thumbnails load the generated thumbnail size**, not the original image
   (`featured_sizes['thumbnail']`, falling back to medium, then the original).
6. **Table / List / Grid views.** A view toggle in the toolbar switches layout;
   the choice is saved to preferences and restored. Grid and List reuse the
   card layout; Table is the default.
7. **Bulk actions match the selection.** The selection bar's buttons are built
   from the selected rows' statuses: Publish shows only when something selected
   is not already published, Unpublish only when something is published, and
   there is a Clear. The buttons were also tidied.

Also: data-table cells no longer inherit the admin's generic cell border (the
Views numbers were showing in boxes).

## Files

New:
- `app/Services/Table/TemplateTableProvider.php`

Changed:
- `admin/assets/js/datatable.js` — row action menu, context bulk buttons,
  Table/List/Grid views, draft-based Screen Options with save-before-reload,
  select-all/row-twin sync.
- `admin/assets/css/datatable.css` — view toggle, action menu, grid/list
  layouts, bulk polish, cell-border reset.
- `admin/views/partials/datatable.php` — view toggle, JS-built bulk bar,
  saved view on the root.
- `admin/views/posts/index.php` — map `page`/`template`/`post` to the right
  table key.
- `routes/tables.php` — thumbnail uses the thumbnail size; Views sortable;
  the `content.templates` table.
- `app/Repositories/PostRepository.php` — `views_desc`/`views_asc` sorts.
- `app/Services/Table/PostTableProvider.php` — views sort keys.
- `app/Core/Table/TableService.php` — rows carry their `status`.
- `app/Core/Table/TablePreferences.php` — a `view` mode (table/list/grid).
- `index.php`, `install.php` — version 1.2.56.

## Tests

`tests/Integration/DataTableTest.php` adds: the templates table lists
templates (not posts), `views_desc` is accepted, rows carry a status, and the
view mode round-trips with an invalid value rejected.

Full suite: 239 passing, 0 failing.
