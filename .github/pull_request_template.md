## What this changes

<!-- A sentence or two. Link the issue if there is one: Fixes #123 -->

## Why

<!-- The reasoning behind the approach, especially if a simpler one was rejected. -->

## How it was tested

<!-- Which tests you added or ran (php tests/run.php), and what you exercised
     by hand: PHP version, database, and the flows you clicked through. -->

- PHP version:
- Database:
- Tested flows:

## Checklist

- [ ] `find . -name "*.php" -not -path "./vendor/*" -print0 | xargs -0 -n1 php -l` passes
- [ ] `php tests/run.php` passes with a test database (and a test covers the change)
- [ ] No new Composer, npm, or daemon dependency
- [ ] Works on PHP 8.1 (no 8.2+ syntax in core)
- [ ] Any new table or column honours `DB_PREFIX`
- [ ] New migrations are added as a new numbered file, not edits to a shipped one
- [ ] Docs and `CHANGELOG.md` updated if behaviour changed
- [ ] No credentials, `.env` contents, or personal data in the diff
