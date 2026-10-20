# Basehim 1.2.66

Two fixes. The data table's empty and error states get a large icon, centred
above the message (1). The administration email in Settings → General, the
administrator's own account email and `ADMIN_EMAIL` in `.env` stay in step, and
the account part keeps My Profile's protections (2–5).

No database changes. Installs on 1.2.65.

## What changed

1. **Data table: the empty-state icon is centred and large.** The icon was an
   inline `<svg class="w-4 h-4">` placed straight into `.bh-dt-state`, which
   centres with `text-align`. Tailwind's base styles make every `svg`
   `display: block`, so the icon sat flush left at 16px while the text was
   centred. The first page printed by the server had no icon at all.
   - The state is now a flex column. The icon sits in a 72px soft circle
     (`.bh-dt-state__icon`, a 36px icon), sized by the stylesheet, not by
     utility classes, so admin themes cannot move it.
   - The message says what happened. "Nothing here yet" with an inbox when the
     table is empty; "No matches" with a magnifying glass when a search or
     filter found nothing. The text names the items ("There are no posts to show
     yet.").
   - The error state has the same layout with a red alert icon.
   - The partial prints the same markup as `datatable.js` (`stateHtml()`), so the
     first paint and every reload match.
   - On phones, where the list has no frame (1.2.65), the empty state gets the
     cards' surface.

2. **The administration email is validated.** Settings → General stored
   anything typed into it. A changed address must now be valid, and it is
   lower-cased. An unchanged older value (say, `admin@localhost` on a test
   install) still lets the rest of the screen save. The field is labelled
   **Administration Email**, says what it is for, and links to My Profile for
   the sign-in email.

3. **`.env` follows the setting.** A changed administration email is written
   to `ADMIN_EMAIL` in the `.env` the site loaded (`Env::path()`, new). The
   next General save also repairs a `.env` that fell behind before this
   release. The new `App\Core\EnvFile::set()` is deliberately narrow:
   - It accepts only an UPPER_SNAKE key, and only a value that needs no quoting
     (no spaces, quotes, `#`, `$`, backslashes, backticks or line breaks).
   - It rewrites every line for the key in place, or appends one. Every other
     line is kept byte for byte, including CRLF endings.
   - It writes a temporary file beside `.env` and renames it over, keeping the
     permissions; where the folder is not writable, it writes in place under a
     lock.
   - A missing or read-only `.env` is reported in the save message, not treated
     as an error. The site keeps working: `ADMIN_EMAIL` is only the default used
     before the setting exists.

4. **Settings → account, only with your password.** Copying the setting into
   the account would have let anyone with an open session take the account
   over: password resets and sign-in codes go to that address, which is why My
   Profile asks for the current password (1.2.33). So when the address being
   replaced is your own account's, the form offers **Also change my account
   email** with a **Current password** field. The change goes through
   `AdminEmailService::changeOwnAccount()`, which applies My Profile's rules:
   - the current password is checked;
   - the address must be free;
   - with two-step verification on, a code goes to the new address first and
     the change finishes on My Profile;
   - it is recorded in the activity log, and the old address is told.

   The password is checked before anything is saved, so a wrong one saves
   nothing. The checkbox and password are never stored as settings. The
   `settings.save` response carries `account_email`, and `settings-save.js`
   fires `bh:settings-saved`, so the form updates without a reload.

5. **Account → settings.** `UserService::update()` is the one path every
   account email change takes: My Profile, its code confirmation, Users → Edit
   and the API. When an account's old address was the administration email,
   and the person signed in may change settings (`manage_options`), the
   setting and `.env` follow. An editor changing their own email never moves
   the administration email.

## Files

Changed:
- `admin/assets/css/datatable.css`: the empty and error states; the phone
  surface.
- `admin/assets/js/datatable.js`: `stateHtml()`, `renderEmpty()`,
  `renderError()`, the `alert` icon.
- `admin/views/partials/datatable.php`: the same empty state on the first
  page.
- `admin/views/settings/general.php`: the Administration Email field and the
  account option.
- `admin/assets/js/settings-save.js`: the `bh:settings-saved` event.
- `app/Services/SettingsSaver.php`: validation, the account change, `.env`.
- `app/Http/Controllers/Ajax/SettingsActions.php`: `account_email` in the
  General response.
- `app/Services/UserService.php`: the account → setting step.
- `app/Core/Env.php`: `Env::path()`.
- `CHANGELOG.md`, `index.php` (1.2.66).

New:
- `app/Core/EnvFile.php`, `app/Services/AdminEmailService.php`.
- `tests/Unit/EnvFileTest.php`, `tests/Integration/AdminEmailTest.php`.
- `docs/changelog/CHANGELOG-1.2.66.md`, `RELEASE-NOTES-1.2.66.md`.

## Tests

`php tests/run.php` with a database (Dolt): 253 passed, 2 skipped, 1 failed.
The failure is the pre-existing `editor: a double click … creates one post`
(a Dolt full-text limitation). 1.2.65 gives the same single failure on the
same server.

New:
- `EnvFile`:
  - only the key's line changes, and a missing key is appended;
  - CRLF is kept;
  - duplicate lines agree;
  - values that could add a line or change parsing are refused, as are bad keys;
  - permissions are kept, and a missing file is reported, not created.
- Administration email, through `settings.save` and My Profile:
  - a new address is saved lower-cased and written to the loaded `.env`,
    with the account left alone;
  - an invalid address is refused and nothing is saved;
  - an unchanged older value does not block the screen;
  - the account changes only with the current password; a wrong password saves
    nothing, and the helper fields are never stored;
  - without the checkbox, the account keeps its address;
  - My Profile moves the setting;
  - an editor cannot move it.

Checked in Chromium:
- The empty state, with no posts and with a search that matches nothing, at
  1280px (Table, List and Grid) and 390px. The icon, title and box share one
  centre.
- The Settings form:
  - the account option appears only when your own address is being replaced;
  - the password field follows the checkbox;
  - an in-page save changes the setting, the account and `.env`, and the
    option hides afterwards;
  - My Profile shows the new address.
