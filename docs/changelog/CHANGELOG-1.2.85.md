# Basehim 1.2.85: the Apps page, registered installs only, friendlier permissions

## What was wrong

- **Renamed and hand-copied folders (BH-042).** `AppService::sync()`, run on
  every visit to Apps, registered any folder in `content/apps` with an
  `app.json`. A folder renamed in a file manager appeared as a new app next to
  the old one ("remove from list"), and a folder uploaded by FTP could be
  activated without the ZIP install's security scan, code check or record.
- **Permission failures (BH-044).** An app screen calling `$this->db()`
  without `db.raw` answered with a 500; an app whose `boot()` needed it was
  switched off as if its code were broken.
- **The page.** Stats cards and one card per app with hand-built buttons and
  colours; the consent screen was a separate page; no search or bulk actions.

## The fix

### Installs

- `AppService::register($slug, $source)` is the one way an app becomes
  installed: it checks the folder (a valid `app.json`, named after its slug),
  records it with its source (`upload`, `marketplace`, `devtools`), runs
  `onInstall()`, the security scan and the code check. ZIP uploads and
  marketplace installs use it; Basehim Dev Tools should call it (BH-043).
- `sync()` keeps registered apps up to date but no longer registers folders;
  `unregisteredFolders()` lists them for a note on Apps.
- `reconcile()` (on Apps) and boot: an installed app whose folder is gone is
  switched off and listed as "Files missing"; a pre-1.2.85 record whose folder
  holds a different slug (a renamed copy) is dropped from the list when it
  isn't running. Files are never touched.
- Installing over a folder that isn't an installed app is refused with a
  clear message.
- Updates (marketplace and ZIP) are security-scanned; installed apps never
  scanned are scanned a few per visit (`scanUnscanned()`).
- `apps.source` (migration 021; added on first use if the files arrive first).
  Existing rows are `legacy`.

### The Apps page

- `admin/views/apps/index.php`: the shared page header (`.bh-page-head`, the
  Marketplace's, now generic), an upload card, the note about stray folders
  and the Data Table `apps.installed` (`routes/tables.php`,
  `App\Services\Table\AppTableProvider`): icon, app (with the reason when
  something is wrong), status, version, permissions, security scan, installed
  from; developer and installed date optional. Status filter: Active,
  Inactive, Needs approval, Can't activate, Switched off, Files missing.
- Gateway actions (`App\Http\Controllers\Ajax\AppActions`): `apps.bulk`
  (activate, deactivate, check again, delete; each app reported on its own),
  `apps.consent`, `apps.consent_save`.
- `admin/assets/js/apps.js` runs the table's actions through `Basehim.ajax`.

### Data Table: declared actions

A table can declare `actions` (`id`, `label`, `icon`, `tone`, `when`, `flag`,
`href`, `event`, `confirm`, `bulk`, `row`); the row menu and bulk bar are built
from them. Rows may carry `row_key` and `row_flags`; a status filter may name
its options (`labels`). New event `bh:dt-action`. The toolbar band's inline
style moved to `datatable.css`. See docs/DATA-TABLE.md.

### Permissions

- One permission window for Apps and the Marketplace
  (`admin/assets/js/app-consent.js`, inside the details window): "Checked for
  you" (where the app came from, the scan, the code check), each permission
  in plain words (`PermissionBroker::FRIENDLY`), the app's own reasons
  (`app.json` `permission_reasons`), a quiet "Sensitive" tag for high-risk
  ones.
- `App\Core\AppPermissionMissing` (a `RuntimeException`) from `db()` /
  `schema()`. An app's admin screen answers with
  `admin/views/apps/permission-needed.php` and the window open; allowing it
  reloads the page (`app-permission.js`). JSON callers and the AJAX gateway
  get `403 APP_PERMISSION_MISSING`. In `boot()` it no longer quarantines the
  app: it doesn't start that request, Apps shows "Needs approval" and its
  screens ask for the permission (`App\Http\Controllers\Admin\AppPermissionPage`).

### Icons

`power`, `document-magnifying-glass`, `tag`, `information-circle`,
`chat-bubble-left-ellipsis` added to `icons.js`.

## Tests

`tests/Integration/AppsPageTest.php` (10): a stray folder is not registered,
listed or installable over; a ZIP upload registers an `upload`, scanned and
inactive; a renamed folder switches its app off and shows it once as
missing; a legacy renamed-copy record is dropped; `apps.bulk` activates,
deactivates and asks for approval first; other roles are refused; the page has
no stats cards or inline styles; an app screen missing a permission asks for
it and works once allowed (and JSON gets the code); an app needing one to
start isn't switched off; the window's plain words, reasons and checks.
`AppIsolationTest` and `MarketplaceUiTest` follow the new markup.
