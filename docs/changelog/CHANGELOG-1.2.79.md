# Basehim 1.2.79: the route table no longer slows every request (BH-034)

## What was wrong

On every request the router builds its table of routes (`Router::table()`)
before anything else runs. For each app route it compared the route with every
core route and every app route accepted before it, working out each side's
"shape" with a regex every time and compiling a regex for fixed paths. On a site
with about 300 core and 800 app routes (28 apps) that is roughly a million
regex calls per request: measured on the live site at 2.0–4.2 s on every page,
admin screen, AJAX call and worker heartbeat, while the 28 apps themselves
started in about 0.1 s. Found and analysed by a teammate on 21 October 2026.

Registering routes was quadratic too, on a smaller scale: each new route was
checked against every route before it (`isRepeat()`), and each named route
scanned them all again (`hasName()`).

## The fix (`app/Core/Router.php`)

- `table()` checks each app route by lookup: core routes are indexed by method
  and shape, core routes with parameters by their first segment, accepted app
  routes by method and shape; each pattern's shape and regex are worked out
  once. Same routes, same order, same refusal messages.
- One case the proposed patch would have missed: a core route with a
  parameter *inside* its first segment, such as 1.2.76's `/sitemap-{name}.xml`,
  can match a fixed app path whose first segment differs
  (`/sitemap-shop.xml`). Such core routes are always checked, so that app route
  is still refused as before.
- `compile()`: the regex for a pattern, built once per request and shared by
  `match()` (which also used to rebuild it for every path it tried).
- `isRepeat()` and `hasName()` use sets kept beside the route list, rebuilt
  when `forgetOwner()` removes an app's routes.

## Checked

- Old and new `table()` compared on 4,000 random route sets (literal,
  parameter, optional, wildcard and mixed segments, core catch-alls, HEAD,
  `replace`, duplicates across apps, reserved prefixes): identical routes,
  order and refusals in every case.
- 1,102 routes in the live site's proportions: 338 ms before, 12 ms after,
  identical tables. (Locally the teammate measured 520–620 ms before and
  17–22 ms with the proposed patch on the site's real 1,094 routes.)
- `tests/Integration/RouterTableTest.php` (3): speed at that size, the
  mixed-segment conflict, repeats and names after `forgetOwner()`. Full suite
  on Dolt: 368 passed, 0 failed, 4 skipped.

No change for apps or themes.
