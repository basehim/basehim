# Public routes for apps

Since 1.2.72 an app registers its public pages and endpoints through core's
router, with one small API: `$this->router()->site()`. The routes are
dispatched by Basehim like core's own. They sit beside the admin, the
content and the permalinks without taking any of them over. A faulty app
costs only itself.

A complete, working example is in
[`docs/examples/public-routes`](examples/public-routes). Copy it into
`content/apps/` and activate it.

## Registering routes

Routes are registered in `boot()`, which runs on every request for an active
app:

```php
use App\Core\Http\SitePage;
use App\Core\Request;
use App\Core\Response;

public function boot(): void
{
    $site = $this->router()->site();

    $site->get('/shop', [$this, 'index'], ['name' => 'shop.index']);
    $site->get('/shop/{slug}', [$this, 'show'], ['where' => ['slug' => '[a-z0-9-]+']]);
    $site->post('/api/shop/cart', [$this, 'add'], ['rate' => [30, 60]]);
    $site->put('/api/shop/cart/{id}', [$this, 'update'], ['where' => ['id' => '\d+']]);
    $site->patch('/api/shop/cart/{id}', [$this, 'patch']);
    $site->delete('/api/shop/cart/{id}', [$this, 'remove']);
    $site->match(['GET', 'POST'], '/shop/search', [$this, 'search']);

    $site->group(['prefix' => '/account', 'auth' => true], function ($r) {
        $r->get('', [$this, 'account']);          // /account
        $r->get('/orders', [$this, 'orders']);    // /account/orders
    });
}
```

- **Methods:** GET, POST, PUT, PATCH and DELETE. A GET route also answers
  HEAD.
- **Return value:** each call returns `true` when the route was registered
  and `false` when it was refused. The reason is in the core log, in the
  app's log (Apps → Logs) and in the app's report (Apps → Report → Routes).
- **Lazy loading:** a controller class given by name (`[ShopController::class,
  'index']`) is loaded only when its route is used, so your other code is not
  loaded on every request.
- **Boot runs twice?** Registering the same route again (say, boot code that
  runs twice) is one route, not a conflict.

### Paths and parameters

| Pattern | Matches |
|---|---|
| `/shop` | exactly `/shop` (a trailing slash is ignored) |
| `/shop/{slug}` | one segment: `/shop/blue-mug` |
| `/shop/{slug?}` | optional, last segment only: `/shop` and `/shop/x` |
| `/files/{path:*}` | the rest of the path, slashes included, last segment only |

- **Order:** parameters are passed to the handler after the request, in
  order: `function show(Request $request, string $slug)`.
- **Decoding:** they arrive URL-decoded (`/tag/caf%C3%A9` gives `café`).
- **Refused parameters:** a parameter containing an encoded `/` (except in a
  `{path:*}` wildcard), a NUL byte or invalid UTF-8 is refused with **400**,
  as is any path with a `.` or `..` segment.
- **`where`:** narrows a segment with a regular expression,
  `['where' => ['id' => '\d+']]`. A path that does not match is simply not this
  route (usually 404). The pattern may not contain `/`, `#`, or named or
  look-around groups.

### Options

| Option | Meaning |
|---|---|
| `name` | A unique name, for `$this->router()->url('shop.item', ['slug' => $slug])`. The URL includes the install's base path. |
| `where` | Parameter constraints, as above. |
| `auth` | A signed-in account is required. Without one: **401** (JSON) or the sign-in page (HTML). The account is `$app->make('auth.user')` in the handler. |
| `can` | Capabilities, comma separated, e.g. `'can' => 'edit_posts'`. Implies `auth`. Missing: **403**. |
| `csrf` | On POST/PUT/PATCH/DELETE, **on by default**. See below. |
| `rate` | `[requests, seconds]`, per visitor: `[10, 60]` is ten a minute. Over: **429** with `Retry-After`. |
| `idempotent` | `true` on a POST/PUT/PATCH/DELETE route: an `Idempotency-Key` header is required, and the handler runs once per key (1.2.75). See *Retries and double submits*. |
| `middleware` | Extra middleware classes, run after the checks above (the `"Class:arg"` form works). |
| `replace` | Take over `GET /` or `GET /search` from core (see *Precedence*). |

