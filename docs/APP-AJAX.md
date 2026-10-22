# AJAX actions

Companion to **APP-DEVELOPMENT.md**, **APP-API.md** and **APP-PERMISSIONS.md**.

Basehim has one endpoint for browser AJAX: `POST /ajax`. A page names a
registered *action* and sends its data; core checks the session, the CSRF
token, the user's capability, the rate limit and the input, and only then runs
the handler. Apps and core register actions the same way.

```js
Basehim.ajax.request('my-app.save-note', { post_id: 12, note: 'Check the figures' })
  .then(function (data) { bhToast('Saved'); })
  .catch(function (err) { bhToast(err.message, 'error'); });
```

```php
// in your app's boot()
$this->ajax('save-note', [
    'handler'    => [$this, 'saveNote'],
    'capability' => 'edit_posts',
    'input'      => ['post_id' => 'required|int|min:1', 'note' => 'required|string|max:500'],
]);
```

The point is that the security checks live in one place. A hand-written JSON
route has to remember all of them every time; an action declares what it needs
and cannot forget the rest.

**What it is not:** a replacement for the REST API. `/api/v1` is for other
programs, authenticated with API keys or tokens. `/ajax` is for this site's own
pages, authenticated with the signed-in browser session. Business logic belongs
in a service that both can call.

## The request

```http
POST /ajax
Content-Type: application/json
X-CSRF-Token: 3f9a…

{"action": "my-app.save-note", "data": {"post_id": 12, "note": "Check the figures"}}
```

- The body is a JSON object with `action` and, optionally, `data`. Nothing else
  is accepted at the top level.
- `action` is only ever looked up in the registry. It is never treated as a
  class, method, function or path, so `../../x` or `App\Core\Env::all` is just
  an unknown name (404).
- Read-only actions may also be called with `GET /ajax?action=…&key=value`.
  Query values are strings; the schema turns `"3"` into `3` for `int` fields
  and `"true"`/`"1"` into `true` for `bool` fields.

## The response

Success, always HTTP 200:

```json
{"success": true, "data": {"saved": true}, "request_id": "9c2e4f0a1b7d3e65"}
```

`data` is whatever the handler returned. Text that is not valid UTF-8 (a log line, a title saved
by a broken import) is sent with U+FFFD in place of the bad bytes rather than
failing the whole answer.

Error:

```json
{
  "success": false,
  "error": {"code": "FORBIDDEN", "message": "You do not have permission to perform this action."},
  "request_id": "9c2e4f0a1b7d3e65"
}
```

The `message` is written for the person using the page and is safe to show as
it is. A 422 adds `error.fields`, e.g. `{"note": ["Must be at most 500 characters."]}`.
The `request_id` is also in the `X-Request-Id` header and in every log line
about the request, so a user who reports an error can be matched to the log.

| Status | `code` | When |
|---|---|---|
| 400 | `BAD_REQUEST`, `MALFORMED_JSON` | Body empty, not JSON, not an object, no action, extra top-level keys |
| 400 | `IDEMPOTENCY_KEY_REQUIRED` | An idempotent action without a valid `Idempotency-Key` |
| 401 | `AUTH_REQUIRED` | Not signed in, or the session ended |
| 401 | `UNSUPPORTED_AUTH` | A token was sent in `Authorization` (use `/api/v1` for tokens); `Basic` is ignored |
| 403 | `CSRF_INVALID` | Token missing, wrong, or from before a sign-in/sign-out |
| 403 | `ORIGIN_REJECTED` | The browser says the request came from another site |
| 403 | `FORBIDDEN` | The user lacks the action's capability (or the handler said so) |
| 404 | `ACTION_NOT_FOUND` | No such action, or its app is not active |
| 405 | `METHOD_NOT_ALLOWED` | Wrong method; the `Allow` header lists the right ones |
| 409 | `DUPLICATE_REQUEST` | The first request with this idempotency key is still running, or the key was used for a different request |
| 413 | `PAYLOAD_TOO_LARGE` | Body over the limit |
| 415 | `UNSUPPORTED_MEDIA_TYPE` | A POST that is not `application/json` (UTF-8) |
| 422 | `VALIDATION_FAILED` | Input does not match the schema |
| 429 | `RATE_LIMITED` | Too many requests; see `Retry-After` |
| 500 | `INTERNAL_ERROR` | The handler threw something unexpected (details are logged, never sent) |
| 503 | `SERVICE_UNAVAILABLE` | The rate-limit store is down and the action fails closed |

