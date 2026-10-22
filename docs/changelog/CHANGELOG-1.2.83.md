# Basehim 1.2.83: one spinner per busy button, icons for done and failed (BH-036)

## What was wrong

Saving a Settings screen showed two spinners in the Save button, and the
button ended with a text "✓" (or "!"). Two layers decorated the same button
without knowing about each other:

- `bhLoader(button, 'Saving…')` (1.2.50) replaces the button's contents with
  its own spinner and words, and sets `aria-busy="true"`;
- 1.2.77 added `.bh-btn[aria-busy="true"]::before`, a spinner drawn by the
  stylesheet for `bhBusy()`, which set only `aria-busy`. It matched every
  busy `.bh-btn`, including bhLoader's, so they got both (Settings, the post
  editor's Save draft, the media library, data tables).

The same kind of double icon (two sources for one decoration) had been
reported twice before.

## The fix

- One owner: `bhBusy(button, true, label)` now uses the loader bhLoader uses
  (`buttonLoader()` in the layout), so a busy button looks and behaves the
  same whichever is called; an icon-only button gets the spinner alone.
  `bhBusy(button, false)` restores it exactly.
- The stylesheet no longer draws a spinner on `[aria-busy]`; it only blocks
  clicks. A comment says why.
- A guard in `ui.css`: inside a busy button only the loader's spinner/icon and
  its label show; anything else is hidden, and `::before` / `::after` content
  is turned off.
- Done and failed marks are icons from the icon set (`check`,
  `exclamation-circle`) instead of the characters ✓ and !, in buttons and in
  the pill loader (`bhLoader(element)`), whose CSS `content` marks are gone.

## Guarding it

`tests/Unit/BusyStateTest.php` fails if any stylesheet (Tailwind source and
build, ui.css, the admin styles, the layout) styles `::before`/`::after`
content on a busy element, if `bhBusy()` gets its own busy state again, if
the guard is removed, or if a ✓ character is used as a status mark in the
layout, ui.css or the admin scripts. Run against the 1.2.82 files, it reports
all four problems.

## Files

- `admin/views/layouts/app.php` (bhBusy, buttonLoader, the pill loader)
- `admin/assets/css/ui.css`, `admin/assets/css/src/tailwind.css`,
  `admin/assets/css/tailwind.min.css`
- `tests/Unit/BusyStateTest.php` (new)
- `docs/ADMIN-UI.md`, `CHANGELOG.md`, `index.php`

## Checked

378 passed, 4 skipped (Dolt). In a browser: the Settings Save button with the
save slowed down shows one spinner (no pseudo-element content) and an SVG
check when saved; `bhBusy()` on a labelled and an icon-only button shows one
spinner each and restores both exactly; the pill loader ends with an SVG icon.
