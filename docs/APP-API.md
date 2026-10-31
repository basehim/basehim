# Basehim App API

Everything an app needs from core, reachable as `$this->api()` from any class
extending `App\Core\App` (or the legacy `App\Core\App`).

Calls run **in-process**. Do not HTTP-call your own site to do something core
can do directly — it costs a full request cycle and loses the session.

## Shape

Every resource exposes the same methods, so learning one teaches you all:

| Method | Returns |
|---|---|
| `find($id)` | one row, or `null` |
| `all($filters, $limit)` | array of rows (paginates internally, capped) |
| `paginate($filters, $page, $perPage)` | `['data' => [...], 'meta' => [...]]` |
| `create($data)` | new id, or `0` on failure |
| `update($id, $data)` | `bool` |
| `delete($id)` | `bool` |

Nothing throws. Core services raise on input an app can't easily pre-validate,
so the facade absorbs it, logs the reason against your slug, and returns
`null`/`false`/`0`. Check return values; raise your own exception if you want one.

## Resources

```php
$this->api()->posts()            // type = post
$this->api()->pages()            // type = page
$this->api()->content('event')   // any custom type; ->fields($id), ->saveFields($id, [...]) (1.2.94, CONTENT-TYPES.md)
$this->api()->media()
$this->api()->users()
$this->api()->comments()
$this->api()->terms()
$this->api()->menus()
$this->api()->settings()         // SITE settings — see caution below
$this->api()->cache()            // namespaced to your app
$this->api()->mail()
$this->api()->schedule()
$this->api()->http()
$this->api()->rateLimit()        // counting limits (1.2.90)
$this->api()->search()           // BH Search: index and search your records (1.2.92)
```

## Examples

```php
// Content
$id = $this->api()->posts()->create(['title' => 'Hello', 'status' => 'published']);
$this->api()->posts()->update($id, ['excerpt' => 'A summary']);
$recent = $this->api()->posts()->all(['status' => 'published'], limit: 20);

// Tagging, without the usual race
$term = $this->api()->terms()->firstOrCreate('tag', 'Release Notes');

// Media from a file your app generated. $meta takes title, alt_text (or its
// short form alt) and caption.
$item = $this->api()->media()->uploadFromPath('/tmp/chart.png', 'chart.png', null,
    ['alt_text' => 'Revenue by month', 'caption' => 'Q3 report']);

// Later changes: title, alt_text/alt, caption, description — and, after
// rewriting the file in place, its width, height and file_size.
$this->api()->media()->update($item['id'], ['width' => 1600, 'height' => 900, 'file_size' => 245112]);

// 1.2.87: a file sent to one of your AJAX actions, into the library…
$item = $this->api()->media()->fromUpload($ctx->file('file'), ['title' => 'Logo']);
// …or a file replaced in place (same URL; size, dimensions and thumbnails updated).
$this->api()->media()->replaceFile($id, $optimizedPath, ['expect_sha1' => $sha1]);
$why = $this->api()->media()->lastError();     // when either returned null
$abs = $this->api()->media()->path($id);        // the file on disk, safely resolved
// More in MEDIA-EXTENSIONS.md.

// Moderation
foreach ($this->api()->comments()->all(['status' => 'pending']) as $c) {
    if ($this->looksFine($c)) $this->api()->comments()->approve((int) $c['id']);
}

// Cache — keys are namespaced to your app automatically
$rates = $this->api()->cache()->remember('fx', 3600, function () {
    return $this->api()->http()->getJson('https://api.example.com/rates');
});

// Outbound HTTP that fails fast instead of hanging
$res = $this->api()->http()->timeout(5)->withToken($key)->postJson($url, ['x' => 1]);

// Email through the site's configured mailer and branding
$this->api()->mail()->sendTemplate($to, 'Report ready', 'Your report', '<p>…</p>');
```

## Scheduling

Register handlers in `boot()` — it runs every request, so the runner always
knows what a key means. Registration is cheap and idempotent by design.

```php
public function boot(): void
{
    $this->api()->schedule()->hourly('sync-feed', [$this, 'syncFeed']);
    $this->api()->schedule()->daily('cleanup', [$this, 'cleanup']);
    $this->api()->schedule()->everyMinutes(15, 'poll', [$this, 'poll']);
}
```

**How tasks actually fire.** By default, after a page response has been sent
(`fastcgi_finish_request()` where available), so visitors never wait on your
task. The consequence is honest and worth designing around: **a site with no
traffic runs nothing.** "Hourly" means "hourly, whenever someone next visits".

For punctual execution, point a real crontab at the token URL shown in
`GET /api/v1/schedule` or on Admin → System:

```
* * * * * curl -s "https://example.com/api/v1/schedule/run?token=..."
```

Other guarantees: one sweep at a time (non-blocking `flock`, so requests never
queue behind a slow task), at most 5 tasks per sweep, and `next_run_at` advances
even when a handler throws — otherwise a permanently failing task would retry on
every sweep forever. Failures are logged and counted on the task row.

