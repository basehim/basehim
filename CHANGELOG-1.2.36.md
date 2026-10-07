# Basehim 1.2.36

## App updates on the Updates page

- **Apps are checked with Basehim.** Every check — the button, the dashboard's
  hourly background check — now also asks CloudHim which installed apps have
  a newer published version, using its `item-updates` endpoint.
- **One list, one badge.** The Updates page lists app updates under
  Basehim's own, each with its icon, installed and new version, developer and
  company, size, release notes, and the notes of any versions in between. The
  sidebar badge and the "Updates available" line count both.
- **Update one, or Update all.** Each app has its own Update button. Update
  all installs Basehim releases first (oldest first, as before), then each
  app in turn, one request each, with progress and a per-app result.
- **Safe by default.** A download is checked against the SHA-256 the update
  list announced as well as the one sent with the file; a mismatch installs
  nothing. The app folder is swapped as a whole and restored if the swap
  fails. Settings, tables and active state are kept, `onUpgrade()` runs as
  usual, and a version that asks for new permissions keeps running and is
  flagged for review with a link. If an app's update fails, it stays on its
  current version and the reason is shown on its row; the rest carry on.
- After an app's files are replaced, PHP's opcode cache is cleared, so the
  next request runs the new version rather than the cached old one.
- **Older hubs.** A CloudHim server without `item-updates` answers 404; that
  is treated as "no app updates" and the page works exactly as before.
- `UPDATE_HUB_URL` in `.env` points a site at a different update hub, for
  developers testing their own. Without it, sites use cloudhim.com as before.

## App listing details in app.json

New optional manifest fields, described in docs/APP-DEVELOPMENT.md:

- `icon` — a PNG or GIF in `assets/`, square, 256×256 or 512×512, up to 1 MB.
- `description` — plain text, up to 600 characters.
- `developer` — `{ "name", "url" }`, or a string plus `developer_url`.
- `company` — `{ "name", "url" }`, or a string plus `company_url`.

Shown on the Apps screen (icon, developer and company linked to their sites,
description), on the Updates page, and in the marketplace when CloudHim sends
them. Links must be full http(s) addresses; anything else is ignored rather
than rendered. A field that breaks a rule is listed on the app's card under
"App details need attention" — the app works normally either way.

**Nothing existing changes.** `"author"` still names the developer,
`heroicon:` and `fa-` icons still draw, a bare file name still means a file in
`assets/`, and SVG/JPEG/WebP icons still show (with a note recommending PNG or
GIF). The apps table's `author` column keeps holding the developer's name, so
code reading it is unaffected. No database migration.
