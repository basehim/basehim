# Basehim 1.2.54

One new piece of core: the **Data Table system**. The admin's list screens now
share one table — search, filters, sortable columns, server-side pagination,
row selection and bulk actions, column show/hide and reorder, density, and
responsive desktop/mobile layouts — and an **app can add a column to an
existing table** without replacing it or editing core.

Posts and Pages use the new table by default. Nothing else changes; `?view=classic`
falls back to the previous server-rendered page.

## What it is built on

No second router and no new storage. The table reuses:

- the **AJAX gateway** (`POST /ajax`) for its one read action — session auth,
  CSRF-exempt GET, origin, capability, rate limit and input schema are the
  gateway's, not re-implemented;
- each feature's existing **`paginate()`** (PostService, CommentService), with
  its whitelisted sort map and bound parameters — the table holds no SQL;
- `CheckCapability` and `AdminAreaPolicy` for permissions, honouring a site's
  `admin.area_policy` overrides;
- the existing **`users.meta`** JSON column for per-user preferences — the
  first reader/writer of a column present since the initial schema.

## New API

- **Table registry** — `App\Core\Table\TableRegistry`, filled in
  `routes/tables.php`. Core registers `content.posts`, `content.pages` and
  `comments.list`. Every table names the capability and admin area it serves.
- **Columns** — declarative arrays, validated at registration the way AJAX
  action options are (unique ids, declared sortable/filterable, a real callable
  render, a capability that gates visibility). An app appends columns through
  the `datatable.columns.{key}` filter; ids are namespaced by slug, duplicates
  are refused, and a capability-gated column is removed from the response for
  users who lack it — not hidden in CSS.
- **Providers** — `App\Core\Table\TableProvider`: three methods
  (`sortKeys`, `filterKeys`, `query`) mapping the generic request onto the
  feature's `paginate()`. `PostTableProvider`, `PageTableProvider`,
  `CommentTableProvider`.
- **One read action** — `content.table` (GET). Writes stay on each feature's
  existing CSRF-protected routes, so ownership and publish rules are never
  bypassed.
- **Preferences** — `content.table_prefs` / `content.table_prefs_reset`, stored
  in `users.meta` and reconciled with the live definition on every read.
  `UserService::meta()` / `setMeta()` are the small helpers over the column.

Docs: **docs/DATA-TABLE.md**.

## Performance: the two-phase column contract

A page of 100 rows × 12 columns is up to 1,200 cells. To keep that at
O(columns), a column may define:

- `prepare(array $rows)` — run **once** for the whole page; do a single
  `WHERE id IN (…)` bulk query and return a lookup;
- `render(array $row, $prepared)` — **pure**; reads the row and the prepared
  lookup only. No per-cell I/O.

Core caps `per_page`, bounds `prepare()` to one page, and guards both so a
column that throws costs that column, not the page. The built-in **Category**
column uses this (one query per page for all posts' terms), as does the bundled
**SEO Score** example app.

## Security

- Sort and filter inputs are whitelists declared by the provider and the
  columns; nothing from a request reaches an `ORDER BY` or an undeclared field.
- App column HTML is run through `HtmlSanitizer`; values are escaped.
- Capability-gated columns are removed from the payload; per-row ownership
  stays the handler's job and is re-checked by the existing write routes.
- `per_page` has a hard ceiling (100); preferences are validated and size-capped
  before they are stored.

## Files

New:
- `app/Core/Table/` — `TableRegistry`, `TableDefinition`, `Column`,
  `TableProvider`, `TableQuery`, `TableService`, `TablePreferences`.
- `app/Services/Table/` — `PostTableProvider`, `PageTableProvider`,
  `CommentTableProvider`.
- `app/Http/Controllers/Ajax/TableActions.php`.
- `routes/tables.php`.
- `admin/assets/js/datatable.js`, `admin/assets/css/datatable.css`,
  `admin/views/partials/datatable.php`.
- `docs/DATA-TABLE.md`.

Changed:
- `index.php`, `install.php` — version 1.2.54; `index.php` loads
  `routes/tables.php`.
- `routes/ajax.php` — registers `content.table`, `content.table_prefs`,
  `content.table_prefs_reset`.
- `app/Services/UserService.php` — `meta()` / `setMeta()`.
- `admin/views/posts/index.php` — uses the shared table for the main Posts and
  Pages list; `?view=classic` and the Trash view keep the original.

Untouched: the AJAX gateway, the repositories' `paginate()` bodies,
`CheckCapability`, `AdminAreaPolicy`, and every existing write path.

## Tests

- `tests/Unit/TableRegistryTest.php` — registration validation (duplicate keys
  and ids, non-callable render, missing capability, provider type), the
  extension filter, and capability gating.
- `tests/Unit/TablePreferencesTest.php` — reconciliation (unknown ids dropped,
  non-hideable forced, page size clamped, bad sort rejected, reset).
- `tests/Integration/DataTableTest.php` — `content.table` through the real
  gateway: rows/meta/columns, capability (403) and unknown table (404),
  sort/filter whitelisting, the `per_page` ceiling (422), and the preferences
  round-trip through `users.meta`.

Full suite: 85 unit + 133 existing integration + 9 new integration — 227
passing, 0 failing.