An unknown option is refused, so a typo such as `midleware` does not silently
turn a check off. Options given to `group()` apply to every route in the
group; `middleware` lists are joined.

### CSRF, sessions and stateless calls

A request that changes something (POST, PUT, PATCH, DELETE) and carries this
site's session cookie must also carry the session's CSRF token: an
`X-CSRF-Token` header, or a `_csrf` form field. Otherwise it gets **403**.

- **Where the token comes from:** your page gets it from
  `$this->session()->csrfToken()`.
- **On admin pages:** scripts already have it as `window.BASEHIM_AJAX.csrf`.

A request with **no** session cookie has no signed-in session for a
cross-site page to abuse. It passes this check, and it is anonymous: a server
calling a public JSON endpoint, or a cookie-less script. Validate its input as
you would any public form, and add `rate`.

`'csrf' => false` is for endpoints other servers call (webhooks): verify
their signature yourself, with `App\Core\Http\Webhook`. It cannot be combined
with `auth` or `can`.

```php
use App\Core\Http\Webhook;

$site->post('/shop/webhooks/pay', [$this, 'paid'], ['csrf' => false, 'rate' => [120, 60]]);

public function paid(Request $request): Response
{
    $body = Webhook::payload();                                   // the raw bytes that were signed
    $sig  = Webhook::header($request, 'Pay-Signature');           // "t=…,v1=…"
    if (!Webhook::verifyTimestamped($body, $sig, (string) $this->getSetting('webhook_secret'))) {
        return Response::json(['error' => 'bad signature'], 400);
    }
    $event = json_decode($body, true);
    // Providers retry: handle each event once, however often it arrives.
    $this->api()->idempotency()->once('pay', (string) $event['id'], fn() => $this->markPaid($event));
    return Response::json(['ok' => true]);
}
```

`verifyHmac($body, $signature, $secret)` checks a plain HMAC-SHA256 of the
body, hex or base64, with or without a `sha256=` prefix (GitHub's
`X-Hub-Signature-256`, and most others). `verifyTimestamped()` checks the
`t=…,v1=…` format signed over `{t}.{body}` and refuses a timestamp more than
five minutes away, so a captured request cannot be replayed later. Both compare
in constant time. If `once()`'s work throws, the event is released and the
provider's retry is processed.

### Retries and double submits

A request that creates something — an order, a booking, a payment — can arrive
twice: a double click, or a client that timed out and tried again although the
first attempt had worked. Mark such a route `'idempotent' => true` and send an
`Idempotency-Key` header (16–128 letters, digits, `-`, `_`; a UUID works),
generated once per intent and reused on every retry:

- the first request with a key runs;
- a repeat after it finished gets **the same response** (status, body,
  `Location`), with `Idempotent-Replayed: true`, and the handler does not run;
- a repeat while the first is still running gets **409** with `Retry-After`;
- the same key with a different body gets **422**;
- no key, or a malformed one: **400**.

Keys are kept for 24 hours, per signed-in user, else per session, else per IP.
A handler that throws or answers 5xx releases its key, so the retry runs.

Routes added with the older helpers, `$this->get()`, `$this->post()` and
`$this->route()`, keep their old behaviour (no automatic CSRF check), so
existing apps such as tracking beacons keep working. Prefer `site()` for new
code.

## Answering

A handler returns a `Response`, or something core turns into one:

```php
return SitePage::view($this, 'item', ['item' => $item], ['title' => $item['name']]);  // page in the theme
return Response::json(['items' => $items]);                                            // JSON
return Response::json(['errors' => $errors], 422);                                     // JSON with a status
return Response::redirect($this->router()->url('shop.index'), 303);                    // redirect
return Response::make($csv, 200, ['Content-Type' => 'text/csv; charset=utf-8']);       // anything else
return SitePage::notFound();                                                           // the theme's 404
return SitePage::error(409, 'Conflict', 'That item was changed meanwhile.');           // a short error page
```

A returned string is sent as HTML, and an array as JSON.

### Pages in the active theme

`SitePage::view($this, $template, $data, $page)` renders `views/{template}.php`
from your app and puts it in the theme's layout: header, footer, menus, the
customizer's styles and the SEO tags, as for a core page.

