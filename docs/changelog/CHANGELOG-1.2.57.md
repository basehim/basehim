# Basehim 1.2.57

Data-table filters: multi-select Category and Tags with search, a linked Tags
column, a working Status filter, and page numbers that survive a filter, sort or
view change.

1. **Category and Status filters did nothing (over AJAX).** When a table had no
   active filters the server serialised them as `[]`, so the client's
   `state.filters` became a JavaScript **array**; setting `filters.status` on an
   array is silently dropped by `JSON.stringify`, so the request always sent
   `filters=[]` and nothing was filtered. (The no-JS first page built the filter
   array in PHP, which is why it looked fine.) The client now always holds
   filters as a plain object.
2. **Category and Tags are multi-select dropdowns with search.** Each is a
   Screen-Options-styled panel: a search box, a scrollable checkbox list, a
   selected-count badge and a Clear. Selecting more than one term widens the
   result (OR within a filter); a Category and a Tag selection together narrow
   it (AND across filters). The first open shows the most-used terms (30
   categories, 50 tags). If the taxonomy has more terms than that, typing
   searches the database live over AJAX; if it does not, the list is already
   complete and the search filters it in the browser. A selected term stays
   visible and un-tickable even when it falls outside the current search.
3. **Tags column.** Posts and Pages gain a Tags column, each tag a chip that
   links to its edit screen (`/admin/taxonomies/tag?edit={id}`); that screen now
   opens the matching term's editor and scrolls to it. Loaded in one query per
   page, like Category (no N+1).
4. **Page number is preserved.** Changing the view, a filter, a sort or a Screen
   Options setting keeps you on the current page instead of snapping back to
   page 1; paging forward keeps the active filter, sort and screen settings. If
   the current page no longer exists after a filter narrows the results, the
   table drops to the last page that has rows.

## Files

New:
- none (all additions are in existing files).

Changed:
- `app/Repositories/PostRepository.php` — `paginate()` accepts `category` and
  `tag` as one id or a list of ids (`termIdList()` normalises to positive ints);
  OR within a filter via `IN (…)`, AND across filters via separate subqueries.
- `app/Services/Table/PostTableProvider.php` — `tag` added to `filterKeys()`;
  category/tag passed through as scalar-or-list.
- `app/Core/Table/TableService.php` — filter reconciliation accepts array
  values (multi-select), still validating each member against a column's `in`
  set where one is declared.
- `app/Repositories/TaxonomyRepository.php` — `searchTerms()` (most-used first,
  optional search, force-include selected ids) and `countTermsBySlug()`.
- `app/Services/TaxonomyService.php` — passthroughs for the two methods above.
- `app/Http/Controllers/Ajax/TableActions.php` — `terms()` handler for the
  `content.table_terms` action.
- `routes/ajax.php` — registers `content.table_terms` (GET, rate-limited).
- `routes/tables.php` — `tags` column (prepare + chip-link render) on the Posts
  and Pages tables.
- `admin/views/partials/datatable.php` — builds the term-filter config (initial
  terms + total per taxonomy), renders the Category/Tags filter hosts, parses
  multi-value `category`/`tag` from the query string.
- `admin/views/taxonomies/index.php` — `?edit={id}` opens that term's editor.
- `admin/assets/js/datatable.js` — object-safe `state.filters`; the
  multi-select term-filter widget (search, live/offline, checkboxes, badge,
  Clear); page preserved across filter/sort/view/screen-option changes with a
  clamp to the last populated page.
- `admin/assets/css/datatable.css` — filter button, badge, dropdown panel,
  term list and the linked-chip style.
- `index.php`, `install.php` — version 1.2.57.

## Tests

`tests/Integration/DataTableTest.php` adds: multiple category ids match any
(OR); a category plus a tag narrows to posts in both (AND); the Posts table
offers a `tags` column and accepts a `tag` filter list; the tags cell links to
the term's edit screen; and `content.table_terms` returns terms with a total
(unknown taxonomy → 422).

Full suite: 244 passing, 0 failing.
