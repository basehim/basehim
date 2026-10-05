Table prefix on new installs.

- The installer now asks for a table prefix (for example `bh_`), so several
  sites can share one database. Before, every install got bare table names
  (`users`, `posts`) even when a prefix was set.
- The installer will not overwrite another site's tables: if tables with the
  chosen prefix already exist, it asks you to pick another prefix or confirm
  replacing them.
- Fixed: AI connector sign-in (MCP) and "Remember me" on sites that use a
  table prefix.
- The System page lists only this site's tables.
- Already installed without a prefix and want one? Run
  `php database/add-prefix.php bh_` from the command line to see what would
  change, then add `--apply`. Back up the database first.
- Sites without a prefix need do nothing.