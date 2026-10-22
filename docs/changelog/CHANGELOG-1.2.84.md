# Basehim 1.2.84: the Marketplace on the AJAX gateway and the core UI

The audit behind this release is in the project notes
(`marketplace-ui-audit.md`): the Marketplace was the last admin screen on
hand-written JSON routes, a stylesheet of its own and a colour of its own.

## What was wrong

- **Endpoints.** Browse, categories and install were six hand-written JSON
  routes (`/admin/{apps,themes}/marketplace/{browse.json,facets.json,install}`),
  and activation used four more (`/admin/apps/{slug}/consent.json`,
  `…/activate`, `…/consent`, `/admin/themes/{slug}/activate`). None had an
  input schema, a rate limit or the gateway's error shape.
- **Client.** `marketplace.js` used raw `fetch()` with its own CSRF token from
  a data attribute. A stale token was never refreshed, and an HTML answer (a
  signed-out session, a 403 page) broke `r.json()`, so every problem read
  "Network error".
- **Permissions.** The page needs `manage_apps`, the theme endpoints
  `manage_themes`: a role with only the first saw a Themes tab whose every
  request failed. (A role with only `manage_themes` still can't open the
  page; BH-038, to discuss.)
- **Styles.** `admin/assets/css/marketplace.css` (161 lines, 68 fixed colours,
  a teal accent unrelated to the admin's) duplicated buttons, tabs, chips,
  fields and a skeleton; the details window carried an inline `<style>`
  block (16 more colours); the script wrote colour utilities into alerts and
  badges.
- **Icons.** `icons.js` lacked `users`, `circle-stack`, `shield-check`,
  `arrow-left`, `arrow-right` (and others the page now uses), so they drew
  the question-mark fallback; `BasehimIcon.has()` answered true for any name
  because it tested the fallback (BH-040).

## The fix

- **Gateway actions** (`routes/ajax.php`, `App\Http\Controllers\Ajax\MarketplaceActions`):
  `apps.market_browse`, `apps.market_facets`, `apps.market_install`,
  `apps.market_consent`, `apps.market_activate`, `themes.market_browse`,
  `themes.market_facets`, `themes.market_install`, `themes.market_activate`.
  Each is gated like the screen it stands in for (`/admin/apps` →
  `manage_apps`, `/admin/themes` → `manage_themes`), checks its input (sort
  values, slug pattern, page range, permission keys) and is rate limited.
  Failures are error codes: `NOT_CONNECTED` (503), `MARKETPLACE_ERROR` (502),
  `INSTALL_FAILED` and `ACTIVATION_FAILED` (422), `NOT_FOUND`. The old routes
  remain for older callers. Documented in `docs/APP-AJAX.md`.
- **Client.** `marketplace.js` uses `Basehim.ajax.request` (token in a
  header, refreshed once on `CSRF_INVALID`) and shows the error's message.
  Success and failure are toasts; busy buttons use `bhBusy()`.
- **UI.** The page is built from core components and new `.bh-mk-*` classes
  in `tailwind.css`: the dashboard's header panel, a toolbar card with the
  Apps/Themes tabs, search and sort, category chips (hidden when the hub has
  none), a featured card whose artwork glows in its own colours, and a grid
  of cards (4 a row from 1280 px, 3 from 768 px, 2 on phones; themes 1 on
  phones). App cards put a 56 px icon beside the name (on top on phones).
  Loading is the shimmer skeleton in the cards' shape; empty and failed
  states use `.bh-empty` with Clear filters / Try again / Open Updates.
- **Details window.** Its styles moved to `tailwind.css` (`.bh-im*`); its
  buttons are `bh-btn`. Consent lists each permission as a switch with a
  risk badge; on phones the actions fill the footer.
- **Removed.** `admin/assets/css/marketplace.css`, the inline modal styles,
  and the unreachable `admin/views/apps/marketplace.php`,
  `admin/views/themes/marketplace.php` with their controller methods (their
  addresses redirect to `/admin/marketplace`).
- **Themes tab** shown only to a role with `manage_themes`
  (`MarketplaceController`).
- **Icons.** The missing icons copied from the PHP set; `BasehimIcon.has()`
  true only for icons the set has.

## Files

- `index.php` (version)
- `routes/ajax.php`
- `app/Http/Controllers/Ajax/MarketplaceActions.php` (new)
- `app/Http/Controllers/Admin/MarketplaceController.php`, `AppController.php`, `ThemeController.php`
- `admin/views/partials/marketplace.php`, `admin/views/partials/item-modal.php`, `admin/views/marketplace/index.php`
- `admin/assets/js/marketplace.js`, `admin/assets/js/icons.js`
- `admin/assets/css/src/tailwind.css`, `admin/assets/css/tailwind.min.css`
- `docs/APP-AJAX.md`, `docs/ADMIN-UI.md`, `CHANGELOG.md`, this file and the release notes
- `tests/Integration/MarketplaceUiTest.php` (new)

Removed: `admin/assets/css/marketplace.css`,
`admin/views/apps/marketplace.php`, `admin/views/themes/marketplace.php`.
Nothing loads them any more, so copies left on an updated site are unused
and can be deleted.

## Tests

`tests/Integration/MarketplaceUiTest.php`: read actions answer GET only and
every marketplace action is rate limited; editor, author and subscriber get
`FORBIDDEN`; bad sort, a path in the slug and a missing slug are refused
before anything is downloaded; consent and activation of a missing item are
`NOT_FOUND`; the page has no stylesheet, inline styles or fixed colours of
its own; the script uses `Basehim.ajax` and core classes only; every icon the
script draws exists in `icons.js`; the details window is styled by the core
sheet.

Checked by hand against a local copy of the CloudHim catalogue (6 apps, no
themes yet): browse, categories, search with no results, details, install
(success and a failed download), activate with and without consent, the
loading, empty and error states, at 1440, 820 and 390 px.
