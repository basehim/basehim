# Basehim 1.2.103

A security release for the update path: core updates, and apps and themes
installed from the marketplace. It follows an audit of the three parts of the
update supply chain — core's updater, the CloudHim app, and CloudHim's release
pipeline — recorded in `claude/update-supply-chain-security-audit.md` in the
project. Pairs with CloudHim 1.7.0.

Adds one column, `apps.consented_permissions` (migration 023). No other
database changes.

## Package signing — groundwork, inactive in this release

The verification machinery ships now but is **off**: no publisher key is built
in yet, so nothing is required to be signed and every update installs on its
checksum exactly as before. This is deliberate — signing will be switched on in
a later release (by adding a key to `PackageSignature::PUBLISHER_KEYS`), once
there are enough installs to warrant it. Shipping the client code now means
sites already have it when that day comes.

- New `App\Services\PackageSignature`. When a publisher key is present, a
  package (core, app or theme) is checked against it before install, with the
  signature bound to `kind`, `slug`, `version` and the file's SHA-256 so a
  genuine package cannot be replayed as another version, app or kind. With no
  key present, `check()` passes and `required()` is false.
- A developer running their own hub can already turn it on for themselves with
  `UPDATE_TRUSTED_KEYS` in `.env`.
- `tools/basehim-sign.php` makes the key pair and signs packages, on the
  publisher's own computer, never on a server. Not needed until signing is
  switched on.

Everything below is active in this release.

## Core updater (`UpdateService`)

- One outbound path to the hub, `hub()`:
  - the site key travels in an `X-Site-Key` header, not the query string, so it
    stays out of the hub's access logs (the query is still used for hubs older
    than CloudHim 1.7.0);
  - redirects are followed only to the hub's own host and never from https to
    http — previously curl followed up to five redirects anywhere;
  - the package is streamed to disk, never held in memory.
- The checksum is now **required**: a release offered without one is refused
  rather than installed unchecked.
- After the checksum and signature, the version written inside the zip's
  `index.php` must match the version being installed.
- `extractOver()` path handling moved to `entryPath()`, which normalises an
  entry (back-slashes, `.` and empty segments) before the traversal and
  protected-path checks and compares protected paths case-insensitively. Spellings
  like `./.env`, `config//app.php` and `./storage/x` used to slip past the
  protected list and overwrite those files.

## Marketplace (`AppService`, `ThemeService`)

- Apps and themes go through the same `hub()` client (header auth, same-host
  redirects) and are streamed into `storage/updates`, not the shared system
  temp directory.
- Both verify the checksum (now required) and the publisher's signature, and
  both bind the download to the slug that was asked for, so the hub cannot
  answer a request for one item with a different one — including replacing an
  installed theme, or the bundled `default`.
- `installFromZip()` takes an optional expected slug and enforces it.
- Marketplace cards only linkify a real `http(s)` developer/company URL
  (`marketplace.js`).

## App permissions (`PermissionBroker`, `AppService::needsConsent`)

- An upgrade that **adds** a permission is now caught. The old check compared
  the declared list against granted ∪ withheld, which always equals the
  declared list, so it never fired. Consent now records what was declared at
  the time (`consented_permissions`), and any later difference — added or
  removed — needs review.
- An upgrade that **drops the whole permissions array** no longer silently
  makes an app unrestricted: an app stays enforced while its consent baseline
  shows it once declared permissions, until the operator re-approves the empty
  declaration.

## Tests

`tests/Unit/Release12103Test.php`: a real sign-and-verify round trip (tamper,
wrong version/kind, untrusted key all refused; dormant with no key);
`entryPath()` against traversal, absolute and protected paths; and the client,
marketplace and permission changes.
