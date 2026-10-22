# Basehim 1.2.82: Settings, like the dashboard on desktop and like an app on phones

Asked for on 22 October 2026: the Settings pages with the same typography and
cards as the dashboard, switches instead of checkboxes, and a native-app feel
on phones; then full width on desktop, and sections opening through Basehim
AJAX.

## Pages

- Every Settings page is built from shared parts
  (`admin/views/settings/_ui.php`): `bh_set_card_open()` / `_close()` (the
  core card), `bh_set_row()` (name and help on the left, the control on the
  right; `wide`, `sub`), `bh_set_toggle()` / `bh_switch()`, `bh_set_save()`.
  `_head.php` prints the title, the tabs and, on phones, the back link;
  `_sections.php` lists the sections once for the tabs and the phone list.
- Full width, as the dashboard; a field stops at a comfortable width (42rem)
  unless it is a long one (`wide`).
- Settings regrouped into named cards (Reading: Homepage, Authors, Search and
  page views; Email: Sender, Sending; and so on), labels in sentence case.
- Save is one bar at the foot of the form, kept in view while scrolling. Email
  and Authentication say "Save changes" like the others.

## Switches, choices, segmented control, notes

- Every checkbox is a switch: `.bh-switch` (a checkbox with `role="switch"`
  over its track; tap, click, Space). Each posts exactly what its checkbox did:
  the hidden "0" where the page had one, nothing when off where it didn't
  (AI, Authentication, Permalinks' HTTPS, Email's "Remove the saved
  password").
- Radio options with a sentence each are `.bh-choice` rows (Permalinks,
  Two-step verification, Regenerate thumbnails); AI crawler Allow/Block is a
  `.bh-seg` segmented control (Block in red), with no script needed.
- `.bh-note` / `.bh-note--warn` for notes inside a card.
- `hidden` always wins over a component's display (`.bh-btn[hidden]`,
  `.bh-set [hidden]`): Media's Stop button and General's account-email panel
  stayed visible otherwise.

## Phones

- `/admin/settings` is the list of sections (icons, chevrons), like a phone's
  own Settings app; on desktop it is General, as before
  (`SettingController::general()` passes `settingsHome`).
- A section has a "‹ Settings" back link and a large title; each card becomes
  a heading above an inset panel; fields are 44 px tall (16 px text, so
  iOS doesn't zoom); switches are larger; Save is a full-width button docked
  to the bottom, above the home indicator.

## Sections without a page load

- New gateway action `settings.section` (GET, `manage_settings` and the
  screen's own area rule): the content of the page's `<main>` exactly as
  `GET /admin/settings/{tab}` renders it, the page title and the top-bar
  heading. `tab` is `home` or a screen; not `appearance`, whose address has
  opened the Customizer since it moved there (that tab stays a link).
- `admin/assets/js/settings-nav.js`: the tabs, the phone list and the back
  link load sections through it, re-run the section's own scripts, bind its
  forms for in-page saving (`window.bhSettingsBind`, settings-save.js), and
  update the address, title and Back/Forward. Sections fade in on desktop and
  slide in on phones (from the right, back to the list from the left); reduced
  motion turns that off. Any failure falls back to a normal page load.
- The layout's `<main>` has an id (`bh-content`), so the section is found
  exactly (the word appears in a comment in the layout's styles).
- Email's SMTP script moved into the page content so it comes with the
  section.

## Fixed

- `RateLimiterTest`, "the global per-IP limit applies across actions" failed
  now and then: the limit counts in fixed one-minute windows on the clock,
  and four requests straddling a minute were counted in two. The test now
  starts well inside a window.

## Files

- `admin/views/settings/*.php` (all; new `_ui.php`, `_head.php`,
  `_sections.php`), `admin/views/layouts/app.php` (`<main id>`)
- `admin/assets/js/settings-nav.js` (new), `admin/assets/js/settings-save.js`
- `admin/assets/css/src/tailwind.css`, `admin/assets/css/tailwind.min.css`
- `app/Http/Controllers/Admin/SettingController.php`,
  `app/Http/Controllers/Ajax/SettingsActions.php`, `routes/ajax.php`
- `tests/Integration/SettingsPagesTest.php` (new),
  `tests/Integration/RateLimiterTest.php`
- `docs/ADMIN-UI.md`, `docs/APP-AJAX.md`, `CHANGELOG.md`, `index.php`

## Checked

375 passed, 4 skipped (Dolt). In a browser: all ten sections by tab without a
reload (gateway calls, titles, headings, forms bound), saving after a switch
of section, Back; Media's Stop, Email's SMTP fields and the Media Library
picker after a swap; Appearance still opens the Customizer; on a phone the
list, the slide, the back link and Back; switches saved and restored by tap
and by keyboard on Reading, AI and Authentication. Screenshots reviewed before
release.
