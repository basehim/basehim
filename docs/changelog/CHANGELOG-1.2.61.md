# Basehim 1.2.61

Quick Edit on the Posts and Pages data tables: an inline form under a row for
the common properties, saved through the editor's own rules, with the row
re-rendered in place.

No database changes. Installs on 1.2.60.

## What changed

1. **A Quick Edit hook in the shared data table.** A table opts in with
   `'quick_edit' => SomeQuickEditor::class` (validated in
   `TableRegistry::register()`). `App\Core\Table\QuickEditor` has `form()` and
   `save()`; the table client renders the fields it is given and never knows
   what they mean. Two generic actions serve every such table, after the
   table's own capability and admin area are checked
   (`TableActions::authorizedTable()`):
   `content.quick_edit` (GET) and `content.quick_edit_save` (POST, CSRF, rate
   limited). The table payload gains `quick_edit: bool`.

2. **One row, rendered like a page.** `TableService::renderRow()` resolves one
   record through the same column list (core and app, capability-gated), the
   same `prepare()`/`render()` contract and the same escaping and sanitising as
   `build()`. The save response carries that row, so the client replaces the
   row and its card without reloading; search, filters, sort, page and the
   selection stay as they are.

3. **Posts and Pages** (`ContentQuickEdit`, `PostQuickEdit`, `PageQuickEdit`).
   Each type lists its fields; the base class shows, checks and saves them with
   the editor's own pieces:
   - record access: `PostEditorService::canEdit()` (admin area, own, or
     `edit_others_{type}s`); a record of another type is "not found";
   - status and schedule: `PostPublishing::resolve()` (1.2.60), then
     `PostService`'s invariants — no second set of status rules;
   - saving: `PostService::update()` (hooks, slug lock, caches as for any save);
   - new tag names: `PostEditorService::settleNewTags()`.

   | Field | Posts | Pages |
   |---|---|---|
   | Title, Slug | ✓ | ✓ |
   | Author (`edit_others_{type}s`) | ✓ | ✓ |
   | Status; Date and Time when Scheduled | ✓ | ✓ |
   | Allow comments | ✓ | only if the type supports `comments` (it does not by default) |
   | Categories, Tags | ✓ | — |
   | Parent, Order | — | ✓ (hierarchical types) |

4. **Permissions, enforced on the server.** The form lists only fields this
   user may change on this record; `save()` refuses any other field
   (`FIELD_NOT_ALLOWED`, 403) and validates every value again: statuses must be
   among those offered (Draft and Pending only, without `publish_{type}s`),
   authors must be active users who can edit the type, categories must exist,
   a parent cannot be the page itself or below it. Without publish rights,
   published, scheduled and private content is not editable here (403), as in
   the REST API.

5. **Status and scheduling preserved.** An edit that does not touch status
   never changes status or `published_at`. Choosing Scheduled needs a future
   date and time (site timezone); Published on a non-live post publishes now.
   Taking a live post off the site requires `confirm` (`CONFIRM_REQUIRED`, 409,
   otherwise) and the panel asks first.

6. **Slugs.** Normalised with `Helpers::slug()`; must be free across posts and
   pages (trash included) and not a reserved route; a clash, a reserved word or
   a slug with no usable characters is a field error rather than a silent
   `-2`. Empty means "from the title". A live post's address change is
   reported in the notices — core has no redirect table, so old links break.

7. **Terms.** `PostService::update()` replaces every term in one go, so Quick
   Edit sends the other taxonomy's terms along: changing categories keeps tags
   and the reverse.

8. **Concurrent edits.** The form carries a version — a hash of every field
   Quick Edit can change, terms and pending tags included. A save against an
   older version is refused (`EDIT_CONFLICT`, 409); only changed fields are
   sent and written.

9. **The panel** (`datatable.js`, `datatable.css`). Opened from the row's ⋮
   menu; one at a time; directly under the row in the table view, under the
   card in list/grid and on narrow screens, and it follows its row when the
   view or breakpoint changes. Labels tied to fields, errors under their fields
   with `aria-invalid` and `aria-describedby`, focus on the first field and back
   on the row when closed, Escape cancels. Save shows the shared button loader
   and cannot be submitted twice. Required fields and a schedule without a time
   are caught before a request. Cancel (or leaving the page) with changes asks
   first. A failed save keeps every value and says why. Two columns on wide
   screens, one on narrow ones.

10. **`PostService::update()` accepts `author_id`.** Who may change it is the
    caller's decision; nothing that existed before sends it.

## Files

New:
- `app/Core/Table/QuickEditor.php` — the interface and field contract.
- `app/Services/Table/ContentQuickEdit.php` — shared Posts/Pages logic.
- `app/Services/Table/PostQuickEdit.php`, `PageQuickEdit.php` — field lists.
- `tests/Integration/QuickEditTest.php`, `tests/Unit/QuickEditJsTest.php`,
  `tests/js/quick-edit.test.js`, `tests/js/mini-dom.js`.
- `docs/changelog/CHANGELOG-1.2.61.md`, `RELEASE-NOTES-1.2.61.md`.

Changed:
- `app/Core/Table/TableDefinition.php`, `TableRegistry.php` — `quick_edit`.
- `app/Core/Table/TableService.php` — `renderRow()`; `quick_edit` in payloads.
- `app/Http/Controllers/Ajax/TableActions.php` — `quickEditForm()`, `quickEditSave()`.
- `routes/ajax.php` — the two actions; `routes/tables.php` — enabled on Posts and Pages.
- `app/Services/PostEditorService.php` — `canEditOthers()`, `hasCategories()`,
  `typeDefinition()`, `supports()`, `isHierarchical()`.
- `app/Services/PostService.php` — `author_id` in `update()`.
- `admin/assets/js/datatable.js`, `admin/assets/css/datatable.css` — the panel.
- `docs/DATA-TABLE.md`, `CHANGELOG.md`, `index.php` (1.2.61).

## Tests

`php tests/run.php` with a database (Dolt), on the published 1.2.60 with this
patch applied: 269 passed, 2 skipped, 1 failed — the pre-existing
`editor: a double click … creates one post` (Dolt full-text limitation; fails
identically on 1.2.60). With the 1.2.60 scheduling tests also present (they
are not in the published tree): 306 passed, 3 skipped, the same 1 failed.

New: 11 integration tests through the real gateway (forms for posts and pages,
title/slug/author/category/tag/parent/order saves, draft/live/scheduled
preservation, scheduling and confirmation, contributor and author limits,
unknown fields, malformed input, version conflicts, CSRF, table state after a
save) and 17 UI tests running the real `datatable.js` in a small DOM (open,
values, labels and focus, only-changed fields, in-place row swap with no
reload, slug refresh, field errors without losing input, local validation,
network failure and retry, double submit, Cancel/Escape/discard, one panel at a
time, unpublish confirmation, schedule fields, view change).
