# Basehim 1.2.58

The **Comments** admin screen now uses the shared Data Table, so moderating
comments gets the same search, filtering, pagination, column controls, row
selection and bulk actions that Posts and Pages already have. `comments.list`
was registered in 1.2.54 as proof the table adapter is not posts-only; this
release finally puts it on the screen.

## What changed

1. **Comments screen on the Data Table.** `admin/views/comments/index.php`
   now includes the shared `partials.datatable` partial keyed to
   `comments.list`. The old status tabs are replaced by the table's **Status**
   filter (All / Approved / Pending / Spam / Trash), and the list gains search
   (author, email, content — the terms `CommentService::paginate()` already
   accepted), server-side pagination, Screen Options and row selection.
   `?view=classic` keeps the original server-rendered list as a fallback.

2. **Bulk moderation endpoint.** The data table submits bulk and per-row
   actions to a feature route, so a new
   `POST /admin/comments/bulk` → `CommentController::bulk` applies **Approve**,
   **Unapprove**, **Spam** or **Delete** to the selected comments. It reuses
   the same `CommentService::setStatus()` / `delete()` the single-row routes
   use — nothing can be done in bulk that a moderator could not already do one
   row at a time — and is CSRF-checked and gated by `moderate_comments` through
   the existing `/admin/comments` area policy.

3. **`spam` and `pending` actions in the shared table JS.** The table's row
   action menu and selection bar previously knew only `publish` / `draft` /
   `approve` / `delete`. They now also render **Spam** and **Unapprove**
   buttons when a table declares those bulk actions, and an optional
   `deleteConfirm` message lets a table whose delete is permanent (comments)
   say so, instead of the default "Move to trash". Posts and Pages declare
   neither `spam` nor `pending` and pass no `deleteConfirm`, so their behaviour
   is unchanged.

4. **Comments table columns.** `comments.list` now shows **Author** (name +
   email), **Comment** (excerpt), **On post** (a link to the post editor),
   **Status**, and **Date** (site timezone). The unusable `edit` row action was
   removed — comments have no edit screen; moderation is inline.

5. **Fix: comment search threw under strict PDO.** `CommentService::paginate()`
   reused the `:search` placeholder three times in one statement. PDO runs with
   emulated prepares off (`app/Core/Database.php`), where a reused named
   placeholder raises `HY093 Invalid parameter number` — so any comment search
   errored (the admin API's comment search, and now the new table's search box).
   It now uses distinct `:search1/:search2/:search3` placeholders, the same idiom
   the Post, User and Media repositories already use. Latent until this release
   because the old Comments screen never passed a search term.

## Files

Changed:
- `admin/views/comments/index.php` — Data Table by default, classic fallback.
- `routes/admin.php` — registers `POST /admin/comments/bulk` (literal path,
  before the `/{id}/…` routes).
- `app/Http/Controllers/Admin/CommentController.php` — `bulk()`.
- `app/Services/CommentService.php` — `paginate()` search uses distinct
  placeholders (HY093 fix).
- `routes/tables.php` — `comments.list` columns (Author/On-post links, Date),
  `row_actions` emptied.
- `admin/assets/js/datatable.js` — `spam` / `pending` in the row menu and bulk
  bar; `cfg.deleteConfirm` for a permanent-delete table.
- `admin/views/partials/datatable.php` — passes `deleteConfirm` into the config.
- `index.php`, `install.php` — version 1.2.58.

Untouched: `CommentService::paginate()`'s `status`/`search` filter *inputs*
(only the search SQL's placeholder names changed), the Posts/Pages/Templates
tables, the AJAX gateway, and every existing comment route (`approve` / `spam` /
`delete` single-row forms still work and remain the classic view's controls).

## Checked

On a 1.2.57 site updated to 1.2.58:

- the Comments screen renders through the Data Table; Status filter, search and
  pagination work;
- selecting comments and running **Approve**, **Unapprove**, **Spam** and
  **Delete** from both the bulk bar and the per-row menu changes status /
  removes rows, with a CSRF check and a summary flash;
- searching comments returns matches (the reused-placeholder query that used to
  raise HY093 on this strict-PDO install now works — verified against the live
  database with emulated prepares off);
- the permanent-delete confirmation names that it cannot be undone;
- `?view=classic` still renders the original list and its single-row controls;
- the Posts and Pages tables are unchanged (no Spam/Unapprove buttons, delete
  still reads "Move to trash").
