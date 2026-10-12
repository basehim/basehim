# Basehim 1.2.37

## Updates page

- **Update all finishes visibly.** The outcome ("Up to date. 3 apps updated.",
  or what went wrong) now appears at the top of the list instead of below it.
  With several apps the message was off-screen, and the progress bar sat at
  100% under "Keep this tab open", so a finished update looked stuck. When
  everything is done the bar goes away, the heading says "All updates
  installed", and the Update all button is hidden until there is something
  new to install.
- **Compact app list.** Each app is one row: icon, name, installed → new
  version, developer and company, and an Update button. Release notes are no
  longer printed in the list.
- **App details dialog.** Clicking an app opens its details: icon, developer
  and company (linked), installed and new version, description, release date,
  download size, checksum, the new version's release notes, and the notes of
  any versions in between. The app can be updated from the dialog, which shows
  the result when it finishes. Escape, Close or a click outside closes it.
- App updates now carry the app's description (the installed app's own, or
  CloudHim's when the app has none).

Files changed: `admin/views/updates/index.php`,
`app/Services/UpdateService.php`, `index.php`, `install.php`.
