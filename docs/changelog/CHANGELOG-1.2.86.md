# Basehim 1.2.86: no half-drawn app page ahead of the answer (BH-045)

## What was wrong

On cloudhim.com, Backup & Restore with Direct database access withheld opened
without the admin layout instead of the 1.2.85 permission screen. Its log
showed the permission screen had been chosen
(`AppPermissionMissing … db()`), but the app's own view had already drawn
part of the page when its data table called `db()`. `App::view()` started an
output buffer, `include`d the view and returned `ob_get_clean()` — with no
`try`, so the exception left the buffer open. The router then emitted the
permission page into that buffer, and PHP flushed both at the end: the
half-drawn app markup first, then the full page, which broke the layout. The
1.2.85 tests used an app that failed before drawing anything.

## The fix

- `Router`: the output-buffer level is recorded before an app or core route's
  handler runs; on any exception, buffers above it are discarded before the
  permission page or the error page answers.
- `App::view()` ends its buffer when the view throws, as core's `View` does.

## Files

`app/Core/Router.php`, `app/Core/App.php`, `index.php`, `CHANGELOG.md`, this
file and the release notes, `tests/Integration/AppsPageTest.php`.

## Tests

`AppsPageTest`: "a screen that fails half-way through its view still gets the
whole permission page" — an app whose admin view prints, then calls `db()`
without `db.raw`; the answer is the permission page, starting with the admin
layout, with none of the partial view. It fails on 1.2.85.
