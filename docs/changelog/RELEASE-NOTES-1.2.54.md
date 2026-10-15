A shared data table for the admin, and a way for apps to extend it.

- New: the admin's list screens share one table, with search, filters,
  sortable columns, pagination, bulk actions, show/hide and reorder of columns,
  a compact mode, and a card layout on phones. Your column choices are
  remembered per person. Posts and Pages use it now; add `?view=classic` to the
  URL for the old table.
- New for developers: an app can add a column to the Posts, Pages or Comments
  table — an SEO score, a comment count — without replacing the table or
  editing core. Docs: docs/DATA-TABLE.md. A worked example ships as the
  "SEO Score (demo)" app.
- Built on what is already there: the AJAX gateway, each screen's existing
  query, the permission checks, and the per-user settings column. No new
  database tables.

After updating: nothing to do. The new table reads the same data and respects
the same permissions; if anything looks off on a list screen, `?view=classic`
shows the previous one while you report it.
