# Basehim 1.2.121

The admin header becomes the admin's own bar: content search, New, View site,
notifications and the account menu. Stage 1 of bringing the front-end admin
bar's tools into the dashboard (light skin). No database changes.

## Header

Desktop, left to right after the page title:

- **Search content… (Ctrl K / ⌘K)** — opens a search dialog over the content
  this user can edit: posts and pages (drafts included where they may edit
  them), media, users and categories/tags, grouped, five per group. Two
  characters at least; results come as you type. ↑ ↓ move, Enter opens,
  Ctrl/⌘ Enter opens in a new tab, Esc closes. The sidebar keeps `/` for its
  menu filter.
- **New ▾** (was "Create") — Post, Page, Media, Category, Tag, User, filtered
  by capability as before.
- **View site** — opens the site in a new tab.
- **Notifications** — a bell with a count: updates available, comments waiting
  for approval, failing scheduled tasks and security warnings (debug mode on,
  no HTTPS), each linking to where it's fixed. The count is red when anything
  needs attention, accent-blue when it's only information. These are live
  conditions, so they clear when the cause does; there's nothing to mark read.
- **Account** — name and email at the top, Profile, Settings (now shown only
  to people who may open Settings; it used to be shown to everyone) and Log out.

Phones: menu, title, **+**, bell, avatar. The bell opens a sheet under the
header with **Search content…** at the top; search opens full screen with a
Cancel button. View site stays in the sidebar.

All controls are 36px. The header is still `.bh-main > header`, so the data
table's sticky offsets are unchanged (checked: controls stick at 64px).

## How it works

- The layout prints a signed page descriptor with type `admin`
  (`AdminBarContext::make(['type' => 'admin', 'path' => …])->descriptor()`).
- `admin.bar_search` and `admin.bar_notifications` accept it. For this type
  they don't apply the front bar's switches (Settings → Admin bar: on/off,
  roles, search, notifications), since this is part of the admin, not the
  front-end bar. Every provider still filters for the user (capabilities,
  `canEdit`, admin area rules), and apps' search and notification providers
  appear here too.
- The admin descriptor opens nothing else: `admin.bar_panel`,
  `admin.bar_indicators` and `admin.bar_url` answer 404 for it. Front-page
  descriptors work exactly as before.
- New `admin/assets/js/topbar.js` (deferred). Uses `Basehim.ajax`, builds
  results with text nodes, aborts stale searches, and loads the bell count when
  the page is idle (the panel refreshes on open, at most every 30 s).
- The search dialog is a native `<dialog>` on the core `.bh-dialog` (focus
  stays inside, Esc and the backdrop close it, focus returns to the button).
  It sits near the top so the field doesn't move as results change.

## Components (src/tailwind.css, "Admin header")

`.bh-kbd`, `.bh-top` (+ `__start`, `__menu`, `__title`, `__end`), `.bh-top-sep`,
`.bh-top-search` (+ `--block`), `.bh-top-new`, `.bh-top-view`, `.bh-top-bell`,
`.bh-top-badge` (+ `.is-quiet`), `.bh-top-user`, `.bh-top-panel` (+ `--menu`,
`__label`, `__who`, `__sep`), `.bh-top-item` (+ `--danger`, `__icon`),
`.bh-notify` (+ `__search`, `__head`, `__list`, `__state`, `__item--warning|danger`),
`.bh-cmdk` (+ `__bar`, `__input`, `__group`, `__item`, `__foot`).

Utilities that only the old header used are kept in `tailwind.safelist.js`, so
apps that use them aren't affected; the compiled sheet is a superset of
1.2.120's.

Other changes:

- `icons.js` gains `bell`, `bell-slash`, `chat-bubble-left-right`,
  `chevron-down`, `newspaper`, `user-circle` and `arrow-turn-down-left`,
  copied from `Icon.php` (search results name `newspaper` and `user-circle`).
- Dropdown carets: only a trailing `svg` turns when a menu opens, so the bell's
  count isn't rotated.

## Files

- `index.php` — version.
- `admin/views/layouts/app.php` — header, search dialog, script tag.
- `admin/assets/js/topbar.js` — new.
- `admin/assets/js/icons.js` — seven icons.
- `app/Http/Controllers/Ajax/AdminBarActions.php` — the admin descriptor.
- `admin/assets/css/src/tailwind.css`, `admin/assets/css/tailwind.min.css`,
  `tailwind.safelist.js`.

## Tests

- `tests/Unit/Release12121Test.php` (4 tests).
- `tests/Integration/AdminBarTest.php`: the header's search and notifications
  for an admin and a contributor (who doesn't see someone else's draft); they
  work with the front bar switched off; a tampered descriptor gets 409; the
  admin descriptor gets 404 from the panel and badge calls; a front page's
  descriptor still respects the front bar's search switch.
- Full suite with the database: 774 passed, 2 failed. The 2 are the known
  BH-047 checks for `dashboard.css` and `marketplace.css` in the repository
  (see 1.2.90), unrelated.
- In a browser (1366, 1280, 1024, 820, 390): layout and no sideways scroll,
  bell count and panel, search by mouse and keyboard (Ctrl K, arrows with wrap,
  Enter navigates, Esc closes, focus returns), no-results and hint states,
  New and account menus, phone sheet → full-screen search → Cancel, the nav
  drawer still covers the header, sticky table controls at 64px, no script
  errors.