- **Page options:** `title`, `description`, `status`, `robots`, and
  `layout => false` for your HTML without the theme. The `<title>` is your
  title in the site's format (Settings → SEO), as for core pages (1.2.90).
- **Canonical:** the page's own address without a trailing slash, absolute.
  A path given with `bh_seo()->set('canonical', '/shop')` is made absolute too
  (1.2.90).
- **Layout used:** the theme's `templates/app-{app-slug}.php` if it has one
  (1.2.90), else its `templates/app.php` (both receive `$content`, `$title` and
  `$app_slug`), otherwise its `page.php`. Every theme has
  `page.php`, and it prints the title above your content, so don't repeat it
  as an `<h1>`.
- **Theme overrides:** a theme can restyle an app's page by shipping
  `templates/apps/{app-slug}/{template}.php`.
- **Escaping:** templates escape their output as core's do, with
  `htmlspecialchars()`, or the `$e()` shortcut every template receives:
  `<?= $e($item['name']) ?>`. Unescaped output is your responsibility. `$base`
  (the install's base path) is available too.
- **Styling:** don't rely on the theme having particular CSS classes; inline
  or bundled styles (`$this->asset()`) are safer.
- **Missing template:** a missing template is logged in your app's log and
  answers a plain 500 page to the visitor.

`SitePage::html($title, $html, $page)` does the same with HTML you built
yourself.

## Precedence and conflicts

Routes are tried in this order, whatever order apps boot in:

1. An app route registered with `'replace' => true` for `GET /` or
   `GET /search`. Only one app can do so; it is logged.
2. Core's routes with a fixed first segment: `/admin/…`, `/api/v1/…`,
   `/ajax`, `/search`, `/feed`, `/posts/{slug}`, `/category/{slug}`,
   `/uploads/…` and so on.
3. App routes, admin and public:
   - most specific first: more fixed segments, then fewer wildcards, then
     fewer parameters;
   - equal ones in registration order, so `/shop/cart` is tried before
     `/shop/{slug}`.
4. Core's content routes, `/{slug}` and `/{category}/{slug}`: pages and
   posts under every permalink setting.

What is refused, and why (logged once a day, shown in the app's report):

| Refused | Because |
|---|---|
| A public route under `/admin` | It would skip sign-in and the admin permission checks. Use `adminGet()`/`adminPost()`. |
| A `site()` route under `/ajax`, `/api/v1`, `/mcp`, `/oauth`, `/.well-known`, `/uploads`, `/content`, `/storage` | Reserved for core. Your own API can live at `/api/your-app/…`. |
| A route whose first segment is a parameter (`/{slug}`) | It would answer every page of the site. Use `fallback()` for URLs nothing else serves. |
| A route core already serves (same method and path shape, or a fixed path a core route matches) | Core routes always win. |
| A route another app registered first (same method and shape) | The first one is kept; the report names the app that has it. |

A route an app does get can hide content. If an app serves `/shop` and a
page's slug is `shop`, the page cannot be reached at `/shop`. The app's
report lists such cases. New pages and posts never get a slug an app route
uses.

When nothing matches: **405** with an `Allow` header if the path exists for
other methods, else **404**.

## Admin routes

`adminGet()` and `adminPost()` are unchanged. They live under `/admin`, behind
sign-in and the app's access permission. A public route can never be
registered there, and an app's admin route can no longer replace a core
admin screen.

## Failures

- **App fails while loading or in `boot()`:** its routes are removed with its
  other registrations, and the app is switched off (see
  [APP-FAULT-ISOLATION.md](APP-FAULT-ISOLATION.md)). Its public URLs answer
  503 until it is fixed. Disabling an app removes its routes, since an
  inactive app does not boot.
- **Handler throws:** the visitor gets core's error page (HTML or JSON, 500,
  no details unless `APP_DEBUG` is on). Your app's log gets
  `Route GET /shop/{slug} failed: RuntimeException: … (file:line)`, with paths
  relative to the site. A failing page does not switch the app off.
- **Bad definition:** an invalid route is refused at registration, and the
  app's other routes are unaffected.

## Caching

Routes are not cached. Active apps register them on every request, which
takes well under a millisecond. Enabling, disabling, installing or updating
an app therefore takes effect on the next request, with nothing to
invalidate.
