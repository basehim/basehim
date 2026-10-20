# Basehim 1.2.72

Public site routes for apps: one small API, `$this->router()->site()`, through
core's router, with methods, parameters, names, per-route sign-in,
capabilities, CSRF and rate limits, and pages rendered in the active theme.
Core routes, the admin and other apps' routes are protected by a fixed
precedence order and conflict checks.

No database changes. Installs on 1.2.71 (cumulative from 1.2.67).

## What was there before

- **Public routes already existed:** apps could register them with
  `App::get()`, `post()`, `route()` and `routeGroup()`.
- **But apps boot before `index.php` loads core's route files**, and the
  router returned the first match. So an app's route beat every core route.
  - An app could shadow `/`, `/search`, `/feed` or `/api/v1/…`.
  - An app could register `/admin/anything` with no sign-in at all.
  - An app could register `/{slug}` and answer every page of the site.
- **Nothing reported conflicts** between apps.
- **Wrong methods were never refused:** a path served for another method
  answered 404, not 405.
- **No route options:** there was no way to require sign-in, a capability,
  CSRF or a rate limit per route.
- **No themed pages:** an app could not render a page inside the theme
  without copying core's `RendersTheme` logic.

The routes apps register on the live sites were surveyed first, read-only, on
electronics360.org, across all 28 apps:

- Analytics: `/bh-analytics/*` beacons, GET and POST.
- Apps Migration: `/apps-migration-transfer/{token}`.
- Circuit Design: `/circuit-viewer/{token}`.
- Component Builder: `/parts`, `/parts/{slug}`.
- People: `/careers`, `POST /careers/{slug}/apply`.
- Radar2: `/api/v1/radar2/cron`.
- Tasks Scheduler: a `/tasks` group.

None of them conflicts with a core route under the new rules, and all keep
working. The old helpers deliberately keep their behaviour: no new CSRF
requirement, which the Analytics beacons rely on, and no reserved-prefix rule,
which Radar2's path relies on.

## What changed

1. **`Router::site()` → `App\Core\Routing\SiteRoutes`.**
   - **Calls:** `get/post/put/patch/delete/match(path, handler, options)` and
     `group(attributes, fn)`, returning true or false.
   - **Checked at registration:** the path, its parameters (`{p}`, `{p?}` and
     `{p:*}` last, no repeats), the handler (callables and bound methods now;
     class names when used, so controllers are not loaded on every request),
     and the options.
   - **Options:** `name`, `where`, `auth`, `can`, `csrf`, `rate`,
     `middleware`, `replace`. Unknown options are refused, and
     `csrf => false` cannot be combined with `auth` or `can`.
   - **Refusals** go to the core log and the app's log, once a day.
   - **Owner:** routes are credited to the app whose `boot()` is running;
     outside a boot nothing can be registered.
2. **Route areas and a precedence table** (`Router::table()`).
   - Every route is now `core`, `admin` (an app's `adminGet`/`adminPost`),
     `site` (`site()`) or `legacy` (the older helpers).
   - **Dispatch order:** an app's explicit `replace` of `GET /` or
     `GET /search` first, then core routes with a fixed first segment, then
     app routes most specific first, then core's content catch-alls.
   - **Refused:** a public route under `/admin`; a route whose first segment
     is a parameter; a `site()` route under a reserved prefix; a route core
     already serves (same method and shape, or a fixed path a core route
     matches); a route another app registered first.
   - The same app registering the same route twice is one route.
   - The table is built once per request.
   - `Router::rejected()` lists the refusals, `Router::url()` builds named
     URLs, and `Router::currentRoute()` returns the route being served.
3. **Dispatch.**
   - **400:** a path with `.`/`..` segments or a NUL byte; a `site()`
     parameter with an encoded `/`, a NUL byte or invalid UTF-8.
   - **405:** when the path exists for other methods (core's catch-alls left
     out), with an `Allow` header.
   - **Decoding:** `site()` parameters are URL-decoded; `where` constraints
     narrow segments.
   - **Failures:** an app route that throws is logged in the app's log (route,
     exception class, relative file:line) and answered by core's error page.
4. **`App\Http\Middleware\SiteRouteGuard`** runs in front of every `site()`
   route:
   - rate limit via the existing `RateLimiter` (429 with `Retry-After`);
   - malformed JSON body (400);
   - CSRF on unsafe methods for requests carrying the session cookie: the
     session's token in `X-CSRF-Token` or `_csrf` (403). Cookie-less
     requests are stateless and anonymous;
   - `auth` (401 JSON, or the sign-in page) and `can` (403), with the account
     as `auth.user`;
   - after the handler: `X-Content-Type-Options: nosniff` and a
     `Referrer-Policy` when the app set none.
