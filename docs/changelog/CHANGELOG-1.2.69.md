# Basehim 1.2.69

Replaces 1.2.68, which was withdrawn. Contains everything in
[1.2.68](CHANGELOG-1.2.68.md) — the dashboard widget framework and the
dashboard security fix — plus the fix below. Install it on 1.2.67 directly; it
also repairs a site that installed 1.2.68.

## The fix: 1.2.68 took down sites running the Analytics app

1.2.68 added a method, `registerDashboardWidget()`, to the `App` base class
that every app extends. The Analytics app already had its own **private**
method with that name. PHP refuses to load a class that makes an inherited
method less visible than its parent:

```
PHP Fatal error: Access level to Basehim\Analytics\App::registerDashboardWidget()
must be protected (as in class App\Core\App) or weaker
in content/apps/analytics/src/App.php on line 3711
```

Active apps load on every request, so every page, the admin and the MCP
endpoint failed (cloudhim.com, 2026-10-20 01:00 UTC; issues.md BH-014).

1.2.69 adds **no** method to `App`. Dashboard cards are registered with the
existing `registerWidget()` and `'surfaces' => ['dashboard']`; the docs,
README and example app say so. An app that has its own
`registerDashboardWidget()` helper — like Analytics — keeps working, and its
cards appear in Add widgets.

### So it can't happen again

`tests/Unit/AppBaseClassTest.php`:

- fails when a public or protected method is added to (or removed from) the
  `App` base class, so a change to what every app inherits is a deliberate,
  reviewed decision;
- loads, in a separate PHP process, an app with a private
  `registerDashboardWidget()`, the exact shape that failed. Run against
  1.2.68's `App.php` it reproduces the production error; against 1.2.69's it
  passes.

Also checked on a local site with such an app active: `/`, `/admin/login`,
`/mcp` and the dashboard return 200.

## Files

Changed from 1.2.68:
- `app/Core/App.php` — `registerDashboardWidget()` removed; a note on why.
- `index.php` — version 1.2.69.
- `docs/DASHBOARD-WIDGETS.md`, `README.md`, `docs/APP-DEVELOPMENT.md`,
  `docs/changelog/CHANGELOG-1.2.68.md`, `docs/examples/dashboard-widgets/src/App.php`
  — register cards with `registerWidget()`.
- `tests/fixtures/apps/ajax-fixture/src/App.php` — the same.
- `tests/Unit/AppBaseClassTest.php` — new.

The package is cumulative: every file changed since 1.2.67.

## Tests

`php tests/run.php`: 272 passed on Dolt 2.5; the one failure is the
Dolt-only FULLTEXT case (BH-009), unrelated.
