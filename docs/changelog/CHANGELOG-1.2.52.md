# Basehim 1.2.52 — HTML from authors is filtered

**Post HTML from anyone who is not an editor or administrator is now filtered
when it is saved.** Until now an author could publish a `<script>` or an
`onerror=` attribute. It ran on the public page, which shares an origin with
the admin, for any administrator who opened the post (audit finding B4,
reproduced). This release also removes a dead controller, and moves the
release changelogs out of the repository root. No database changes.

## Who may publish HTML as written: `unfiltered_html`

- New capability **`unfiltered_html`**, held by **editors and administrators**
  (`config/capabilities.php`; super admins have every capability).
- It can be granted to or denied for one person under **Users → edit →
  Individual permissions**, and given to custom roles (**Roles**).
- Grant it only to people you would trust with an administrator's session.

## What happens to everyone else's HTML

`PostService::create()` and `update()` filter the content and excerpt. Every
way a post is written goes through them: the editor's form save and its AJAX
draft save, the REST API, MCP, and apps using `$this->api()->posts()`. The
account is the one each caller passes; otherwise it is the request's signed-in
user. With no signed-in user, as in a scheduled task, the HTML is filtered.

`App\Services\HtmlSanitizer` is an allow-list:

- **Removed with their contents:** `script`, `style`, `noscript`, `template`,
  `svg`, `math`, `object`, `embed`, `textarea`, `select`, `button` and the
  other elements whose content is raw text or a plugin.
- **Unwrapped** (the text is kept): tags not on the list, such as `font` and
  `form`.
- **Attributes:** kept only when allowed. `on*` attributes always go.
  `data-*` and `aria-*` stay, except `data-bh-*`, which belongs to Basehim's
  own UI.
- **Links and sources:** only `http`, `https`, `mailto`, `tel` and relative
  addresses; images may also use `data:image/…`. The check decodes entities and
  drops control characters the way a browser does, so `java&Tab;script:` is
  caught.
- **iframes:** only from the embed providers the editor already knows
  (YouTube, Vimeo, Dailymotion, Spotify, Loom, CodePen, SoundCloud, Google
  Maps, TED), https only. Sites can add more with the `html.iframe_hosts`
  filter.
- **Inline styles:** `position` and anything that loads or runs code are
  removed. A style with CSS escapes or comments is dropped whole, because the
  check cannot read it.
- **Output:** always the re-serialized tree, never the input as typed. The
  parser on the server and the browser can disagree about malformed markup; a
  re-serialized tree means the same thing to both.

How each content format is handled (`App\Services\PostHtmlFilter`), judged
the way the public site renders it:

- **HTML:** filtered.
- **Blocks:** the `html` field of every block is filtered (the Custom HTML
  block, and app blocks rendered from saved HTML), nested blocks included. The
  rest of a block was already escaped by `BlockRenderer`.
- **Markdown:** kept exactly as written when its raw HTML is in an
  unambiguous safe subset (`<br>`, `<details>`, `<img src="https://…">`…). It
  is checked with `HtmlSanitizer::isSimpleMarkup()` against the parts
  `Markdown::rawHtmlParts()` reports the renderer would pass through. Markdown
  with any other raw HTML is stored as filtered HTML, because Markdown source
  cannot be filtered without changing its meaning. Code blocks never count.

The writer is told what was removed:

- **Editor:** a warning toast on Save draft, and a warning message after
  Save, Update or Publish.
- **REST:** a `warnings` array next to `data`.
- **MCP:** a `warning` field in the tool result.

Clients that ignore these fields get the same response as before.

**Existing posts are not changed** until someone saves them. To review older
posts by authors, look for `<script` and attributes such as `onerror=` or
`onclick=` in their content.

### For app developers

- `post.unfiltered_html` (filter): `(bool $allowed, ?array $user)`. Return
  `true` for HTML your app writes and trusts, for example from a scheduled
  task.
- `html.iframe_hosts` (filter): the list of `host/path` prefixes iframes may
  load.
- `PostService::create($data, $authorId, ?array $actor = null)` and
  `update($id, $data, ?array $actor = null)` take the acting account.
  `htmlReport()` says what the last call removed. Both changes are
  backward-compatible.

## Also in this release

- **Markdown links** now ignore control characters when checking for
  `javascript:`. This is defence in depth: the link syntax already kept them
  out of the address.
- **Removed `app/Http/Controllers/Web/CategoryPostController.php`.** No route
  used it, and it could not run: it called a helper that does not exist. Sites
  updated by patches keep the file, unused; patches cannot delete files.
- **Repository:** the 87 `CHANGELOG-*` and `RELEASE-NOTES-*` files moved from
  the root to `docs/changelog/`. `CHANGELOG.md` is now an index of releases.
  None of this changes a site.

## Tests

New:

- `tests/Unit/HtmlSanitizerTest.php` (9): what is kept, what is removed,
  stability, iframe hosts, the simple-markup test, Markdown, links and blocks.
- `tests/Integration/AuthorHtmlTest.php` (7): REST as author, editor and
  admin; per-user grants and denials; REST update; Markdown kept or
  converted; the editor's draft save; MCP. 6 of them fail without the filter.

Core suite **200 passed**; `devtools/regress.php` 63 passed. In the browser
(`devtools/e2e/author_html.py`), an author's Custom HTML block lost its script
and handler with a warning on Save draft and on Publish, and nothing ran on the
public page; an administrator's was kept.

## Files

New: `app/Services/HtmlSanitizer.php`, `app/Services/PostHtmlFilter.php`.

Changed: `app/Services/{PostService,Markdown}.php`,
`app/Http/Controllers/Admin/{PostController,RoleController,UserController}.php`,
`app/Http/Controllers/Ajax/PostActions.php`,
`app/Http/Controllers/Api/{PostController,McpController}.php`,
`admin/assets/js/block-editor.js`, `config/capabilities.php`,
`database/add-prefix.php` (comment), `docs/APP-DEVELOPMENT.md`,
`CHANGELOG.md`, `index.php`, `install.php`.

Removed: `app/Http/Controllers/Web/CategoryPostController.php`.
