# Basehim 1.2.59

One marketplace for apps and themes, reachable from the sidebar, with an
install-then-activate flow that no longer makes you leave the page — and an
Apps screen rebuilt as a compact card grid.

1. **A single Marketplace at `/admin/marketplace`.** Apps and themes now live on
   one page with an Apps/Themes tab (the tab is driven by `?tab=`, so a themes
   link opens on the themes tab). It is in the sidebar under the System group
   (storefront icon). The old `/admin/apps/marketplace` and
   `/admin/themes/marketplace` URLs 302-redirect to it, so existing links and
   bookmarks keep working. The per-kind `browse.json`, `facets.json` and
   `install` endpoints are unchanged and each keeps its own capability gate.
2. **Install → Activate → permission consent, in place.** After an app installs,
   the button becomes **Activate** instead of sending you back to the Apps page.
   Clicking it loads the app's requested permissions over AJAX; if it opened from
   an app's details window, the permission step loads **into that same window**
   rather than a second pop-up over it (it no longer stacks underneath). The step
   states plainly that the app needs the permissions to work properly, shows each
   one's risk, and lets any be withheld; confirming activates the app with the
   granted set. A theme activates directly. Buttons settle to **Active** / **In
   use**.
3. **Consistent, compiled UI — no inline JS or CSS.** The marketplace's inline
   `<style>`/`<script>` are gone; the view renders only the shell and passes
   config via `data-*` attributes. All styling moved to
   `admin/assets/css/marketplace.css` and behaviour to
   `admin/assets/js/marketplace.js` (both cache-busted with `BASEHIM_VERSION`);
   icons come from the shared `BasehimIcon` helper and the consent modal reuses
   the admin `.bh-modal` / `.bh-btn` components.
4. **Apps screen rebuilt as a compact card grid.** `/admin/apps` now follows the
   CloudHim apps list: a four-tile stat row (installed, active, inactive, need
   attention), then a responsive grid of compact cards — each with an icon tile,
   a status pill, the app's version and author, a two-line description, its
   permissions (granted vs withheld) and the actions for its state. The inline
   `onclick` upload toggle and `onsubmit` confirms were removed into
   `admin/assets/js/apps.js`, which drives the upload panel and routes destructive
   actions through the admin `bhConfirm` dialog via declarative `data-confirm`
   attributes.

## Files

New:
- `app/Http/Controllers/Admin/MarketplaceController.php` — renders the unified
  page; reads the active tab from `?tab=` (apps default).
- `admin/views/marketplace/index.php` — the unified marketplace view (extends the
  admin layout, includes the marketplace partial with the chosen tab).
- `admin/assets/css/marketplace.css` — all `.mk-*` marketplace styles plus the
  activate button and the in-modal consent block (permission rows, risk pills).
- `admin/assets/js/marketplace.js` — browse/search/sort/filter/pager, the
  details modal, and the install → activate → consent flow; the consent step
  renders inside the details modal (`#bh-im`) instead of a separate overlay.
  Config read from `#mk-root` data-attributes, icons via `BasehimIcon`.
- `admin/assets/js/apps.js` — upload-panel toggle and `data-confirm` dialogs for
  the Apps screen (no inline handlers).

Changed:
- `routes/admin.php` — adds `GET /admin/marketplace`; turns the two old
  marketplace GETs into redirects; adds `GET /admin/apps/{slug}/consent.json`.
- `app/Http/Middleware/AdminAreaPolicy.php` — maps `/admin/marketplace` to
  `manage_apps` so the new page is not open to any signed-in user.
- `app/Http/Controllers/Admin/AppController.php` — `activate()` and
  `saveConsent()` answer JSON when the caller sends `Accept: application/json`;
  adds `consentJson()`. The existing full-page flows are untouched.
- `app/Http/Controllers/Admin/ThemeController.php` — `activate()` answers JSON for
  AJAX callers, else the original redirect + flash.
- `admin/views/layouts/app.php` — adds the Marketplace sidebar item (System
  group, `manage_apps`).
- `admin/views/partials/marketplace.php` — emits the shell + asset links only;
  inline style/script removed.
- `admin/views/apps/index.php` — rebuilt as the compact card grid above
  (Tailwind utilities, external `apps.js`); marketplace link points at
  `/admin/marketplace`.
- `admin/views/themes/index.php` — "Browse Marketplace" points at
  `/admin/marketplace?tab=themes`.
- `index.php`, `install.php` — version 1.2.59.

## Tests

`tests/Unit/AdminAreaPolicyTest.php` continues to pass: every authenticated
admin route (the new `/admin/marketplace` included) has a capability rule or is
listed as open. Full unit suite: 92 passing, 0 failing (integration tests need a
database and are skipped here).

The interactive flows (install → Activate → in-modal consent → activate; theme
activate; the redirects; the rebuilt Apps actions) are not covered by an
automated browser test — verify them once on a running install after updating.
