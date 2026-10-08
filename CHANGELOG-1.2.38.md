# Basehim 1.2.38

## Marketplace

The app and theme marketplaces now look and work like the Updates page:
compact lists, with the details one click away.

### Apps

- **One row per app:** icon, name, version, developer and company, category,
  one line of the description, and the button — **Install**, **Update to
  vX.Y.Z**, or **Installed**. An app that is installed but switched off says
  "Not active".
- **Details dialog.** Clicking an app opens its full description, the version
  on offer and the one installed, the permissions it asks for, installs,
  download size, date added, tags, and links to the developer and company.
  It can be installed or updated from the dialog, which shows the result and,
  for a new app, a link to activate it.
- "Update to" is offered only when the marketplace version is newer than the
  one installed. Before, any difference counted, so a site running a newer
  development build was offered a downgrade.

### Themes

- **Compact cards:** the screenshot, name, version, author and the button.
  Up to four to a row on wide screens, two on a phone.
- **Details dialog** with a large screenshot, the description, category, tags,
  installs, size and date added, and the install button.

### Both

- The button in the list and the one in the dialog stay in step while an
  install runs and after it finishes.
- An icon or screenshot that fails to load falls back to a plain tile.
- The dialog is shared (`admin/views/partials/item-modal.php`): Escape, Close
  or a click outside closes it.

Files changed: `admin/views/apps/marketplace.php`,
`admin/views/themes/marketplace.php`, `admin/views/partials/item-modal.php`
(new), `index.php`, `install.php`.
