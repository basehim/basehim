# Basehim 1.2.41

## Login security

Hardening from a brute-force audit of the sign-in page.

### Account-wide brake on distributed guessing

Until now every login brake — the security question, the lockout, the emailed
unlock code — was counted per account **and** per network address. An attacker
spread across many addresses (a botnet) therefore got a fresh allowance of
guesses from each one, and the per-address counters never saw the whole run.

A sign-in now also counts an account's failures across **all** addresses in the
15-minute window. Once they pass a threshold, everyone signing in to that
account must answer the security question — including an address that has not
failed yet, from its very first try. This is a security question, never a lock:
the account owner is only ever asked to solve a sum, never shut out, so a third
party still cannot lock them out by guessing from afar.

- New setting **Authentication → "Account-wide failures before a security
  question"**, default 10 (range 3–100). `account_attempt_limit`.
- Scoped to the account under attack: other accounts from the same address are
  unaffected, and failures older than the window are ignored.

### Cleanups

- Removed `AuthService::attempt()`, a dead, unused credential check that —
  unlike the live `attemptDetailed()` — skipped the timing equaliser and so
  could have leaked which usernames exist if it were ever wired up.
- Corrected the lockout-duration wording in the code and the settings screen:
  the pause grows by a fixed step each round (15, 30, 45 minutes …), it does
  not double.

Files changed: `app/Services/AuthSecurityService.php`,
`app/Services/AuthService.php`,
`app/Http/Controllers/Admin/AuthController.php`,
`app/Http/Controllers/Admin/SettingController.php`,
`admin/views/settings/authorization.php`, `index.php`, `install.php`.
