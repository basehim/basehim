# Basehim 1.2.31 — First run and local development

Patch for 1.2.30. Nothing changes for installed sites.

## The faults

- **A fresh copy answered 500.** Opening the site before installing went
  straight to the database, which is not configured until the installer runs,
  and the visitor got "500 Internal Server Error". The README and the
  installation guide both promised a redirect to the installer; there never was
  one, so `/install.php` had to be known and typed by hand.
- **`php -S` could not run Basehim.** The built-in server reads no `.htaccess`:
  `/admin` (a folder without an index file) answered 404, and `app/`,
  `config/`, `storage/` and `.env.example` were served as plain files. There was
  no way to try Basehim locally without Apache.

## Changes

- `index.php` — when there is no `.env` yet (and no `DB_DATABASE` in the real
  environment), pages redirect to `install.php`. `/api`, `/mcp`, `/oauth` and
  `/.well-known` answer `503` with a JSON message instead of an HTML redirect.
  Keyed to `.env` being absent, never to its contents, so an installed site can
  never be sent to the installer.
- `server.php` (new) — router for `php -S localhost:8000 server.php`: serves
  static files, refuses the same private paths as `.htaccess` (plus every
  dotfile), runs the installer, and sends everything else through `index.php`.
  It answers 404 under any other server, so it is inert on Apache.
- `README.md` — a Quick start (web host, local with `php -S`, git clone, one-line
  MariaDB container), a direct download link, `openssl` in the requirements,
  nginx/LiteSpeed notes, and the installer described as it behaves (it locks
  itself; deleting `install.php` is optional).

## Files

- `index.php`
- `install.php` (version only)
- `server.php` (new)
- `README.md`
- `CHANGELOG-1.2.31.md`, `RELEASE-NOTES-1.2.31.md` (new)
