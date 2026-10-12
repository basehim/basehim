# Contributing to Basehim

Thanks for taking an interest. Basehim is a deliberately small, dependency-free
PHP CMS, and contributions that keep it that way are very welcome.

## Before you start

- **Bugs and small fixes** — open a pull request directly. No need to ask first.
- **New features, new dependencies, schema changes** — open an issue first so we
  can agree on the shape before you spend time on it.
- **Security issues** — do not open an issue. See [SECURITY.md](SECURITY.md).

## Local setup

Basehim has no build step and no Composer install. You need PHP 8.1+, MySQL 5.7+
(or MariaDB 10.3+), and Apache with `mod_rewrite`.

```bash
git clone https://github.com/<your-username>/basehim.git
cd basehim

cp .env.example .env
# Edit DB_*, APP_URL. Then generate the two secrets:
php -r "echo 'APP_KEY=' . bin2hex(random_bytes(16)) . PHP_EOL;"
php -r "echo 'JWT_SECRET=' . bin2hex(random_bytes(32)) . PHP_EOL;"

chmod -R 775 storage/ content/
```

Point a vhost (or XAMPP/MAMP) at the project root — there is no `public/`
directory by design — then visit the site in a browser and the installer will
take over. Set `APP_DEBUG=true` in `.env` while developing.

To reinstall from scratch, drop the database and delete `.env`.

## Code style

The codebase follows PSR-12 with a few house conventions:

- `declare(strict_types=1);` at the top of every PHP file.
- Four-space indentation, LF line endings (`.gitattributes` enforces this).
- PSR-4 autoloading: `App\` maps to `app/`. Class filename must match the class.
- Classes are `final` unless they are explicitly designed for extension.
- Constructor property promotion and typed properties throughout.
- A short docblock on every class explaining what it is for, and on any method
  whose behaviour is not obvious from the signature. Explain *why*, not *what*.
- Repositories do data access, services do business logic, controllers stay thin.

Check syntax before pushing:

```bash
find . -name "*.php" -not -path "./vendor/*" -print0 | xargs -0 -n1 php -l
```

CI runs this same lint against every supported PHP version.

## Tests

The suite lives in `tests/` and needs nothing beyond PHP — no Composer, no
PHPUnit. See [`tests/README.md`](tests/README.md).

```bash
php tests/run.php                       # unit tests only
BASEHIM_TEST_DB_HOST=127.0.0.1 \
BASEHIM_TEST_DB_USER=root BASEHIM_TEST_DB_PASS=secret \
php tests/run.php                       # unit + integration (needs MySQL/MariaDB)
php tests/run.php --filter=csrf         # only tests whose name contains "csrf"
```

Integration tests create a database named `basehim_test` (it must end in
`_test`), **drop and recreate it**, run the real migrations, and drive the real
`index.php` over HTTP with PHP's built-in server. They never touch your own
database. Node.js 18+ is used for the JavaScript client tests when present;
without it those tests are skipped.

CI runs the whole suite against MySQL 8.0 on every push and pull request.

## Database changes

Migrations live in `database/migrations/` and are plain `.sql` files applied in
filename order. Add a new numbered file — never edit one that has already
shipped, since existing installs have already run it.

Every table name must honour the `DB_PREFIX` setting.

## Apps and themes

If you are building an app or theme rather than changing core, start with the
docs in [`docs/`](docs/):

- [`docs/APP-DEVELOPMENT.md`](docs/APP-DEVELOPMENT.md) — structure and lifecycle
- [`docs/APP-API.md`](docs/APP-API.md) — the helper API available to apps
- [`docs/APP-PERMISSIONS.md`](docs/APP-PERMISSIONS.md) — the permission model
- [`docs/AGENT-API.md`](docs/AGENT-API.md) — the agent/MCP surface

`content/apps/wp-migrator/` is a full-size worked example.

## Pull requests

1. Branch from `main`: `git checkout -b fix/short-description`.
2. Keep the change focused. One concern per PR.
3. Run `php tests/run.php` (with a test database) and add a test for the bug or
   behaviour you changed. Say in the PR description what else you exercised by
   hand.
4. Update the docs if behaviour changed. Release notes are written per version
   in `docs/changelog/` when a release is prepared; see `CHANGELOG.md`.
5. Write a commit message that explains the reasoning, not just the diff.

Commit messages follow a loose conventional style:

```
fix(router): resolve subdirectory base path on HEAD requests
feat(api): add cursor pagination to /api/v1/posts
docs(readme): correct app directory path
```

## Compatibility promises

Basehim runs on cPanel-style shared hosting. That constrains what we can accept:

- **No Composer requirement.** Core must run from a plain file upload.
- **No build step.** No npm, no bundler, no compiled assets in the critical path.
- **No daemons.** No Redis, no queue workers, no cron dependency for core
  functionality.
- **PHP 8.1 is the floor.** Do not use 8.2+ syntax in core.

A PR that breaks any of these will be asked to find another way, however elegant
the code is.
