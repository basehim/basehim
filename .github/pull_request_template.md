## What this changes

<!-- A sentence or two. Link the issue if there is one: Fixes #123 -->

## Why

<!-- The reasoning behind the approach, especially if a simpler one was rejected. -->

## How it was tested

<!-- There is no automated suite yet, so describe what you exercised manually:
     PHP version, database, and the specific flows you clicked through. -->

- PHP version:
- Database:
- Tested flows:

## Checklist

- [ ] `find . -name "*.php" -not -path "./vendor/*" -print0 | xargs -0 -n1 php -l` passes
- [ ] No new Composer, npm, or daemon dependency
- [ ] Works on PHP 8.1 (no 8.2+ syntax in core)
- [ ] Any new table or column honours `DB_PREFIX`
- [ ] New migrations are added as a new numbered file, not edits to a shipped one
- [ ] Docs and `CHANGELOG.md` updated if behaviour changed
- [ ] No credentials, `.env` contents, or personal data in the diff
