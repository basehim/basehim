# Basehim 1.2.46

One new piece of core, the **AJAX gateway**, and a **security fix for the
media library**. It also fixes nine problems found while testing the gateway
before release.

## AJAX gateway

Browser code now has one endpoint, `POST /ajax`, for everything it asks the
server. A page names a registered *action*; core runs the same checks on every
request before the action's code does:

- signed in (the same session check as the admin), unless the action is public
- the CSRF token, in the `X-CSRF-Token` header
- not sent from another site (`Sec-Fetch-Site` / `Origin`)
- the user's capability
- a rate limit, per user or per address, and a global one per address
- the input, against a declared schema: types are strict and undeclared fields
  are refused, so a request cannot slip in `author_id` or `role`
- an optional idempotency key, so a double click runs once

Answers always use the same JSON shape with a request ID. An unexpected error
is logged with that ID and answered with a plain message, never a stack trace.

- **Apps** register actions from `boot()` with `$this->ajax('name', [...])`,
  under their own slug. **Themes** and apps load the browser client on public
  pages with `bh_ajax_client()` / `$this->useAjaxClient()`.
- **JavaScript:** `Basehim.ajax.request(action, data)` is on every admin page.
  It handles the token, JSON, errors and timeouts, and fetches a fresh token
  once if the page's one went stale.
- New database table `rate_limits` (migration 014; also created on first use).
- New `.env` settings, all optional: `AJAX_MAX_BODY`, `AJAX_RATE_LIMIT`,
  `AJAX_RATE_LIMIT_FAIL`, `TRUSTED_PROXIES`.
- Documentation: `docs/APP-AJAX.md` (new), plus sections in APP-DEVELOPMENT,
  ADMIN-UI and THEME-DEVELOPMENT.

The media library and the media picker now save through the gateway
(`media.update`). The old `/admin/media/{id}/update` URL still works for
anything that calls it.

## Security

- **Media ownership.** The admin media screens only checked that a user could
  open the media library. An author could rename, re-caption or delete an
  administrator's files. Now:
  - **Editing** title, alt text, caption or description of someone else's
    file needs `delete_media` or `edit_others_posts`. Editors can still fix
    alt text on any image; authors can edit only their own.
  - **Deleting** someone else's file needs `delete_media`, as the REST API
    already required.
  The same rules apply in the admin, the gateway and the REST API.
- **CSRF token rotation.** The token is replaced at sign-in and sign-out. It
  used to carry over from the signed-out visitor into the signed-in session.
  A tab left open across a sign-in has to reload, or the AJAX client fetches
  the new token by itself.

## Fixed during testing (before release)

| # | Problem | Fix |
|---|---|---|
| 37 | Any request with an `Authorization: Basic` header was refused, so every AJAX call failed on a site behind an HTTP password (a protected staging copy) | Basic is ignored; Bearer and other token schemes are still refused |
| 38 | Editors could no longer edit alt text or captions on other people's images (the new rule required `delete_media`) | Separate rules for editing and deleting (above) |
| 39 | Bulk delete silently skipped files the user may not delete; a refused single delete said only "Delete failed" | The library says how many were not deleted and why, or shows the server's reason |
| 40 | `tests/` would have shipped to live sites, where Apache/LiteSpeed run its scripts directly | Not included in the update; the runner refuses to start outside the command line |
| 41 | `OPTIONS` and `HEAD` on `/ajax` got the HTML 404 page; an app hooked into "not found" could replace the gateway's JSON 404 | The gateway answers every method (405 JSON); its 404 is never offered to `route.miss` |
| 42 | A number field with a word list (`number\|in:low,high`) was accepted at registration and crashed with a 500 on the first request | Refused at registration |
| 43 | Invalid UTF-8 in a GET value passed validation and could break the JSON answer | Refused with a field error |
| 44 | A body of `[]` was answered "Name an action." | "The request body must be a JSON object." |
| 45 | `$ctx->user()` handed apps the account's security record (`meta.security`) | Removed, like the password hash |

## For app developers

`App\Core\App` gains two methods, `ajax()` and `useAjaxClient()`. An app that
already defines a method with either name, with a different signature, will
stop loading until it is renamed. None of the apps on the Basehim sites do.

## Files

New: `app/Core/Ajax/*`, `app/Http/Controllers/Ajax/*`,
`app/Services/RateLimiter.php`, `routes/ajax.php`, `config/ajax.php`,
`admin/assets/js/ajax.js`, `admin/views/partials/ajax-client.php`,
`database/migrations/014_rate_limits.sql`, `docs/APP-AJAX.md`,
`docs/APP-DEVELOPMENT.md`, `docs/ADMIN-UI.md`.
Changed: `index.php`, `install.php`, `bootstrap.php`, `app/Core/{App,Application,Request,Router,Session}.php`,
`app/Services/{AppService,AuthService,MediaService}.php`, the admin and API
media controllers, `admin/assets/js/{media,media-library}.js`, both admin
layouts, `docs/THEME-DEVELOPMENT.md`, `CHANGELOG.md`.