Since 1.2.75 each run also takes a **lease** on its task row (15 minutes), so
the same task never runs twice at once, even if two runners start together (a
crontab and a page visit, or two servers). A run killed by a time limit leaves
its lease behind; when it expires the task runs again and the lost run is
logged. Make handlers safe to run again after an interruption.

Core has tasks of its own (1.2.76), listed on Admin → System under `core`:
`post-views` adds buffered page views to `posts.view_count` every minute, and
`cleanup` removes expired sign-in tokens, old activity logs, notifications,
log files and idle session files once a day (retention in days:
`retention.activity_days` 180, `retention.notification_days` 90,
`retention.log_days` 30; 0 keeps them). So `view_count` can be a minute
behind; Settings → Reading → Page views switches back to counting on every
view, or off.

A task that keeps failing **backs off**: after *n* failures in a row its next
run is `interval × 2ⁿ⁻¹` away, up to a day (or its own interval, if longer).
One success resets it. Admin → System lists failing tasks with their last
error.

Handlers are never persisted. Storing a callable name would turn a row edit into
arbitrary code execution, so a task whose app isn't booted is skipped, not fired.

## Cautions

- **`settings()` writes site-wide config**, not your app's. Use
  `$this->getSetting()` / `$this->setSetting()` for your own — those are scoped
  to `app:{slug}` and removed automatically on uninstall. Writes to the `roles`
  and `updates` groups, and to another app's namespace, are refused.
- **Read, change, write is a race** when two requests do it at once: both
  read the old value and the second write loses the first. For a value
  that is updated rather than replaced (a counter, a list), use `update()`,
  which locks the row while your function runs:

  ```php
  // Your app's own settings (no permission needed)
  $n = $this->api()->settings()->updateOwn('orders_today', fn($n) => (int) $n + 1, 0);
  // A site setting, with the same protections as set()
  $this->api()->settings()->update('general', 'counter', fn($v) => (int) $v + 1, 0);
  ```

  The function may run more than once if the database retries the
  transaction, so keep it pure. Both return the new value.
- **Several writes that must happen together** go in a transaction:

  ```php
  $this->api()->transaction(function () use ($order) {
      $this->db()->insert('shop_orders', $order);
      $this->api()->settings()->updateOwn('stock', fn($s) => $s - $order['qty'], 0);
  });
  ```

  Everything inside is committed together or not at all. If the function
  throws, it is rolled back and the exception rethrown; a deadlock is retried
  up to three times, so the function must not send mail or call other servers
  — do that after. Transactions nest: an inner one joins the outer.
- **Events that arrive more than once** (webhooks, queue messages) can be
  handled once per id with `$this->api()->idempotency()->once($scope, $id, $fn)`.
  See PUBLIC-ROUTES.md for verifying webhook signatures with
  `App\Core\Http\Webhook`.
- **Settings saved with `autoload = false`** are not loaded on every
  request: use it for anything large or written often — a cache of remote
  data, a sync cursor, a counter —
  `$this->api()->settings()->set('app:' . $this->slug(), 'feed_cache', $data, false)`
  (`$this->setSetting()` always autoloads). They are
  read the first time they are asked for, and writing one does not clear the
  cached settings of every request (1.2.76).
- **`http()` only reaches public internet addresses** (1.2.90). The host is
  resolved once and every address must be public (no loopback, private,
  link-local/cloud metadata or reserved ranges, IPv4 or IPv6); the connection
  goes to the address that was checked. Redirects (at most 5) are followed by
  core and each is checked the same way; `Authorization` is not sent to
  another host. Only `http`/`https`; responses over 20 MB are refused
  (`->maxBytes()`). An app that talks to a service on the server's own network
  on purpose calls `->allowPrivateNetwork()` for that request (never for an
  address a visitor typed), or the site lists hosts or ranges in
  `HTTP_ALLOW_PRIVATE` in .env.
- **`rateLimit()`** counts anything the gateway's and site routes' own limits
  don't cover:
  `$r = $this->api()->rateLimit()->hit('exports', 'user:' . $id, 5, 3600);`
  returns `allowed`, `remaining` and `retry_after`. Counters are atomic and
  shared by every PHP worker, and namespaced to your app. For visitors, key by
  `Request::visitorIp()` / `AjaxContext::ip()`, never by a header. If the store
  can't be reached the hit is refused, unless you pass `$failOpen = true`.
- **`search()`** (1.2.92) makes your records searchable through core's shared
  index, gives `constrain()` for queries on your own tables (every word, any
  order, fast at any size), ranked `query()` results, and a place in the
  site's search page. See [SEARCH.md](SEARCH.md).
- **`users()` strips password hashes** from every return value.
- **`all()` is capped** (500 by default) so a large table can't exhaust memory
  on shared hosting. Raise it deliberately or use `paginate()`.
- **`comments()->create()` bypasses the spam guard**, since app code is trusted.
  Run untrusted input through `comments()->guard()` first.

## REST equivalents

Most of the above is also reachable over HTTP under `/api/v1` with a JWT — see
Admin → API for the full generated list, including the endpoints added in
1.35.0 for comment moderation, menu CRUD, media metadata, apps, cache and the
scheduler.
