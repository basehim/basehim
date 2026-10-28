# Basehim 1.2.90

2026-10-28 · Patch release · installs on 1.2.89

Security headers, one trusted-proxy rule for all of core, an SSRF guard for
`api()->http()`, and the core issues found while building the Public Tools
and Website Diagnostics apps (BH-049 to BH-069).

## Security headers (Settings → Security)

**Problem.** A security-header check of a live site reported: no
Strict-Transport-Security, no Content-Security-Policy, the obsolete
`X-XSS-Protection: 1; mode=block`, and `X-Powered-By: PHP/8.5.12`, which tells
anyone the exact PHP version. Core set its few headers only in `.htaccess`, so
nginx sites got none, and none were configurable.

**Fix.** New `App\Services\SecurityHeaders`, applied to every response in
`Router::emit()`. A header the response already carries always wins.

| Header | Value |
|---|---|
| `X-Powered-By` | `Basehim` (PHP's own header is removed) |
| `X-XSS-Protection` | `0` |
| `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy` | as `.htaccess` already sets them, now from PHP too |
| `Strict-Transport-Security` | HTTPS responses only, not for IPs or localhost: `max-age` 6 months / 1 year (default) / 2 years, optional `includeSubDomains` and `preload` |
| `Content-Security-Policy` | HTML only. Baseline, always: `object-src 'none'; base-uri 'self'; frame-ancestors 'self'` (+ `upgrade-insecure-requests` on HTTPS). Public pages add `script-src 'nonce-…' 'strict-dynamic' https: 'unsafe-inline'; report-uri /csp-report`, report-only (default) or enforced; or no CSP at all ("Off") |

The script policy is the "strict CSP" pattern. Every executable `<script>`
element in the page gets this response's nonce, added when the response is
sent (`SecurityHeaders::addNonces()`). Data blocks such as JSON-LD and
elements that already have a nonce are left alone. `'strict-dynamic'` trusts
what those scripts load, so themes, apps, analytics and ads keep working. Under
Enforce it blocks inline event handlers, `javascript:` URLs, and scripts
injected after load; `https:` and `'unsafe-inline'` only serve browsers
without nonce support. The admin gets the baseline only, because app screens
use inline handlers. Nonces are added at send time, so cached response bodies
never carry a stale one.

**Reports.** New route `POST /csp-report` (`CspReportController`):
`application/csp-report` or JSON, at most 16 KB, 30 per visitor per minute,
answers 204. Reports are grouped by directive, blocked URL (query string
removed), page path, source file and line, with a count. At most 100 groups
are kept, in `storage/security/csp-reports.json`. Nothing identifying the
visitor is stored. They are listed in Settings → Security, with a Clear
button.

**`.htaccess`.** The updater never rewrites `.htaccess`, and Apache and
LiteSpeed apply its `Header set` after PHP's, so the old line would override
the new value. The first admin GET after the update runs
`SecurityHeaders::upgradeHtaccess()`. It replaces exactly
`Header set X-XSS-Protection "1; mode=block"` with `… "0"` (atomic write,
permissions kept, nothing else touched) and records the outcome in the
`security.htaccess_xss` setting. If the file isn't writable, Settings →
Security says how to change the line by hand. The shipped `.htaccess` template
has the new line.

Files:
- new: `app/Services/SecurityHeaders.php`,
  `app/Http/Controllers/Web/CspReportController.php`,
  `admin/views/settings/security.php`;
- changed: `app/Core/Router.php`, `app/Core/ErrorHandler.php` (error pages answered
  outside the router get the same plain headers), `app/Services/SettingsSaver.php`
  (`security()`: refuses unknown modes and durations; preload only with
  subdomains and at least a year), `app/Http/Controllers/Admin/SettingController.php`,
  `admin/views/settings/_sections.php`, `routes/admin.php`, `routes/web.php`,
  `.htaccess`.

## One trusted-proxy rule (BH-061, BH-065)

**Problem.** `Request::ip()` takes the first `CF-Connecting-IP`,
`X-Forwarded-For` or `X-Real-IP` value from anyone. `SiteRouteGuard` (every
app site route's `rate`), `SearchThrottle` and the `Idempotency` middleware
keyed visitors by it. Searching 70 times with a different `X-Forwarded-For`
each time got 1 refusal instead of 40. Separately, eight places (SEO head,
feed, sitemap, robots/llms, MCP OAuth issuer, sign-in links, system info,
CORS origins) believed `X-Forwarded-Proto` from any visitor, so a request
could flip the scheme of absolute URLs, including in cached feeds and
sitemaps.

**Fix.** `Request` gains:

- `trustedProxies()`: `TRUSTED_PROXIES` from .env, as the AJAX gateway always
  used.
- `visitorIp()`: `X-Forwarded-For` only from those proxies, and
  `CF-Connecting-IP` only when the connection comes from Cloudflare's published
  ranges (`Request::CLOUDFLARE`; Cloudflare sets that header itself). Its
  `X-Forwarded-For` is still not believed: a Cloudflare Worker can fetch from
  those ranges with any value.
- `serverIsSecure()` / `isSecure()`: HTTPS directly, `X-Forwarded-Proto` /
  `X-Forwarded-SSL` from trusted proxies, `CF-Visitor` from Cloudflare, or a
  request to the host of an `https://` `APP_URL`. The last covers CDNs that
  aren't listed (QUIC.cloud, say) without believing anything a visitor sends.

`SiteRouteGuard`, `SearchThrottle`, `Idempotency` and the AJAX gateway use
`visitorIp()`; the eight scheme checks use `serverIsSecure()`. `OriginPolicy`
still adds the `https://` origin of the request's own host when
`X-Forwarded-Proto` says so, since that is the site itself and opens nothing.
`ip()` is unchanged and documented as display-only.

Compatibility: a site behind a load balancer or reverse proxy that is not
Cloudflare must list it in `TRUSTED_PROXIES`, as the AJAX gateway already
required. Until then all its visitors share one limit.

## `api()->http()` reaches public addresses only (BH-062)

**Problem.** `HttpApi` let curl follow redirects, never checked what it
connected to, and read whole bodies into memory. An app fetching a URL someone
typed could be pointed at `127.0.0.1`, the hosting network or
`169.254.169.254`, directly, by redirect, or by DNS rebinding.

**Fix.** New `App\Core\Net\AddressPolicy`, the same rules as Public Tools 1.1:
public unicast only, every address a name resolves to must pass, IPv4-mapped,
NAT64, 6to4 and Teredo forms handled. New `App\Core\Net\OutboundGuard`
resolves once and refuses userinfo URLs and numeric host spellings
(`2130706433`, `0x7f.1`). `HttpApi::request()` now:

- follows at most 5 redirects itself, checking each hop;
- pins the checked address with `CURLOPT_RESOLVE` and compares the connected
  address afterwards;
- allows only `http` and `https`;
- turns POST into GET on 301/302/303;
- drops `Authorization` on another host;
- refuses bodies over 20 MB (`maxBytes()`).

Exceptions: `allowPrivateNetwork()` per request, or `HTTP_ALLOW_PRIVATE`
(names, IPs, CIDRs) in .env. No app on the reference site used `http()`;
apps that call intranet services need one of the two.

## Fixes

- **BH-049, app assets cached for days after an update.** `App::asset()`
  appends `?v={app version}`; when the path already has a query string it is
  left as it is. `AppAssetController` sends a month's cache only for
  versioned URLs and `no-cache` (revalidate with ETag) otherwise. Apps that
  add their own `?v=` keep working.
- **BH-050, relative canonical overrides.** `SeoHeadService` makes the
  canonical absolute after overrides and filters (it fed `<link>`, `og:url`
  and the JSON-LD `@id`).
- **BH-051, `/shop/` and `/shop` as two canonicals.** App pages (type
  `other`) drop a trailing slash from their default canonical; the root and
  core page types are unchanged.
- **BH-052, app page titles.** `SitePageController` passed "Title – Site",
  which the title format then wrapped again. It now passes the page's own
  title, so Settings → SEO's format applies once.
- **BH-053, `Icon::has()` was always true.** An unknown name resolved to the
  fallback icon and counted as found.
- **BH-054, sitemaps for apps.** Filter
  `sitemap.index (array $urls, string $origin)`. Only URLs on the site's
  origin are kept, at most 100, without duplicates. They are asked for on each
  request and keyed into the index's cache, so switching an app on or off
  shows at once.
- **BH-055, per-app layouts.** A theme's `templates/app-{slug}.php` is used for
  that app's pages before `templates/app.php`. Both receive `$app_slug`.
  `SitePage::view()` passes the slug.
- **BH-059, dependencies on deactivate and delete.** New
  `AppService::activeDependents()` / `assertNotNeeded()`. Apps → bulk
  Deactivate/Delete and the legacy routes refuse an app that active apps
  require ("Developer Tools depends on it. Switch off that app first, or
  select it too."). A bulk action that includes the dependents processes them
  first. Core's own switch-offs (a broken app) are not affected.
- **BH-064, rate limits for apps.** `api()->rateLimit()->hit($bucket, $client,
  $max, $per, $failOpen = false)` over core's `RateLimiter`, namespaced per
  app.
- **BH-067, `Retry-After` in the AJAX client.** `err.retryAfter` (seconds, or
  null) on fetch and upload errors.
- **BH-068, the app scanner read method calls as shell execution.**
  `$pdo->exec()`, `Tls::exec()` and `function exec()` were reported as "Shell
  execution" (high). The pattern now skips calls after `->`, `?->`, `::` or
  `function `. `exec()`, `\exec()`, `system()` and the rest are still caught.
- **BH-069, stale OPcache after a fresh install or a delete.** Reinstalling a
  just-deleted app ran the old compiled code against the new files, and the
  boot guard switched the app off. `AppService::forgetCompiled()` invalidates
  every PHP file under the app folder after `installFromZip()` and before
  deleting files. The in-place upgrade already reset the whole cache.

## Tests

- `tests/Unit/Release1290Test.php` (new, 9 tests): `serverIsSecure()` and
  `visitorIp()` with forged and trusted headers; `OutboundGuard` against
  loopback, private, metadata, mapped addresses, numeric spellings, userinfo,
  other schemes and `HTTP_ALLOW_PRIVATE`; the scanner on method calls versus
  real shell calls; `Icon::has()`; nonces (data blocks and existing nonces
  untouched); headers on public, admin, JSON and plain-HTTP responses; report
  grouping, cleaning and the cap; the `.htaccess` line change.
- `tests/Integration/Release1290Test.php` (new, 9 tests over HTTP):
  - headers and a fresh nonce on each page;
  - Settings → Security (admin only; enforce, off and refused values);
  - reports in and out, 415 and 413;
  - the search limit under forged `X-Forwarded-For`;
  - asset versioning and cache headers;
  - `sitemap.index`;
  - app page titles and canonicals;
  - dependency refusals and the ordered bulk;
  - `api()->http()` refusing loopback, metadata, private, `file:` and numeric
    hosts, and allowing loopback on purpose.
- Updated: `tests/Unit/AppBaseClassTest.php` lists `appStorageDir` (added in
  1.2.87); `tests/Integration/QuickEditTest.php` no longer expects a non-Latin
  slug to be refused (valid since 1.2.76). Part of BH-047.

Full suite: 597 passed, 2 failed, 5 skipped. The two failures are the
stylesheet checks of BH-047 (`dashboard.css` and `marketplace.css` are still in
the repository and need deleting with a direct commit, which a patch release
can't do).

Also checked on a local site with the Cloudhim theme, Public Tools, Developer
Tools and Website Diagnostics, with the policy on **Enforce**: 67 browser
checks (desktop and phone) passed with no console errors and no CSP reports.

## Not in this release

- BH-057 (an empty `permissions` list means unrestricted) and BH-058 (route
  ties decided by app name): changing either can switch off or reroute apps
  already installed. They need a deprecation step first.
- BH-063 (network functions in the scanner): flagging `curl_init` would mark
  most existing apps; it needs a lower "info" severity first.
- BH-056 (admin Tailwind safelist) is documentation work.
