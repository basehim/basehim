Fix for sites on MySQL: installing and updating work again.

- A database update shipped earlier used a command that only MariaDB understands. On MySQL, new installations stopped at the database step, and existing MySQL sites could not apply that update or any update after it.
- Sites on MariaDB were not affected, but should still install this release.
- If your site runs MySQL, the pending database updates are applied automatically when you install this release.

No other changes.
