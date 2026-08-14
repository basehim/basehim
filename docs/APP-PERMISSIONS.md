# Basehim App Permissions

## Read this first

**This is not a sandbox.** An app is PHP running in the same process as core, on
shared hosting with no separate process, no seccomp and no container. Any app can
call `new PDO(...)`, read `.env`, or invoke a core service it was never granted.
Nothing in Basehim prevents that, and nothing could without changing how apps are
loaded entirely.

What this *is*: a policy layer over the App API. An app declares what it intends
to touch, the operator approves it, and calls through `$this->api()` are checked
against the approved set. Overreach becomes visible in the logs instead of
invisible. Combined with the static scanner, it raises the cost of misbehaviour
without pretending to make it impossible.

The honest guarantee: **an honest app cannot exceed its declaration by accident.**
Not: a hostile app is contained. Install apps you have reason to trust.

## Enforcement is opt-in

An app that declares **no** permissions runs unrestricted, exactly as before.
That is deliberate — every app written before 1.36.0 declares nothing, and gating
them would break all of them on activation.

Declaring a `permissions` array is what opts an app in. From that moment the
declaration is also the ceiling. Unrestricted apps are badged as such in the
admin so an operator can see which ones are running without limits.

## Declaring

```json
{
  "name": "Feed Importer",
  "slug": "feed-importer",
  "version": "1.0.0",
  "icon": "heroicon:rss",
  "permissions": ["posts.write", "terms.write", "http.outbound", "schedule"]
}
```

Ask for the least you need. Every extra line is one an operator has to accept,
and a long list invites clicking through without reading.

## The catalogue

| Permission | Risk | Grants |
|---|---|---|
| `posts.read` / `pages.read` | low | View content including drafts |
| `posts.write` / `pages.write` | medium | Create and edit content |
| `posts.delete` / `pages.delete` | high | Trash and permanently delete |
| `media.read` | low | Browse the library |
| `media.write` | medium | Upload and edit metadata |
| `media.delete` | high | Remove files from disk |
| `users.read` | medium | View accounts, roles, emails (never hashes) |
| `users.write` | high | Create and edit accounts, including roles |
| `users.delete` | high | Permanently remove accounts |
| `comments.read` | low | View all comments including spam |
| `comments.write` | medium | Create comments, bypassing the spam guard |
| `comments.moderate` | medium | Approve, spam, trash, delete |
| `terms.read` / `terms.write` | low / medium | Read / manage taxonomies |
| `menus.read` / `menus.write` | low / medium | Read / manage navigation |
| `settings.read` | medium | View site-wide configuration |
| `settings.write` | high | Change site-wide configuration |
| `mail.send` | high | Send email from the site address |
| `http.outbound` | high | Contact external servers |
| `schedule` | medium | Run recurring background work |
| `db.raw` | high | Arbitrary SQL — bypasses everything else here |
| `agents` | high | List and command desktop agents |

**Always available, never declared:** an app's own cache, its own settings, and
its own log. That is the app's own storage — already namespaced to its slug and
dropped on uninstall. Asking an operator to approve an app for access to its own
data is noise that trains people to click through consent screens.

**A write implies the matching read.** An app granted `posts.write` can read
posts, because one that couldn't read a post to update it would be useless and
every author would just declare both anyway.

## What refusal looks like

API calls **degrade, they do not throw**. A denied call returns the same empty
result it would return if there were no data — `null`, `false`, `0`, or an empty
array — and writes a line to the app's log naming the missing permission. An
exception would turn a policy decision into a white screen on a page render.

The exceptions are `db()`, `schema()` and `agents()` on the base class, which
*do* throw. Every caller of those expects a real object and would fatal on a
null anyway, so a clear exception is the more useful failure.

Degrade gracefully rather than tripping a denial:

```php
if ($this->allowed('mail.send')) {
    $this->api()->mail()->send($to, $subject, $html);
}
```

## Consent

Activating an app that declares permissions sends the operator to a consent
screen first. They can withhold individual items — the app then runs with less,
which may break parts of it. That is the operator's call, and each refusal is
logged so the cause is findable.

**An update that adds a permission requires re-approval.** Otherwise an update
could silently widen what an app can do.

Grants survive deactivation and are dropped on uninstall. Toggling an app off and
on again should not mean re-approving it.

## Logs

Each app writes to `storage/logs/apps/{slug}-YYYY-MM-DD.log`, readable at
**Admin → Apps → Logs**. Files are pruned after 7 days and a single file is
rotated aside at 2 MB — shared hosting disk is finite, and losing old log lines
is a far better failure than a site that can no longer write uploads.

Warnings and above are mirrored into the core log, so an operator reading the
main log still sees an app in trouble. Info and debug stay out of it — that noise
is what the split was meant to remove.

## The scanner

On install, and on demand, Basehim regex-scans an app's PHP for constructs that
reach around the broker: `new PDO`, `eval`, shell execution, direct `.env` reads,
disabled TLS verification, and a few others.

It is a heuristic, not a verdict. It does not follow variables and anything
determined defeats it in a line of obfuscation. Several flagged patterns have
entirely proper uses. **Findings never block an install** — a false positive
refusing a legitimate app would be worse than a flag an operator can read and
judge. The point is that a surprise becomes a decision rather than a discovery.
