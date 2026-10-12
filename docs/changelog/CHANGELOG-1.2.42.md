# Basehim 1.2.42

## Fix: app updates failed when more than one version was waiting

Updating an app from the Updates page could fail with "Checksum mismatch —
download may be corrupted. Nothing was installed.", leaving the app on its old
version.

The cause: the Updates page remembers each app's available update (version +
checksum) from the last check. When it then installed one, it asked the hub for
the app's **current** download — not the specific version it had recorded — and
verified the bytes against the recorded checksum. As soon as a newer version was
published after that check, the hub served the newer file while the site still
held the older version's checksum, so every attempt failed.

Now the install pins the exact version the update entry names, so the file, its
recorded checksum and the hub's own checksum header all describe the same
version. Updates apply one version at a time (the one shown), and the list is
refreshed from the hub straight after, so when an app has several versions
waiting the next one appears immediately instead of only after a manual
re-check. The marketplace "Install" button, which has no recorded version, still
gets the latest.

## Admin sidebar shows an app's own icon

An app that ships an icon image now shows that icon beside its name in the admin
sidebar, sized like the built-in icons. Apps that use a built-in glyph are
unchanged, and an app with no icon still falls back to the generic one.

Files changed: `app/Services/AppService.php`,
`app/Http/Controllers/Admin/UpdateController.php`, `app/Core/App.php`,
`admin/views/layouts/app.php`, `index.php`, `install.php`.