5. **`App\Core\Http\SitePage`**: `view()`, `html()`, `notFound()`,
   `error()`.
   - Renders an app template in the theme's `templates/app.php`, or
     `page.php`, through `RendersTheme`: header, footer, menus, customizer and
     SEO.
   - A theme can override an app template at
     `templates/apps/{slug}/{template}.php`.
   - Templates get `$e()` for escaping, and `$base`.
   - `layout => false` returns the template alone.
   - A missing template is logged and answers a plain 500.
6. **Admin.** Apps → Report gains a Routes section:
   - the app's routes in use, with method, path, kind, name and checks;
   - the refused ones, with the reason;
   - published pages or posts an app route hides.
7. **`App\Core\App` is unchanged** (only comments). No method was added to the
   base class (BH-014), and `AppBaseClassTest` still passes.

## Files

New:
- `app/Core/Routing/SiteRoutes.php`, `app/Core/Http/SitePage.php`,
  `app/Http/Middleware/SiteRouteGuard.php`,
  `app/Http/Controllers/Web/SitePageController.php`.
- `docs/PUBLIC-ROUTES.md`, `docs/examples/public-routes/` (app.json,
  src/App.php, views/index.php, views/item.php).
- `tests/Integration/PublicRoutesTest.php`.
- `docs/changelog/CHANGELOG-1.2.72.md`, `RELEASE-NOTES-1.2.72.md`.

Changed:
- `app/Core/Router.php`: areas, `site()`, `addSiteRoute()`, `table()`,
  `rejected()`, `url()`, `currentRoute()`, 400/405, parameter decoding and
  `where`, app route failure logging.
- `app/Http/Controllers/Admin/AppController.php`, `admin/views/apps/health.php`:
  the Routes section.
- `app/Core/App.php`: comments only.
- `docs/APP-DEVELOPMENT.md`, `CHANGELOG.md`, `index.php` (1.2.72).

## Compatibility

- **Healthy app routes behave as before**, with the same handlers and
  parameters.
- **Behaviour changes, each logged and shown in the app's report:**
  - an app route that is also a core route no longer wins;
  - a public route under `/admin` and a route starting with a parameter are
    refused.
- **A path served for other methods** now answers 405 instead of 404.
- **Routes are not cached,** so enabling, disabling, installing or updating
  an app takes effect on the next request.

## Tests

`php tests/run.php` with a database (Dolt): 321 passed, 0 failed, 3 skipped.
The skips are the InnoDB-only concurrency tests and the new rate-limit test,
which needs reliable counters. That test also passes on Dolt when forced
(`BASEHIM_TEST_FORCE_INNODB_TESTS=1`): 200, 200, 429.

New (14), through the real front controller unless noted:
- **Methods and parameters:**
  - GET, POST, PUT, PATCH and DELETE, with JSON bodies;
  - parameters, `where`, wildcards and URL-decoding;
  - 400 for an encoded slash, a NUL byte, a dot-dot segment and malformed
    JSON;
  - HEAD, and the security header.
- **Status codes:** 405 with `Allow`; 404 for unknown paths, including a POST.
- **Answer types:** HTML, JSON, redirect, plain text and a custom status.
- **Theme pages** (`SitePage`):
  - in the theme, with the app's title;
  - escaped output;
  - a theme override of the template;
  - a missing template (500, no internals, logged);
  - the theme's 404;
  - `layout => false`.
- **Coexistence:** a page by slug, the home page, search, feed and sitemap,
  app admin routes (sign-in and app access) and the dashboard all work beside
  app routes.
- **Conflicts:** core `/search`, a public `/admin/…` (`site()` and the old
  helper), `/{anything}`, `/api/v1/…`, `/ajax`, the old helper on `/feed`, and
  a duplicate between two apps are all refused and logged. The app's other
  routes still work, and the report shows the reasons.
- **`replace`** takes over `/`, and only while the app is active.
- **Security:**
  - `auth` (302 to sign-in, 401 JSON, 200 signed in);
  - `can` (403 / 200);
  - CSRF: a session without a token gets 403, the header or form token gets
    200, no session gets 200, `csrf => false` gets 200;
  - `csrf => false` with `auth` is refused;
  - old-helper beacons are unaffected.
- **Rate limit:** 200, 200, 429.
- **Failures:**
  - a deactivated app's routes are gone;
  - a broken app's route never answers and the site stays up;
  - a throwing handler gives a safe 500 (HTML and JSON), a missing controller
    gives a safe 500, the app's log has the route, and the app stays active.
- **Registration:** repeated registration is one route; named URLs work; bad
  options, patterns, handlers and methods are refused with reasons.
- **The example app**, as documented, including its CSRF-protected form.
- **In-process:** the precedence order and refusal reasons of `Router::table()`.

Checked in Chromium: the example's pages in the default theme at 1280px and
390px, its form submission, and the report's Routes section.
