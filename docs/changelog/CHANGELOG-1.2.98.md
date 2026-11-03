# Basehim 1.2.98 — 2026-11-03

A rebuilt installer and a better first impression from the default theme.
Fixes BH-086, BH-087, BH-088 and BH-089. No database changes.

## Installer (`install.php`)

### Fixes

- **BH-086: the "Installation complete" screen was never shown.** The last
  step wrote `.env` with `INSTALLED=true` and redirected to the done screen,
  but that request met the installer's own lock (added in 1.2.43), which sends
  every request on an installed site to the home page. People landed on the
  home page with no confirmation and no link to the admin. The lock now lets
  one GET of `?step=done` through for the browser session that has just
  installed, for 30 minutes; any other request, and every POST, still goes to
  the home page.
- **BH-087: a missing GD blocked the install.** GD showed "recommended" but
  every failed row blocked Continue. Each check is now required or
  recommended: recommended rows (GD, cURL, `content/` writable, `proc_open`)
  are amber and don't block. Every failed row says how to fix it.
- **BH-088: "17 tables will be created".** The count and the list were
  hardcoded; a 1.2.97 install creates 28. The confirm-only schema step is
  gone (see below). The retry-safe drop list now also names
  `idempotency_keys`, `post_views` and `rate_limits`.
- **BH-089: the site address suggestion ignored proxies.** It read
  `$_SERVER['HTTPS']`, missed by BH-084, so behind Cloudflare it suggested
  `http://`. It now uses `Request::serverIsSecure()`, and the page replaces it
  with the address bar's own origin, which is right behind any proxy.

### The new flow

Four screens instead of five:

1. **Check.** Required and recommended items, with a fix for each failure.
2. **Database.** Name, user and password, with host and port folded away
   (`localhost:3306`). The connection is tested before going on: the server
   version (MySQL 5.7+, MariaDB 10.3+) and whether the user can create
   tables (a probe table is created and dropped). Errors are explained in
   words a site owner can act on: wrong user or password (1045), no access or
   no such database (1044, 1049, 1142), no server answering (2002, 2003,
   2005, 2006), with the server's own message under "Technical details".
3. **Your site.** Site name, tagline (new; was hardcoded to "A Modern
   API-First CMS"), address, timezone (new; preselected from the browser and
   saved to `general.timezone`), the first account, and a switch for the
   starter content (now really optional). Submitting runs the whole install:
   tables, account, content, menus, settings, and `.env` last, so a failure
   at any point leaves nothing locked and can simply be retried.
4. **Done.** Sign in, view the site, and four first steps (write, customize,
   email, invite).

Also:

- **Basehim's logo** replaces the rocket icon. The installer has its own
  stylesheet (`admin/assets/css/installer.css`) and core's inline SVG icons;
  it no longer loads Font Awesome from cdnjs or the admin's Tailwind file, so
  it looks right offline and behind strict firewalls.
- **Form tokens** on both forms (session-bound); an expired form says so.
- `Cache-Control: no-store`, `X-Frame-Options: DENY`, `nosniff`, a
  `noindex` robots tag, and its own session cookie (`BASEHIM_INSTALL`,
  HttpOnly, SameSite=Lax, Secure on HTTPS).
- Field-level validation with messages under each field; the username
  follows the admin's rule (3–60 letters, digits, `.`, `-`, `_`); the
  password must be 8+ characters and not the username. A strength meter
  guides without blocking.
- Show/hide on password fields, one submit per click with a busy state,
  phone layout (16 px inputs, 44 px+ targets, full-width buttons).
- `.env` values that can hold anything (title, database name, user,
  password) are quoted and stripped of line breaks. Lines a host already put
  in `.env` (`TRUSTED_PROXIES`, `MAIL_*` …) are kept; only the installer's
  own keys are replaced. The file is written with `LOCK_EX` and mode 0640;
  a failure to write it is reported instead of being ignored.
- UUIDs from `random_bytes()` instead of `mt_rand()`.
- Old `?step=1…5` links still land on the right screen.

### Starter content

- The welcome post explains what to try first (write, customize, the About
  page, apps), without repeating its title as a heading; its excerpt names
  the site.
- The About page has no `<h1>` of its own (the theme prints the title).
- The primary menu (created empty by migration 001) gets **Home** and, with
  starter content, **About**.
- The tagline is what was entered, or empty.

## Default theme

- **Home page:** the newest post leads on page one as a wide card (image or a
  lettered placeholder beside the title and excerpt), with the rest in a grid
  under "More posts". A new site with one post no longer looks like a lone
  card. Posts without an image get a soft lettered placeholder instead of a
  large blue block with a newspaper icon. The whole card is the link.
- **Header:** the site name shows on phones too (only the tagline waits for
  wider screens). The search icon was squeezed to a dot by padding on the SVG.
  The menu button is 44 px, labelled and reports `aria-expanded`. The menu's
  6-unit gap now applies (core's menu styles, printed later, reset it to
  4 px, so "Home About" ran together).
- **404:** the icon was off-centre at a quarter of its box, and "Page not
  found" appeared twice. Now centred, said once, with a search form and a
  link home.
- **Footer:** "Connect" with a single RSS square becomes "Follow" with
  "Subscribe by RSS" and a search box; with no footer menu, Explore lists the
  primary menu.
- **Post:** the comments heading icon is sized to the heading.
- New `assets/theme.css` holds these components as plain CSS (no build step),
  plus three utilities the templates used but the compiled file lacked
  (`mt-4`, `min-w-0`, `bg-slate-200`): the comment thread's reply spacing and
  avatar background were missing.

## Core

- `bh_author_box()`: one post read "View all 1 posts". New `link_text_one`
  argument, default "View their post".

## Files

- `install.php` (rewritten), `admin/assets/css/installer.css` (new)
- `content/themes/default/templates/{index,404,single}.php`,
  `templates/partials/{header,footer}.php`, `assets/theme.css` (new)
- `bootstrap.php` (`bh_author_box`)
- `README.md` (install steps), `index.php` (1.2.98), `CHANGELOG.md`
- `tests/Integration/Release1298Test.php` (new)

## Tests

`Release1298Test` runs the real installer: `install.php` copied into a
scratch site, served by `php -S`, against its own database. It covers the
check screen (logo, no CDN, GD recommended), the form token, three database
errors, field errors with nothing written, a full install, the done screen
for that browser only (not another, not a POST), the lock afterwards, the
`.env` contents read back by core's parser (quotes, kept host lines), and
the database (role, tagline, timezone, sender, starter posts, About without
`<h1>`, the Home/About menu, every migration recorded once). A second test
checks there's no raw `$_SERVER['HTTPS']` or CDN in the installer, and a
third the default theme's home page, 404 and author link.

MariaDB 11.4: 683 passed, 2 failed (BH-047, unchanged).
