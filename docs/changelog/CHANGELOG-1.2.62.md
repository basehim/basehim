# Basehim 1.2.62

Quick Edit, improved: Categories, Tags and Publish are now the block editor's
own components, shared by both screens; a live post's publication date can be
changed; View and Preview in the row menu; a compact Quick Edit form.

No database changes. Installs on 1.2.61.

## What changed

1. **Shared post fields** — `admin/assets/js/post-fields.js` (new) holds the
   three components both screens draw:
   - `BasehimPostFields.categories(host, cfg)` — the category checklist, with a
     filter box once the list is long.
   - `BasehimPostFields.tags(host, cfg)` — chips, typeahead
     (`terms.tag_suggest`), Most used / Recently used (`terms.tag_overview`,
     now fetched once per page), new names kept until the post is published.
     `immediate: true` stores add/remove at once on a saved post (the editor,
     as since 1.2.47); `immediate: false` leaves changes for the form's Save
     (Quick Edit).
   - `BasehimPostFields.publish(host, cfg, opts)` — the Publish control,
     generalised from 1.2.60's `post-publish.js`.

   Each returns a small API (`value()`/`values()`, `onChange()`, `set()`,
   `validate()`/`check()`, `label()`, `confirmMessage()` …). A `skin` option
   gives the editor its `bhe-*` classes and the lists the admin's `bh-*`, so
   neither stylesheet restyles the other; the shared look lives in `ui.css`.

   The server builds their settings in one place for both screens:
   `PostPublishing::panel()`, `PostEditorService::categoriesField()` and
   `tagsField()`.

2. **The editor uses them.** `posts/edit.php` puts the settings on mount points
   (`data-bh-publish-mount`, `data-bh-categories-mount`, `data-bh-tags-mount`);
   `post-publish.js` and `post-tags.js` are now short glue files. The form still
   posts `status`, `publish_*`, `term_ids[]` and `new_tags[]` — nothing that
   reads the form changes. The editor no longer loads every tag on the site
   (`PostController::renderForm()`), only the post's own.

3. **Quick Edit uses them.** Field types `categories`, `tags` and `publish`
   mount the components (compact). Status offers Draft, Pending review,
   Published and Private (and Scheduled for a post that is); choosing Published
   shows the Publish control, and Save reads Publish, Schedule or Publish now
   when that is what will happen. The server reads the control's values with
   `PostPublishing::resolve()`, like the editor's form; errors on them are
   shown on the control.

4. **A live post's date can be changed** (editor and Quick Edit). For a
   published post the control offers **Keep date** / **Change date**
   (`publish_mode` `keep` / `date`, new in `PostPublishing::resolve()`). A date
   that has passed re-dates the post and it stays live; a future date
   schedules it — the editor asks first, and Quick Edit's server requires
   `confirm` (`CONFIRM_REQUIRED`). Publish rights are still required; 1.2.60's
   `schedule` on a live post keeps working.

5. **View / Preview** in the ⋮ menu. A table may declare `row_links`, a
   callable run once per page (like `prepare()`) returning each row's address
   and whether it is live; rows then carry `link` (also in Quick Edit's saved
   row). Posts and Pages use it: under category permalinks the page's first
   categories are loaded in one query instead of
   `Helpers::lookupPrimaryCategory()` per row. Live rows get **View**, others
   **Preview**, opening in a new tab; a preview is only served to people who may
   edit the post. Templates have no address.

6. **Compact Quick Edit.** Fields carry a `span` (1–12); on wide screens they
   sit in a twelve-column grid (title 6 · slug 3 · author 3; status 3 ·
   publish 9; categories 5 · tags 7; …), two columns on tablets, one on phones.
   Smaller labels, inputs and spacing; Save and Cancel at the bottom right.

7. **Fix found by the tests:** Quick Edit's client-side required-field check
   looked up every sent key as an input and threw on the Publish control's
   values.

## Files

New:
- `admin/assets/js/post-fields.js` — the shared components.
- `tests/js/post-fields.test.js`, `tests/Unit/PostFieldsJsTest.php`.
- `docs/changelog/CHANGELOG-1.2.62.md`, `RELEASE-NOTES-1.2.62.md`.

Changed:
- `app/Services/PostPublishing.php` — `keep` and `date` modes; `panel()`.
- `app/Services/PostEditorService.php` — `categoryOptions()`,
  `categoryIdsFor()`, `categoriesField()`, `tagsField()`.
- `app/Services/Table/ContentQuickEdit.php`, `PostQuickEdit.php`,
  `PageQuickEdit.php` — the shared field types, status options, spans.
- `app/Core/Table/TableDefinition.php`, `TableRegistry.php`, `TableService.php`
  — `row_links`.
- `routes/tables.php` — `$postLinks` for Posts and Pages.
- `app/Http/Controllers/Admin/PostController.php` — no full tag listing.
- `admin/views/posts/edit.php` — mount points; `admin/views/partials/datatable.php`
  — loads `post-fields.js` for tables with Quick Edit.
- `admin/assets/js/post-publish.js`, `post-tags.js` — glue.
- `admin/assets/js/datatable.js` — component field types, Save label,
  View/Preview, the required-field fix.
- `admin/assets/css/ui.css` (shared field styles), `block-editor.css` (moved
  out), `datatable.css` (compact layout).
- `BLOCK-EDITOR.md`, `docs/DATA-TABLE.md`, `CHANGELOG.md`, `index.php` (1.2.62).
- Tests: `tests/js/mini-dom.js`, `post-publish.test.js`, `quick-edit.test.js`,
  `tests/Integration/QuickEditTest.php`, `ScheduledPublishingTest.php`,
  `tests/Unit/PostPublishingTest.php`.

## Tests

`php tests/run.php` with a database (Dolt), on the published 1.2.61 with this
patch applied: 326 passed, 3 skipped, 1 failed — the pre-existing
`editor: a double click … creates one post` (Dolt full-text limitation; fails
identically before this release). The concurrency test is one of the skips
(Dolt; `bh_require_innodb()`).

Since the published tree carries none of the 1.2.60–1.2.61 tests, this patch
includes them, updated: scheduling, Quick Edit and the shared fields.

New or changed coverage: `keep`/`date` rules and `panel()` (unit); Change date
through the editor form and through Quick Edit, the editor's shared mount
points, Quick Edit's new field shapes, contributors and the Publish control,
`row_links` for posts, pages and templates including the preview being served
only to editors (integration); the Publish panel in the editor, the Tags and
Categories components in both modes and skins (8 tests), and Quick Edit with
the components, the Save label, Change date with confirmation, errors on the
Publish control, View/Preview and its refresh after a save (22 tests), all
running the real scripts in `tests/js/mini-dom.js`.
