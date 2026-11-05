# Basehim 1.2.100 — 2026-11-05

Fixes found testing 1.2.99 on cloudhim.com: row actions in data tables,
Customize controls that a theme doesn't use, PHP 8.5 deprecation notices,
and long media titles on phones. Fixes BH-105 and BH-106. No database
changes. Versions now continue 1.3.x.

## Data tables: row actions in Comfortable density

In 1.2.99 the row links lived in a second table row under each item, and
the two rows were kept in step by script. In real use the links showed on
every row, and the links line sat outside the row's hover colour. The
`@media (hover: none)` rule also showed them on every row on touch-capable
screens.

The links now live **inside their own row**:

- They are drawn across the bottom of the row from its first cell
  (`td.bh-dt-linkcell`, `position: absolute`), over bottom padding the row
  keeps for them (`tr.has-rowlinks > td`).
- One table row per item, so the row's own `:hover` and `:focus-within`
  show its links and colour the whole area, links included.
- Nothing moves on hover: the space is always kept, and only `visibility`
  changes.
- No script keeps rows paired (`buildLinksRow`, `pairOf` and `is-hover` are
  removed), and there is no touch-only rule. On a touch screen, tapping a
  row shows its links.

Compact density and cards are unchanged.

## BH-105: Customize offers only what the theme uses

Logo width and the Site name / Site tagline switches only work when the
theme reads them. On themes that didn't, they showed and did nothing.

Themes now declare them in `theme.json`:

```json
"supports": ["custom-logo", "logo-width", "header-text"]
```

`CustomizerService::filterBySupport()` drops `identity.logo_width` without
`logo-width`, and both switches without `header-text`. A width saved
earlier then adds no CSS variables. The Default and Dark Night themes
declare both. `docs/THEME-DEVELOPMENT.md` has a table of what each value
offers and what the theme must do.

## BH-106: PHP 8.5 deprecations

On PHP 8.5, every request that used the database logged
`PDO::MYSQL_ATTR_INIT_COMMAND is deprecated`, and every outbound HTTP call
logged `curl_close() is deprecated`.

- `Database::initCommandAttribute()` returns `Pdo\Mysql::ATTR_INIT_COMMAND`
  where it exists (PHP 8.4+) and the old constant otherwise. The value is
  the same on every PHP version. `install.php` does the same.
- `curl_close()` (a no-op since PHP 8.0) is replaced by `unset()` in
  `CanonicalUrlService`, `HtaccessService`, `ThemeService`, `AppService`,
  `UpdateService` and `Core/Api/HttpApi`.
- Apps have the same `curl_close()` calls and need their own releases:
  WP Migrator, Public Tools and Basehim Dev Tools.

## Media library on phones

In the List view and on phones, a long file name with no spaces ran across
the size and date beside it. The media title button kept
`white-space: nowrap; max-width: 28rem`, sized for the desktop table. On
cards it now wraps to two lines within its column, ending with "…".

## Files

`admin/assets/js/datatable.js`, `admin/assets/css/datatable.css`,
`admin/assets/css/media-picker.css`, `app/Services/CustomizerService.php`,
`app/Core/Database.php`, `install.php`,
`app/Services/{CanonicalUrlService,HtaccessService,ThemeService,AppService,UpdateService}.php`,
`app/Core/Api/HttpApi.php`,
`content/themes/{default,dark-night}/theme.json`,
`docs/THEME-DEVELOPMENT.md`, `index.php`.

## Tests

- New in `tests/Unit/Release130Test.php`:
  - Customize controls depending on what the theme declares, and the CSS
    variables;
  - the bundled themes' declarations and the docs table;
  - no deprecated calls in core;
  - the in-row link CSS;
  - the media card title.
- `tests/js/quick-edit.test.js`: the links are in their own row (one table
  row per item).
- `tests/Unit/Release1299Test.php`: its theme stub now declares support.
- MariaDB: 706 passed, 2 failed. Both failures are the known BH-047 ones.
