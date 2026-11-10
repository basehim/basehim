# Basehim 1.2.123

Categories and Tags on the Data Table, with a New dialog that adds one term
or several. No database changes.

## Why

The Categories and Tags screen printed every term twice: a row plus a hidden
edit form, and for categories a parent list inside each. On a site with
5,597 tags (pngculture) that's tens of thousands of elements on one page, and
the browser stopped responding. The screen now shows one page of terms
(20 by default) and searches, filters and sorts in SQL.

Locally, with 3,007 tags, the page is 146 KB and loads in under a second.

## The tables

There's one table per taxonomy, `terms.{slug}`, covering Categories, Tags and
apps' taxonomies:

- **Columns:**
  - **Name.** Child categories also show "Under Engineering".
  - **Slug.**
  - **Description.** Shown by default for categories, hidden for tags.
  - **Posts.** The number links to the posts list filtered by that category or tag.
- **Search** by name or slug. **Filter** by usage: *Any usage*, *In use*, or
  *Not used* (handy for cleaning up thousands of tags). **Sort** by name or
  post count. Tags open most-used first, categories A–Z.
- **Quick Edit:** name, slug, parent (categories) and description.
  - Renaming keeps the slug, so the term's address doesn't change.
  - A category can't be moved under itself or one of its own subcategories.
  - A slug that's already taken gets a suffix, and the dialog tells you.
- **Row actions:** View (the term's public page), Quick Edit, View posts
  and Delete. **Bulk Delete** uses the danger dialog and says "Posts keep
  their content".
- **Views and roles:** table, list and grid, phone cards, Screen Options and selection work the same
  as on the other lists. Access needs `manage_taxonomies`, as before.

## New category / New tag

The side form is replaced by a **New tag** (or **New category**) button
that opens a dialog with two tabs:

- **One:**
  - Name, with a live preview of the address (`/tag/web-design`).
  - Slug, parent and description sit under a "more" disclosure.
  - **Add** closes the dialog. **Add & add another** keeps it open and puts
    the cursor back in Name.
  - A name you already have (in any letter case, or with the same slug) is
    refused with "There is already a tag called …", instead of creating
    `web-design-2`.
- **Several:**
  - Paste names one per line or separated by commas (up to 500 at a time).
    Categories can be placed under a parent.
  - Before anything is saved, it shows "3 new tags · 2 already exist
    (skipped)" and lists the existing ones.
  - **Add 3 tags** creates only the new ones.

After a save the table reloads in place and a message confirms it. If the
script doesn't load, the One form still works as an ordinary form.

## Other changes

- **Tag chips in the Posts list** now filter the list to that tag. They used to
  open the old screen's inline editor.
- **Old "edit" links:** `?edit={id}` links, like the front-end admin bar's
  "Edit category", now search for the term and open its Quick Edit.
- **Quick Edit gains a `textarea` field type.** Tables can also open a row's
  Quick Edit with the `bh:dt-quickedit` `{id}` event.
- **New AJAX actions:** `terms.create` and `terms.create_many` (with
  `preview`). Both need `manage_taxonomies` in the `/admin/taxonomies` area
  and are idempotent.
- **New route:** `POST /admin/taxonomies/{taxonomy}/bulk`, which deletes only
  that taxonomy's terms.
- **Repository:** `paginateTerms()` (with whitelisted sorts), `findTermRow()` and
  `termsMatching()`.
- **CSS:**
  - New core components: `.bh-field` and `.bh-field__error`.
  - A "New term dialog" block in `src/tailwind.css`.
  - Term cell styles in `src/datatable.css`.
  - `self-start`, used only by the old screen, is kept in the safelist.

## Files

- `index.php`
- `routes/tables.php`, `routes/ajax.php`, `routes/admin.php`
- `app/Repositories/TaxonomyRepository.php`
- `app/Services/Table/TermTableProvider.php`, `TermQuickEdit.php` (new)
- `app/Http/Controllers/Ajax/TermManageActions.php` (new)
- `app/Http/Controllers/Admin/TaxonomyController.php`
- `app/Core/Table/QuickEditor.php` (docblock)
- `admin/views/taxonomies/index.php`
- `admin/assets/js/term-new.js` (new), `admin/assets/js/datatable.js`
- `admin/assets/css/src/tailwind.css`, `tailwind.min.css`,
  `src/datatable.css`, `datatable.css`, `tailwind.safelist.js`
- `docs/DATA-TABLE.md`

## Tests

- **`tests/Integration/TermTableTest.php`** (6 tests):
  - paging, search, the usage filter and sorting, checked against 250 tags;
  - the page doesn't print every term;
  - access needs `manage_taxonomies`, and the count links to the filtered posts;
  - Quick Edit:
    - a rename keeps the slug;
    - no cycles;
    - stale versions get 409;
    - other taxonomies get 404.
  - One:
    - a duplicate in another letter case is refused;
    - tags take no parent.
  - Several:
    - the preview writes nothing;
    - existing names are skipped.
  - bulk Delete stays within its taxonomy;
  - `?edit` finds the term.
- **`tests/Unit/Release12123Test.php`:** 4 tests.
- **Updated:** `Release12102Test` (the screen is the table now),
  `DataTableTest` (tag chips filter posts) and `CommentModerationTest` (the
  bulk route check knows the taxonomy route).
- **Full suite with the database:** 792 passed, 2 failed. The 2 are the known
  BH-047 stylesheet checks.
- **In a browser** (1366, and iPhone 13), with 3,007 tags:
  - search, the usage filter, sorting and the count link;
  - Quick Edit: rename, moving to another parent, a two-line description;
  - New, One: Add & add another, the duplicate message, then Add;
  - New, Several: preview and Add;
  - bulk Delete;
  - phone cards, ⋮ menu and dialog, with no sideways scroll;
  - roles: an editor can open the screen, an author gets 403;
  - the `?edit` deep link;
  - no script errors.