A handler can answer with its own code and status by throwing `AjaxException`
(see [Errors](#errors-and-logging)).

Every response is `application/json; charset=utf-8` with `Cache-Control: no-store`
and `X-Content-Type-Options: nosniff`. The gateway sends no CORS headers.

## Registering an action

### From an app

In `boot()`, with the base class helper. The name you give is prefixed with
your app's slug:

```php
$this->ajax('save-note', [...]);   // registers "my-app.save-note"
```

`ajax()` returns the full name, or `false` if the definition was refused. A
refusal is written to your app's log (Admin → Apps → Logs) with the reason, and
the rest of `boot()` carries on.

Because registration happens in `boot()`, and `boot()` only runs for active
apps, a deactivated or uninstalled app's actions stop existing on the next
request. The gateway also checks, before running a handler, that the app that
owns it was booted this request. `ajax()` called anywhere else — `onActivate()`,
a constructor, a route — is refused.

### From core

In `routes/ajax.php`:

```php
$ajax->register('media.update', [...]);
```

### Names

`namespace.action`, lower case: letters, digits, `-` and `_`, up to three dots.
`my-app.save-note`, `my-app.notes.list`.

- An app registers only under its own slug. It cannot register under another
  app's slug or under core's.
- Core owns these namespaces: `core basehim ajax admin api auth session system
  posts pages content templates editor blocks media users profile roles comments
  terms taxonomies menus widgets settings customizer seo apps themes updates
  schedule cache search mail`. An app whose slug is one of them cannot register
  AJAX actions at all.
- A name can be registered once. A second registration is refused, never
  silently replaces the first — whoever registers it.

### Options

| Option | Default | |
|---|---|---|
| `handler` | — | Required. A closure, `[$this, 'method']` or `[SomeClass::class, 'method']` (resolved from the container when called). Strings are refused. |
| `capability` | — | Required for signed-in actions. A capability name, or a list (all required). `'read'` means any signed-in user. |
| `area` | — | An admin path (`'/admin/media'`). The user must also hold whatever that admin area requires, including changes a site made with the `admin.area_policy` filter, so the action is never more open than the screen it serves. Signed-in actions only. |
| `auth` | `'user'` | `'user'` or `'public'`. Public actions take no capability. |
| `methods` | `['POST']` | `'POST'` and/or `'GET'`. GET is for reads only. |
| `csrf` | `true` | Only a public action may set `false` — see [Public actions](#public-actions). |
| `input` | `[]` | The schema. With none, any field sent is refused. |
| `allow_unknown` | `false` | Tolerate undeclared top-level fields (they are still dropped). |
| `rate_limit` | 120/min per user; public 30/min per IP | `['max' => 10, 'per' => 60, 'by' => 'user'\|'ip'\|'session', 'fail' => 'open'\|'closed']`, or `false` (signed-in actions only). |
| `max_body` | `AJAX_MAX_BODY` (64 KB) | Bytes, 256 B – 1 MB. |
| `idempotent` | `false` | Require an `Idempotency-Key` header and run once per key. POST only. |
| `description` | `''` | For people reading the code. |

Every option is checked at registration. A typo — `capabilty`, `requred`, a
`max` that is not a number — is refused there, not discovered later as a check
that never ran.

### The handler

```php
use App\Core\Ajax\AjaxContext;
use App\Core\Ajax\AjaxException;

public function saveNote(AjaxContext $ctx): array
{
    $postId = $ctx->input('post_id');      // validated: an int ≥ 1
    // …
    return ['saved' => true];              // becomes "data"
}
```

`AjaxContext`:

| | |
|---|---|
| `input($key, $default)`, `all()`, `has($key)` | Validated input — declared fields that were sent, nothing else |
| `user()`, `userId()` | The signed-in user (no password hash), or `null` |
| `can($cap)`, `authorize($cap)` | Capability checks; `authorize()` throws `FORBIDDEN` |
| `requestId()`, `ip()` | For logging. `ip()` honours `TRUSTED_PROXIES` only |
| `log($message, $context, $level)` | Your app's log (core's for core actions), tagged with the request ID |
| `request()`, `action()` | The raw request and the action definition, rarely needed |

Return an array, scalar, `null` or `JsonSerializable`. Do not return a
`Response` and do not echo: anything printed is discarded (and logged), so it
cannot corrupt the JSON.

## Authentication

The gateway uses the browser session and nothing else — the same
`AuthService::sessionUser()` as the admin. That check ends a session whose
account was suspended or deleted, whose password changed, or that was signed
out everywhere, and the request is then `401 AUTH_REQUIRED`.

- **User IDs, roles and capabilities sent by the client mean nothing.** Who the
  user is comes from the session; what they may do comes from their role.
- **Expired sessions** get `AUTH_REQUIRED`. The client passes this on; a page
  should send the user to sign in again. A "remember me" cookie is honoured on
  the next page load, not inside an AJAX call (restoring it would rotate the
  CSRF token mid-request).
- **Bearer tokens and API keys are refused** with `UNSUPPORTED_AUTH`. Programs
  use `/api/v1`, where token scopes are enforced; mixing the two models in one
  endpoint is how a read-only key ends up writing.
- **HTTP Basic is ignored, not refused.** A site behind a server password (a
  staging copy, say) gets `Authorization: Basic …` on every browser request.
  It identifies the visitor to the web server, not to Basehim, so the gateway
  passes it by and goes on with the session as usual.

## CSRF protection

Every POST is checked against the session's CSRF token, sent in the
`X-CSRF-Token` header. It is the same token admin forms carry
(`Session::csrfToken()`, 256 random bits, compared with `hash_equals`).

- Only the header counts. A token in the body or URL is ignored, so tokens stay
  out of URLs, logs and browser history.
- The token is replaced whenever someone signs in or out. A token obtained
  before signing in (or by anyone who saw the signed-out page) does not
  authorise the signed-in session.
- A tab left open across a sign-in elsewhere holds a stale token. The JS client
  fetches the current one (`GET /ajax?action=core.csrf`) and retries once.
- GET requests are not checked. That is why GET actions must never change
  anything.

**Defence in depth.** Before the token is looked at, a request whose
`Sec-Fetch-Site` is `cross-site`, or whose `Origin` is not this site (or an
origin in `CORS_ALLOWED_ORIGINS`), is refused with `ORIGIN_REJECTED`. The
session cookie is `SameSite=Lax` and `HttpOnly`, and JSON bodies force a CORS
preflight that the gateway never answers. None of these replaces the token;
together they mean a forged request has to beat all of them.

## Authorization

The `capability` you declare is checked centrally with the same code as the
admin (`CheckCapability::userCan()`: role, per-user grants and denials,
super-admin wildcard). A user without it gets `403 FORBIDDEN` before your
handler runs.

What the gateway cannot know is **whether this user may touch this record**.
An ID in the request is a claim, not a right. Check ownership in the handler,
ideally through a service method that the admin screens and the REST API use
too:

```php
$item = $this->media->find($ctx->input('id'));
if (!$item) throw AjaxException::notFound();
if (!MediaService::userCanEdit($ctx->user(), $item)) {
    throw AjaxException::forbidden('You can only edit your own files.');
}
```

Hiding a button is not a permission check. Every action must be safe to call by
hand with any input the schema allows.

An action that does the work of an admin screen should name that screen with
`'area'`. Core's own actions do: `media.delete` declares `'area' => '/admin/media'`,
so a site that tightened the media library with the `admin.area_policy` filter
tightened the action too.

## Input validation

The schema says which fields are accepted and what they must look like.
Anything else is refused with `422` and per-field messages — including fields
nobody declared, at every level, which is what stops a client adding
`author_id` or `role` to a request and having it saved (mass assignment).

```php
'input' => [
    'id'      => 'required|int|min:1',
    'title'   => 'string|max:255',
    'status'  => 'required|string|in:draft,published',
    'email'   => 'string|email|max:190',
    'website' => 'string|url',                              // http(s) only
    'score'   => 'number|min:0|max:5',
    'notify'  => 'bool',
    'note'    => 'string|nullable|max:500',
    'tags'    => ['type' => 'array', 'max' => 20, 'items' => 'string|max:50'],
    'seo'     => ['type' => 'object', 'fields' => ['title' => 'string|max:70']],
    'slug'    => ['type' => 'string', 'regex' => '/^[a-z0-9-]+$/'],
],
```

- **Types** (each field needs exactly one): `string`, `int`, `number`, `bool`,
  `array` (a list; needs `items`), `object` (named `fields`). JSON types are
  strict: `"5"` is not an int, `5.0` is not an int, `"true"` is not a bool.
- **Modifiers**: `required` (present, not `null`, not `""`), `nullable`,
  `min`/`max` (characters for strings, items for arrays, value for numbers),
  `in:a,b,c`, `email`, `url` (http and https only — not `javascript:`),
  `regex` (array form only, since a pattern may contain `|`).
- Optional fields that were not sent are absent from `$ctx->all()`, so it can
  be passed straight to a partial update.
- Nesting is limited to 8 levels; the JSON parser stops at 64.

**Free-form objects.** A schema lists field names, so an object whose keys
are data (widget settings, a map of option paths to values) cannot be
declared field by field. Send it as a JSON string and decode it in the
handler, checking what you read: `'settings' => 'string|max:60000'`, then
`json_decode($ctx->input('settings'), true)`. Core's `widgets.render` and
`customizer.save` do this.

Validation is about shape. Database writes still go through parameterized
queries (`Database`, `$this->api()`), and anything shown in HTML still needs
escaping where it is output. A valid string can contain `<script>`.

## Rate limiting

Two limits apply to every request:

- **Global**, per client address: `AJAX_RATE_LIMIT` requests a minute (default
  300) across all actions, checked before the body is read.
- **Per action**: `rate_limit`, or by default 120 a minute per user (signed-in
  actions) and 30 a minute per IP address (public actions).

Over a limit, the answer is `429 RATE_LIMITED` with `Retry-After` in seconds.

Counters live in the `rate_limits` table (migration 014; created on first use
if missing). Each check is one atomic statement, so simultaneous requests
cannot slip past a limit. Windows are fixed (per minute, per hour …), so up to
twice the limit can pass across a window boundary. Counter keys are SHA-256
hashes; no address or user ID is stored readable. IPv6 clients are counted per
/64.

**Client addresses.** `X-Forwarded-For` is believed only from proxies listed
in `TRUSTED_PROXIES` (IPs or CIDR ranges). Otherwise the connecting address is
the client, whatever headers say — without that, anyone could reset their own
limit by inventing an address. Behind Cloudflare or a load balancer, list its
ranges.

**If the store is unavailable** (database down), requests are let through and
a warning is logged once per request (`AJAX_RATE_LIMIT_FAIL=open`, the
default). Set it to `closed` to answer `503` instead, or give one action
`'rate_limit' => ['fail' => 'closed']`. Idempotent actions always fail closed:
"exactly once" cannot be promised without the store.

Give expensive actions — sending mail, calling another server, generating
files — a tight limit of their own.

## Idempotency

With `'idempotent' => true`, an action requires an `Idempotency-Key` header
(16–128 letters, digits, `-`, `_`). The first request with a key runs. A repeat
within 24 hours does not run the handler again (since 1.2.75):

- if the first request finished, the repeat gets **the same answer** — status
  and body — with the header `Idempotent-Replayed: true`. A client that lost
  the first answer to a timeout learns what happened instead of being told
  "duplicate";
- if the first request is still running, `409 DUPLICATE_REQUEST`, with
  `Retry-After`;
- if the key was used for a different action or input, `409 DUPLICATE_REQUEST`.

Keys are per user (or per visitor session). Only a successful answer is
stored: if the handler throws (an `AjaxException` included), its key is
released so the client can retry with the same key. Requests refused before
the handler runs (validation, permissions) never claim the key.

The JS client generates a key with `{ idempotencyKey: true }`, or takes one you
keep across retries: generate it once per user intent (one click of *Pay*), not
once per attempt.

## Public actions

```php
$this->ajax('subscribe', [
    'handler'    => [$this, 'subscribe'],
    'auth'       => 'public',
    'input'      => ['email' => 'required|string|email|max:190'],
    'rate_limit' => ['max' => 5, 'per' => 3600, 'by' => 'ip'],
]);
```

Public actions say so explicitly with `'auth' => 'public'`. They always have a
rate limit. By default they still check CSRF against the visitor's session (the
JS client fetches a token first), which stops another site from submitting the
form in your visitors' names.

`'csrf' => false` is allowed only on public actions, and then the action runs
**as a visitor**: the gateway does not open the session, so `$ctx->user()` is
always `null`, even for a signed-in caller. That keeps a request forged from
another site from borrowing anyone's sign-in. Use it for things like a view
counter, never for anything that should know who is asking.

## Errors and logging

Throw `AjaxException` for an answer you mean the user to see:

```php
throw new AjaxException('QUOTA_EXCEEDED', 'You have used this month’s quota.', 409);
throw AjaxException::notFound('That note no longer exists.');
throw AjaxException::forbidden();
throw AjaxException::invalid('Pick a date in the future.', ['date' => ['Must be in the future.']]);
throw AjaxException::conflict('Someone else changed this note. Reload it.');
```

Codes are `UPPER_SNAKE_CASE`; statuses are 4xx or 5xx. Never build the message
from an exception or from the database.

Anything else a handler throws is a bug as far as the client is concerned: it
gets `500 INTERNAL_ERROR` and the request ID, nothing else. The full exception
— class, message, file, line, trace — goes to the core log, and for an app's
action to the app's log as well. Warnings and notices are never displayed.

Logged automatically: unexpected exceptions, handlers that printed output,
capability denials, CSRF failures of signed-in users, the first rate-limit hit
in each window, and calls to actions of inactive apps. Never logged: request
bodies, CSRF tokens, cookies, session IDs, `Authorization` headers.

## JavaScript

The client is loaded on every admin page (`admin/assets/js/ajax.js`, in
`<head>`, so any later script can use it).

```js
Basehim.ajax.request(action, data, options)   // → Promise<data>
Basehim.ajax.get(action, data, options)       // same, with method GET
Basehim.ajax.post(action, data, options)
Basehim.ajax.newIdempotencyKey()
Basehim.ajax.AjaxError                        // the rejection type
```

Options: `method` (`'POST'`), `timeout` in ms (30000), `signal` (an
`AbortSignal`), `idempotencyKey` (`true` or a string).

The promise resolves with the handler's `data`. It rejects with an `AjaxError`
carrying `code`, `message` (safe to show), `status`, `fields` and `requestId`.
Besides the server's codes, the client produces `NETWORK_ERROR`, `TIMEOUT`,
`ABORTED` and `BAD_RESPONSE` (the answer was not the gateway's JSON — a proxy
error page, say).

```js
var save = document.getElementById('save');
bhBusy(save, true, 'Saving…');
Basehim.ajax.request('my-app.save-note', { post_id: 12, note: field.value })
  .then(function () { bhToast('Saved'); })
  .catch(function (err) {
    if (err.code === 'VALIDATION_FAILED') showFieldErrors(err.fields);
    else bhToast(err.message, 'error');
  })
  .then(function () { bhBusy(save, false); });
```

The client sends the CSRF token in a header, never in the URL, and logs
nothing. It uses `credentials: 'same-origin'`, `cache: 'no-store'`, and
refuses redirects (the gateway never redirects, so one means something else
answered). GET requests take flat values only.

## Themes and the public site

The front end does not load the client unless asked, because most pages make
no AJAX calls. An app that needs it calls `$this->useAjaxClient()` in `boot()`;
a theme calls `bh_ajax_client()` before `bh_head()`. Either adds the script and
the endpoint's URL (through `bh_head()`, so the theme must call it) — and no
CSRF token. A token in the page would start a session for every visitor
and be wrong in any cached copy of the page. The client fetches it just before
its first POST, so only visitors who use an AJAX feature get a session.

A theme cannot register actions (themes have no PHP lifecycle for it); a
theme's interactive features are an app's job. Front-end actions are usually
`'auth' => 'public'` and need the strictest limits you can live with.

## What stays the handler's job

The gateway enforces what an action declares. It cannot:

- decide whether this user may touch this record (ownership, status, workflow);
- make a GET handler read-only;
- escape output — validated strings can still hold markup;
- make SQL safe if you concatenate it — use bound parameters;
- check files: the gateway takes JSON only and accepts no uploads. Use the
  media API for files; never trust a client-supplied file name, type or path;
- make an expensive operation cheap — give it a tight rate limit, and move slow
  work to a scheduled task;
- stop a duplicate of a non-idempotent action — declare `idempotent` where
  repeating would hurt (payments, emails, publishing).

## A complete example

An app that lets authors attach private review notes to their own posts.

```php
<?php
namespace YourCompany\ReviewNotes;

use App\Core\Ajax\AjaxContext;
use App\Core\Ajax\AjaxException;

class App extends \App\Core\App
{
    public function onActivate(): void
    {
        $this->schema("CREATE TABLE IF NOT EXISTS {$this->table('notes')} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            post_id BIGINT UNSIGNED NOT NULL,
            author_id BIGINT UNSIGNED NOT NULL,
            body TEXT NOT NULL,
            created_at DATETIME NOT NULL,
            KEY (post_id)
        )");
    }

    public function boot(): void
    {
        $this->ajax('add', [
            'handler'    => [$this, 'add'],
            'capability' => 'edit_posts',
            'input'      => [
                'post_id' => 'required|int|min:1',
                'body'    => 'required|string|min:1|max:2000',
            ],
            'rate_limit' => ['max' => 30, 'per' => 60],
            'idempotent' => true,   // a double-click must not add two notes
        ]);

        $this->ajax('list', [
            'handler'    => [$this, 'list'],
            'methods'    => ['GET'],
            'capability' => 'edit_posts',
            'input'      => ['post_id' => 'required|int|min:1'],
        ]);
    }

    public function add(AjaxContext $ctx): array
    {
        $post = $this->postFor($ctx);
        $this->db()->insert($this->table('notes'), [
            'post_id'    => (int) $post['id'],
            'author_id'  => $ctx->userId(),            // from the session, never the request
            'body'       => $ctx->input('body'),       // stored as text; escape when shown
            'created_at' => bh_now(),
        ]);
        $ctx->log('Note added', ['post_id' => (int) $post['id']]);
        return ['added' => true];
    }

    public function list(AjaxContext $ctx): array
    {
        $post = $this->postFor($ctx);
        $rows = $this->db()->select(
            "SELECT id, body, created_at FROM `{$this->table('notes')}` WHERE post_id = :p ORDER BY id",
            ['p' => (int) $post['id']]
        );
        return ['notes' => $rows];
    }

    /** The post, if this user may annotate it: their own, or anyone's with edit_others_posts. */
    private function postFor(AjaxContext $ctx): array
    {
        $post = $this->api()->posts()->find($ctx->input('post_id'));
        if (!$post) {
            throw AjaxException::notFound('That post no longer exists.');
        }
        $own = (int) $post['author_id'] === $ctx->userId();
        if (!$own && !$ctx->can('edit_others_posts')) {
            throw AjaxException::forbidden('You can only add notes to your own posts.');
        }
        return $post;
    }
}
```

(If your app declares permissions, this one needs `db.raw` for `schema()` and
`db()`, and `posts.read` for `posts()->find()`; see APP-PERMISSIONS.md.)

```js
Basehim.ajax.request('review-notes.add', { post_id: postId, body: textarea.value }, { idempotencyKey: true })
  .then(function () { return Basehim.ajax.get('review-notes.list', { post_id: postId }); })
  .then(function (data) {
    list.textContent = '';
    data.notes.forEach(function (n) {
      var li = document.createElement('li');
      li.textContent = n.body;                 // textContent, never innerHTML
      list.appendChild(li);
    });
  })
  .catch(function (err) { bhToast(err.message, 'error'); });
```

## Moving an existing endpoint over

1. **Find the callers.** Search for the URL in `admin/`, `content/apps/` and
   themes before changing anything.
2. **Register the action** with the capability the route's area required and a
   schema listing exactly the fields the old code read. Move the logic into a
   service method if the old controller held it, so both routes share it.
3. **Add the ownership check** the old code may have skipped. (The media
   endpoints had none: any author could edit an administrator's files.)
4. **Switch the callers** to `Basehim.ajax.request()`. The response is now
   `data`, errors are `err.message`.
5. **Keep the old route** until nothing calls it. Harden it in place if it had
   a hole; mark it superseded in its doc comment.
6. **Test both**: the new action through the gateway, the old route for
   anything still calling it.

`media.update` is the first one moved: the handler is
`App\Http\Controllers\Ajax\MediaActions::update`, the ownership rule is
`MediaService::userCanEdit()` (shared with the admin controller and the REST
API), and `/admin/media/{id}/update` remains for older callers with the same
rule applied. Editing someone else's file needs `delete_media` or
`edit_others_posts` (so editors can fix alt text on any image); deleting it
needs `delete_media` (`MediaService::userCanModify()`).

## Core actions

What core registers (routes/ajax.php), for reference and as examples. Every
POST action also needs the CSRF token; every one is rate-limited.

| Action | Method | Who | What |
|---|---|---|---|
| `core.csrf` | GET | anyone | The session's current CSRF token |
| `posts.save_draft` | POST | the type's editors (own; others' with `edit_others_*`) | Save the editor's post as a draft or pending review; creates it on the first save. Idempotent. Never changes a published post (409 `ALREADY_PUBLISHED`) |
| `posts.render` | POST | `edit_posts` | Block JSON or Markdown → HTML. With `for: 'blocks'`, Markdown is converted for the visual editor (bold-only lines become headings, deliberate line breaks are kept) |
| `posts.templates` | GET | `edit_posts` | Block templates for the inserter |
| `posts.tag_add`, `posts.tag_remove` | POST | whoever may edit the post | Add or remove a tag at once; a new name is kept pending until the post is published |
| `terms.tag_suggest`, `terms.tag_overview` | GET | `edit_posts` | Tag typeahead; most used and recently used tags |
| `media.list` | GET | `upload_media` | One page of the media library |
| `media.update` | POST | own file, or `delete_media` / `edit_others_posts` | Title, alt text, caption, description |
| `media.delete` | POST | own files; others' need `delete_media` | Delete files (denied ones are counted, not deleted) |
| `media.regenerate_batch` | POST | `manage_settings` | One batch of thumbnail regeneration |
| `widgets.render` | POST | `edit_posts` | Widget block preview |
| `comments.pending_count` | GET | `moderate_comments` | The sidebar badge |
| `customizer.save`, `customizer.draft` | POST | `manage_themes` | Save or preview Customizer values |
| `menus.items_add`, `menus.item_update`, `menus.item_remove`, `menus.reorder` | POST | `manage_menus` | The menu builder |
| `users.activity` | GET | `manage_users` | A user's activity log |
| `system.log` | GET | `manage_settings` | The end of a log file |
| `settings.save` | POST | `manage_settings` (and the screen's own area rule) | Save one Settings screen. `tab`: general, reading, writing, discussion, seo, ai, appearance, permalinks, media, email or authorization; `form`: the screen's fields URL-encoded exactly as the form would post them. Same checks as `POST /admin/settings/{tab}` (`SettingsSaver`); answers `{type, message, values}` — `values` are the stored values, so the screen can show what the server adjusted |
| `settings.section` | GET | `manage_settings` (and the screen's own area rule) | One Settings section to show without a page load (1.2.82). `tab`: `home` (the phone's list of sections) or a screen other than appearance (its address opens the Customizer). Answers `{tab, html, title, heading}`: `html` is the content of the page's `<main>` exactly as `GET /admin/settings/{tab}` renders it, with the screen's own scripts |
| `apps.market_browse`, `themes.market_browse` | GET | `manage_apps` / `manage_themes` | One page of the marketplace (1.2.84). `q`, `category`, `tag`, `sort` (featured, popular, newest, name), `page`. Answers `{items, meta}`; each item says whether it is `installed`, its `installed_version` and whether it is `active`. Not connected to the hub: `NOT_CONNECTED` (503); the hub failing: `MARKETPLACE_ERROR` (502) |
| `apps.market_facets`, `themes.market_facets` | GET | `manage_apps` / `manage_themes` | The marketplace's categories with counts: `{categories: [{name, count}], tags}` |
| `apps.market_install`, `themes.market_install` | POST | `manage_apps` / `manage_themes` | Download one item by `slug` from the hub, verify its checksum and install or update it. Failure: `INSTALL_FAILED` (422) with the reason |
| `apps.market_consent` | GET | `manage_apps` | The permissions an installed app asks for before it is turned on: `{needs_consent, permissions: [{key, label, description, risk}], unknown, granted}` |
| `apps.market_activate` | POST | `manage_apps` | Turn on an installed app. With `consent: true`, `permissions` (the keys to grant; empty withholds all) is recorded first; without it, an app that still needs approval answers `{active: false, needs_consent: true}`. Answers `{active, withheld_count}`; requirements not met: `ACTIVATION_FAILED` (422) |
| `themes.market_activate` | POST | `manage_themes` | Make an installed theme the active one |

The full list on a running site — core's and every active app's, with their
input fields — is under **API → Reference → AJAX actions**.

Still on their own routes: uploads (`/admin/media/upload`, avatars), which the
gateway cannot take because it accepts JSON only; and the updater and the
marketplace installers, which replace files while they run and keep the
routes they were tested with.

## Configuration

| `.env` | Default | |
|---|---|---|
| `AJAX_MAX_BODY` | `65536` | Largest body in bytes; an action may set its own up to 1 MB |
| `AJAX_RATE_LIMIT` | `300` | Requests per minute per client address, all actions |
| `AJAX_RATE_LIMIT_FAIL` | `open` | `closed` answers 503 when the counter store is down |
| `TRUSTED_PROXIES` | — | Comma-separated IPs/CIDRs whose `X-Forwarded-For` is believed |
| `CORS_ALLOWED_ORIGINS` | — | Existing setting; origins listed are not refused as cross-site |

Defaults for per-action limits are in `config/ajax.php`.

## Limits of this design

- **One session lock.** PHP's file sessions lock, so a user's simultaneous
  requests run one after another. A slow handler holds up that user's other
  requests (not anyone else's).
- **No uploads.** JSON only; files go through the media endpoints.
- **Fixed windows.** Up to twice a limit can pass across a window boundary.
- **Not a sandbox.** Like everything an app does, registration is enforced
  for honest code. A hostile app is PHP in the same process (APP-PERMISSIONS.md).
