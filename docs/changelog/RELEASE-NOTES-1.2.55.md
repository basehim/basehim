Fixes and polish for the new admin data table.

- Fixed: on phones the Posts list showed nothing, and the Screen Options button
  did not open. Both work now, along with sorting, filtering, pagination and
  bulk selection, on every screen size.
- The Posts and Pages lists now match the intended design: a thumbnail for each
  post (hideable in Screen Options), the author's avatar next to their name,
  category and status filters, a selection bar with Publish / Unpublish /
  Delete, numbered pagination, and a tidy card layout on phones.
- The table's styles and script now refresh in the browser automatically when
  they change — no hard refresh needed.

After updating: nothing to do. If a list ever looks off, add `?view=classic` to
the URL for the previous table while you report it.
