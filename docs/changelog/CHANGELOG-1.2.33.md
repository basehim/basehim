# Basehim 1.2.33 — Authentication

Patch for 1.2.32. A sign-in page that belongs to the site, two-step
verification by email, and fixes for the faults an audit of sign-in, sessions,
password reset, registration and the auth API found.

## New

- **Sign-in screens carry the site's identity.** Sign in, the code screen,
  forgot/reset password, registration and sign out now show the site's logo or
  icon, name, description and address, in the site's primary colour, with a
  link back to the site. Self-contained styles (`admin/assets/css/auth.css`),
  no web fonts, dark mode, `noindex`, no referrer.
- **Two-step verification (email codes).** After the password, a 6-digit code
  is emailed and must be entered to finish signing in.
  - Settings › Authentication (the tab was called Authorization): Off,
    Optional (each person decides in My Profile), Required for administrators,
    Required for everyone; and how long "Don't ask on this device" lasts
    (0–90 days).
  - My Profile › Two-step verification: turn on (confirmed with a code, so a
    wrong address can't lock you out), turn off (needs the password).
  - Codes: valid 10 minutes, single use, 5 wrong guesses burn a code, 10 per
    hour pause code entry for 30 minutes, a new code at most every 30 seconds
    and 6 per hour. Only an HMAC of the code is stored (`auth_two_factor`,
    created automatically).
  - Users › Edit: an administrator can reset it for someone who lost their
    mailbox; under a policy that requires codes, their next sign-in is let
    through once so they can fix their email address.
  - Changing your email while two-step applies needs a code sent to the new
    address.
  - Emergency switch: an empty file `storage/disable-2fa` suspends codes for
    everyone (for a site whose email stopped working).
  - API: `POST /api/v1/auth/login` answers 401 `two_factor_required` and emails
    a code; repeat with `otp`.
- **My Profile › Signed-in devices:** "Sign out everywhere else" ends other
  sessions, remember-me cookies, API refresh tokens, connected AI clients and
  trusted devices. API keys are kept.
- **Locked out by someone else's guessing?** The sign-in page offers the
  account owner an emailed unlock code.

## The faults

- **Redirect loop.** A suspended or deleted account with an open session was
  sent from every admin page to /admin/login, and from there back to the
  dashboard, for ever.
- **Sessions outlived password changes.** Changing or resetting a password
  left every other session, remember-me cookie and API refresh token working,
  so a stolen session survived the owner's fix.
- **No lockout.** After three wrong passwords a math question appeared, and
  that was the only brake: a script that answered it could guess for ever.
- **Reusable captcha.** The answer was signed into the form, so one solved
  question could be replayed for ten minutes, by any client, for any number of
  guesses.
- **Counters never expired.** Three typos once meant a captcha for months.
- **The "unlock code" signed people in.** After repeated failures the code
  emailed to the owner logged the visitor straight in, so access to the
  mailbox alone was enough. It now unlocks sign-in; the password is still
  needed. At most one code a minute is sent.
- **Password-reset link poisoning.** Reset and welcome links were built from
  the request's Host header; a reset requested with a forged Host sent the
  victim a working token pointing at another site. Links now use `APP_URL`
  (the request's host only when `APP_URL` is empty or local).
- **Profile email change without the password.** Anyone with an open session
  could change the account's email and then reset the password. It now needs
  the current password and a unique, valid address, and the old address is
  told. Same for `PATCH /api/v1/me` (`current_password`), and for an
  administrator's own account on the Users screen.
- **API sign-in had no protection at all** — no limit, lock or captcha — and
  **API registration ignored the registration setting**, creating accounts
  with registration turned off. Now: the same per-account lock (429 with
  Retry-After), registration only when allowed, 5 per hour per address.
- **Refresh tokens:** a token could be rotated twice by parallel requests;
  a reused token is now detected and its whole chain revoked; a suspended
  account can no longer refresh.
- **Admins could create super administrators** (`POST /admin/users` did not
  check the role; editing already did).
- **Invalid account status.** "Pending" in the New User form was stored as an
  empty status on MySQL without strict mode; now stored as Inactive. Duplicate
  emails on the Users screen showed an error page instead of a message.
- **Sign-out by any website.** `GET /admin/logout` and a POST without a token
  signed people out (a hidden image was enough); both now show a confirmation.
- **Open redirect.** A failed form check redirected to whatever the Referer
  header said.
- **Admin email published.** `GET /api/v1/settings/public` returned the whole
  General group, including the administrator's email address — usually the
  sign-in name.
- **API keys worked in the admin panel** with all of their owner's rights,
  whatever scopes the key had. Bearer credentials are now accepted on the API
  only.
- **Unguessable secret.** Without `APP_KEY`, captcha tokens were signed with a
  public value. A per-site random secret is generated and kept instead.
- Authentication settings are validated on save (only known keys, numbers
  clamped, no administrator role for registration).

Sessions opened before this update are kept (and protected from now on); to
end them, use "Sign out everywhere else".

## Files

- `index.php`, `install.php` (version only)
- `app/Services/TwoFactorService.php` (new)
- `app/Services/AuthService.php`, `AuthSecurityService.php`, `UserService.php`, `SettingService.php`
- `app/Http/Middleware/Authenticate.php`
- `app/Http/Controllers/Controller.php`
- `app/Http/Controllers/Admin/AuthController.php`, `ProfileController.php`, `SettingController.php`, `UserController.php`
- `app/Http/Controllers/Api/AuthController.php`, `OAuthController.php`
- `app/Http/Controllers/Web/RendersTheme.php`
- `routes/admin.php`
- `admin/assets/css/auth.css` (new)
- `admin/views/layouts/signin.php` (new), `admin/views/layouts/app.php`
- `admin/views/auth/login.php`, `otp.php`, `forgot.php`, `reset.php`, `register.php`, `logout.php` (new)
- `admin/views/profile/index.php`, `admin/views/users/edit.php`
- `admin/views/settings/authorization.php`, `admin/views/settings/_nav.php`
- `admin/views/api/reference.php`
- `CHANGELOG-1.2.33.md`, `RELEASE-NOTES-1.2.33.md` (new)
