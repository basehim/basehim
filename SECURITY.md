# Security Policy

## Supported versions

Basehim is developed on a rolling release. Security fixes land on the latest
`1.x` release; older versions are not backported.

| Version | Supported          |
| ------- | ------------------ |
| 1.0.x   | ✅ Yes             |
| < 1.44  | ❌ Upgrade first   |

## Reporting a vulnerability

**Please do not open a public issue for security problems.**

Report privately through GitHub's
[Security Advisories](https://docs.github.com/en/code-security/security-advisories/guidance-on-reporting-and-writing-information-about-vulnerabilities/privately-reporting-a-security-vulnerability)
("Report a vulnerability" on the Security tab), or email the maintainer.

Useful things to include:

- The affected version and PHP version
- A description of the issue and its impact
- Steps to reproduce, ideally with a minimal proof of concept
- Any suggested fix

You can expect an acknowledgement within a few days. Once a fix is ready it
will ship in a patch release and you will be credited in the advisory unless
you prefer otherwise.

## Scope

In scope: core (`app/`, `admin/`, `api/`, `routes/`, `config/`), the installer,
the update system, and bundled apps and themes under `content/`.

Out of scope: third-party apps or themes not shipped with core, vulnerabilities
that require an already-compromised server or existing super-admin access, and
issues arising purely from misconfigured hosting (world-readable `.env`,
`storage/` served over HTTP, etc.).

## Hardening checklist for operators

Basehim ships secure defaults, but self-hosting means a few things are on you:

- **Delete `install.php`** once installation completes. The installer reminds
  you, but it is worth double-checking.
- **Set a real `JWT_SECRET`** — at least 32 random characters. Generate one
  with `php -r "echo bin2hex(random_bytes(32)) . PHP_EOL;"`.
- **Set a real `APP_KEY`** the same way.
- **Keep `APP_DEBUG=false`** in production. Debug mode exposes stack traces
  containing file paths and query fragments.
- **Never commit your `.env`.** It is git-ignored by default; keep it that way.
- **Confirm the `.htaccess` rules are active.** They deny direct HTTP access to
  `.env`, `storage/`, and `content/apps/*/src/`. If you run nginx instead of
  Apache you must replicate these denials yourself — nothing else enforces them.
- **`chmod` `storage/` and `content/` to 775, not 777.**
- **Serve over HTTPS.** Session cookies and JWTs are only as private as the
  transport.
- **Rotate credentials if a `.env` ever leaks** — database password, `APP_KEY`,
  and `JWT_SECRET` all at once. Rotating `JWT_SECRET` invalidates every issued
  token, which is the point.
