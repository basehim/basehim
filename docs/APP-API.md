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
$this->api()->content('event')   // any custom type
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
```

## Examples

```php
// Content
$id = $this->api()->posts()->create(['title' => 'Hello', 'status' => 'published']);
$this->api()->posts()->update($id, ['excerpt' => 'A summary']);
$recent = $this->api()->posts()->all(['status' => 'published'], limit: 20);

// Tagging, without the usual race
$term = $this->api()->terms()->firstOrCreate('tag', 'Release Notes');

// Media from a file your app generated
$item = $this->api()->media()->uploadFromPath('/tmp/chart.png', 'chart.png');
$this->api()->media()->update($item['id'], ['alt_text' => 'Revenue by month']);

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

Handlers are never persisted. Storing a callable name would turn a row edit into
arbitrary code execution, so a task whose app isn't booted is skipped, not fired.

## Cautions

- **`settings()` writes site-wide config**, not your app's. Use
  `$this->getSetting()` / `$this->setSetting()` for your own — those are scoped
  to `app:{slug}` and removed automatically on uninstall. Writes to the `roles`
  and `updates` groups, and to another app's namespace, are refused.
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
