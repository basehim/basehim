# Basehim 1.2.53: installs and updates work on MySQL again

**Migration 006 failed on MySQL.** It ended with
`ALTER TABLE … ADD COLUMN IF NOT EXISTS`, which only MariaDB accepts. On
MySQL 5.7 and 8.0 that is a syntax error, and it had three effects:

- **Fresh installs on MySQL** stopped at the database step with "Schema
  install failed".
- **Sites on MySQL that were updated** hit the error at 006 on every
  migration run, so 006 and every migration after it stayed pending.
- **CI** runs the test suite on MySQL 8.0, so its test job failed at setup on
  every push. That is the red CI badge on the README.

MariaDB never noticed. The local development servers run MariaDB, which is
why this went unseen until CI ran the suite on MySQL (audit batch 1).

## Fix

- `database/migrations/006_otp_attempts.sql` adds the column the portable
  way that migrations 007, 009 and 011 already use: an `information_schema`
  check, then `PREPARE`/`EXECUTE`. Sites that already applied 006 do not run
  it again. A site where 006 failed applies it, and then the migrations after
  it, on its next run (Updates, or System → Run migrations).
- `AuthSecurityService` repaired the same column at runtime with the same
  MariaDB-only statement inside a silent `try`. On MySQL it did nothing. It
  now checks the column first.

## CI

- Each failing test is now reported as an annotation on the run's summary
  page, with the test's name and the reason. Reading the log is no longer
  needed.
- `actions/checkout` moves from v4 to v5, which removes the Node 20
  deprecation warning on every job.
- The workflow, run on MySQL 8.0 with PHP 8.1 and 8.3, now passes: all 200
  tests on both.

No other changes.

## Files

Changed: `database/migrations/006_otp_attempts.sql`,
`app/Services/AuthSecurityService.php`, `CHANGELOG.md`, `index.php`,
`install.php`. Repository only: `.github/workflows/ci.yml`.
