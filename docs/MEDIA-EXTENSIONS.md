# Media and uploads for apps

Since 1.2.87 an app can take files, add them to the media library, change
how uploads are checked and stored, and show its own information on library
items, all without touching core. This page covers:

- [Uploads through the AJAX gateway](#uploads-through-the-ajax-gateway):
  `'files'` on an action, `UploadedFile`, `Basehim.ajax.upload()`
- [Private storage](#private-storage): `$this->appStorageDir()`
- [The media library from an app](#the-media-library-from-an-app):
  `fromUpload()`, `replaceFile()`, `regenerate()`, `path()`, `sizes()`
- [Hooks and filters](#hooks-and-filters) on every upload, change and delete
- [In the browser](#in-the-browser): `BasehimMedia.uploadFile()`, upload
  filters, events, item badges
- [Moving an app's own upload route over](#moving-an-apps-own-upload-route-over)

Everything here is backward compatible. An app that uses none of it behaves
exactly as before, and `POST /admin/media/upload` still works for older
callers.

## Uploads through the AJAX gateway

Until 1.2.87 the gateway took JSON only, so every app that accepted a file
wrote its own route. Each one repeated the CSRF check, `$_FILES` error
handling, size and type checks, and its own JavaScript. Now an action
declares its files and the gateway does all of that:

```php
public function boot(): void
{
    $this->ajax('import', [
        'handler'    => [$this, 'import'],
        'capability' => 'manage_options',
        'input'      => ['note' => 'string|max:200'],
        'files'      => [
            'file'  => ['types' => ['zip'], 'max_size' => 50 * 1024 * 1024],
            'cover' => ['types' => ['png', 'jpg'], 'required' => false, 'mimes' => ['image/*']],
        ],
    ]);
}

public function import(AjaxContext $ctx): array
{
    $zip = $ctx->file('file');                 // checked; never null when required
    $path = $zip->moveTo($this->appStorageDir('imports'), 'import-' . date('Ymd-His') . '.zip');
    return ['stored' => basename($path), 'size' => $zip->size(), 'sha256' => hash_file('sha256', $path)];
}
```

```js
Basehim.ajax.upload('my-app.import', { note: 'March' }, { file: input.files[0] }, {
    onProgress: function (percent) { bar.style.width = percent + '%'; }
}).then(function (data) { … }).catch(function (err) { bhToast(err.message, 'error'); });
```

### File rules

| Option | Default | |
|---|---|---|
| `types` | — | Required. A list of extensions (`['zip']`), or `'media'` for the site's media types (Settings → Media, after the `media.upload.allowed_types` filter; for an action whose input has a `context` field, with that context, else `ajax:{action}`). |
| `max_size` | `'media'` | Bytes (up to 2 GB), or `'media'` for the site's upload limit. PHP's `upload_max_filesize` and `post_max_size` apply first. |
| `required` | `true` | |
| `mimes` | `[]` | Content types the file must have, detected from its bytes: `'application/zip'`, `'image/*'`. |

Up to 10 fields per action, one file per field. Only signed-in POST actions
can declare files. Extensions that run on a web server (php, phtml, html, js,
…, the `MediaService::NEVER_ALLOWED` list) can never be declared.

### What the gateway checks

A multipart request goes through the same checks as a JSON one, in the same
order: session, origin, CSRF header, capability, area, rate limit, input
schema, idempotency key. Then, for every file:

- it arrived whole (PHP upload errors become plain sentences) and is a file
  PHP received in this request;
- its extension is one the field accepts, and it is within the size limit;
- its content matches its name: no script or web page under any name, a
  real image under an image extension, an SVG without scripts (SvgGuard);
- its detected type matches `mimes`, when given;
- no undeclared file field was sent.

Any failure is a 422 `VALIDATION_FAILED` with a message per field in
`error.fields`. A body PHP dropped for exceeding `post_max_size` is 413
`UPLOAD_TOO_LARGE`, with the limit named. Multipart sent to an action without
`files` is 415, as before.

The form has two fields besides the files: `action` and `data` (the JSON
object a JSON request would send). Any other field is refused (400). The
token goes in the `X-CSRF-Token` header, never in the form.

### UploadedFile

| Method | |
|---|---|
| `name()` | The browser's file name as a bare, printable name (folders and control characters removed). For display. |
| `extension()` | Lower-case extension of that name. |
| `size()` | Bytes that arrived (not what the browser claimed). |
| `mime()` | Detected from the content. |
| `sha256()` | |
| `imageSize()` | `[width, height]` for a raster image, else null. |
| `path()` | The temporary file, for reading (ZipArchive, hashing). Don't move or delete it by hand. |
| `contents($max)` | The bytes, refusing files over `$max` (16 MB by default). |
| `moveTo($dir, $name = null, $overwrite = false)` | Move it into `$dir` (created if needed) and return the path. `$name` must be a plain file name like `release-1.2.0.zip`, with no part a web server would run (`x.php`, `x.php.zip` and `x.html` are refused); by default a safe ASCII form of the browser's name. An existing file is not replaced unless `$overwrite` is true. |

A file the handler doesn't move is deleted by PHP when the request ends.

## Private storage

```php
$dir = $this->appStorageDir('releases');   // storage/apps/{slug}/releases, created on first use
```

`storage/` is never served by the web server (the root `.htaccess` forbids
it), and `appStorageDir()` also writes a deny-all `.htaccess` and an empty
`index.html` into `storage/apps/{slug}`. Use it for anything that is not
media: packages, imports, exports, backups. The argument is one or more
lower-case folder names separated by `/`.

Files that visitors should download through your app (a keyed download, say)
are streamed by your route after its own checks; they never need to sit in a
public folder.

## The media library from an app

`$this->api()->media()` gained these in 1.2.87:

| Method | Permission | |
|---|---|---|
| `fromUpload(UploadedFile $f, array $meta = [], ?int $authorId = null, ?string $context = null)` | `media.write` | Add a file sent to one of your AJAX actions to the library. Every library check and filter applies (context `app:{slug}` unless you give one). The signed-in person must hold `upload_media`, whatever capability your action asked for, and owns the item. Returns the row, or null. |
| `replaceFile(int $id, string $path, array $opts = [])` | `media.write` | Replace an item's file in place: same name, URL and row; size and dimensions updated; generated sizes rebuilt. Options: `regenerate` (default true), `expect_sha1` (refuse if the file changed since you read it). Returns the updated row, or null. |
| `regenerate(int $id)` | `media.write` | Rebuild an item's generated sizes: `{status, variants, item}`. |
| `path(int $id)` | `media.read` | Absolute path of the item's file on this server, or null (missing, remote storage, or a stored path that would lead outside `storage/uploads`). The only safe way to find a media file. |
| `sizes(string $mime = '')` | `media.read` | The size definitions `{name: {w, h, crop}}`, after the `media.sizes` filter. |
| `uploadRules(?string $context = null)` | none | `{types, max_bytes}` an upload in that context may have. |
| `lastError()` | none | Why the last `fromUpload()`, `replaceFile()` or `regenerate()` returned null, as a sentence you can show the person. |

`replaceFile()` keeps no copy of the old file. If your app may need to undo
the change, back it up first (to `appStorageDir()`). The new file must have
the same content type as the old one: a PNG replaces a PNG. To change format,
add a new item. The swap goes through a temporary file next to the original
and one `rename()`, so a visitor never sees half a file.

## Hooks and filters

All of them run for every way into the library: the media screen, the
editor, `media.upload`, the REST API, `uploadFromPath()` and `fromUpload()`.
Filters that receive `$info` get `['path', 'name', 'ext', 'mime', 'size',
'author_id', 'context']` for the file being uploaded. Those that receive
`$ctx` get `['context', 'author_id', 'source']` (`source` is `upload` or
`import`).

`context` says where an upload started: `library`, `editor`, `picker`, or
anything else a screen passes to `uploadFile()` (lower-case, no colon);
`core:api` for the REST API; `core:import` for `uploadFromPath()`;
`app:{slug}` for `fromUpload()`; `ajax:{action}` for an action's own `'media'`
rules. Contexts with a colon are set by the server and can't be sent by a
browser, so widen rules for trusted sources on those. Treat the others as a
hint about the screen, not as proof of anything: anyone who may upload can
send them.

### Filters

| Filter | Value → return | Arguments | |
|---|---|---|---|
| `media.upload.allowed_types` | `string[]` extensions | `$context` | Widen or narrow what may be uploaded. NEVER_ALLOWED extensions are removed whatever you return. |
| `media.upload.max_bytes` | `int` | `$context` | The size limit. |
| `media.upload.check` | `null` → `null` or a sentence | `$info`, `$ctx` | Refuse a file, e.g. "Product images must be at least 1200 px wide." Core's own checks have already passed. Fails closed: if a callback throws (a scanner that is down), the file is refused. |
| `media.upload.file` | path → path | `$info`, `$ctx` | Store a different file instead: a compressed, converted or watermarked copy your app wrote. It goes through the same checks again and must keep the extension. Core copies it; your file is yours to delete. |
| `media.upload.filename` | name → name | `$info`, `$ctx` | The name to store it under. The extension stays the file's own; the name is made safe and unique as usual. |
| `media.upload.directory` | `'2026/10'` → path | `$info`, `$ctx` | The folder under `storage/uploads`: lower-case words and slashes, at most five levels. Anything else is ignored. |
| `media.upload.row` | row → row | `$info`, `$ctx` | Title, alt text, caption, description. Changes to any other column are ignored. |
| `media.sizes` | `{name: {w, h, crop}}` | `$mime` | Add, change or remove generated sizes (lower-case names, 1–10000 px, at most 12). Applies to new uploads, regeneration and `replaceFile()`. |
| `media.item` | row → row | — | Add data the admin screens show, under `$item['ext']['your-slug']` (scalars and arrays, 8 KB per item). Other changes are ignored. `media.list`, `media.get` and `media.upload` return it. |

### Actions

| Action | Arguments | When |
|---|---|---|
| `media.uploaded` | `$row`, `$ctx` (second argument since 1.2.87) | After a new item is stored. |
| `media.updated` | `$row`, `$fields` (names changed) | After its title, alt text, caption or description changed. |
| `media.file_replaced` | `$newRow`, `$oldRow` | After `replaceFile()`. |
| `media.sizes_generated` | `$row`, `$sizes` | After an item's sizes were (re)built. |
| `media.deleting` | `$row` | Before delete, while the row and files still exist. |
| `media.deleted` | `$row` | After delete. |

```php
// Social cards for every image
$this->addFilter('media.sizes', function (array $sizes) {
    $sizes['social'] = ['w' => 1200, 'h' => 630, 'crop' => true];
    return $sizes;
});

// Product photos go in their own folder and must be big enough
$this->addFilter('media.upload.directory', fn(string $dir, array $info, array $ctx) =>
    $ctx['context'] === 'products' ? 'products/' . date('Y') : $dir, 10, 3);
$this->addFilter('media.upload.check', function ($veto, array $info, array $ctx) {
    if ($ctx['context'] !== 'products' || !str_starts_with($info['mime'], 'image/')) return $veto;
    [$w] = getimagesize($info['path']) ?: [0];
    return $w < 1200 ? 'Product photos must be at least 1200 px wide.' : $veto;
}, 10, 3);

// Show something on library cards
$this->addFilter('media.item', function (array $item) {
    $state = $this->stateFor((int) $item['id']);
    if ($state) $item['ext'][$this->slug] = ['badge' => '−' . $state['percent'] . '%', 'tone' => 'green'];
    return $item;
});
```

When a callback throws, `media.upload.check` refuses the file. For the
other filters, as for every filter in Basehim, the error is logged and the
value is passed on unchanged (the next app's filter still runs). So a
`media.upload.file` filter that fails stores the original, and a failing
`media.upload.row` or `media.item` changes nothing. Keep them fast: they run
inside the upload request.

A size a `media.sizes` filter adds is never written over an existing file:
names are reserved for every size of the file's type, and a generated size
whose name another item's file already has is skipped.

## In the browser

### BasehimMedia.uploadFile(file, options)

Every admin upload (media screen, editor, pickers) goes through this, and
from 1.2.87 it sends to the gateway's `media.upload`, three at a time; one the
rate limit refuses is tried again after a pause. It falls back to the old
route only when the page's `ajax.js` has no upload support. The promise still
resolves with `{ success: true, data: item }`.

| Option | |
|---|---|
| `title`, `alt_text`, `caption`, `name_hint` | As before. |
| `context` | Where the upload starts (`'editor'`), for the server's filters. |
| `onProgress(percent)` | |
| `onStatus(text)` | A note from an upload filter, shown under the progress row. |
| `signal` | An `AbortSignal` that cancels the upload. |
| `legacy: true` | Force the old route. |

### Upload filters

Change a file before it is sent, e.g. compress it in the browser:

```js
BasehimMedia.addUploadFilter(function (file, ctx) {
    // ctx.original, ctx.opts, ctx.status(text)
    return file;                    // a File/Blob, or a Promise of one
});
```

Filters run in order. A rejection stops that upload with its message. A
return value that isn't a Blob keeps the file unchanged. The server checks
whatever arrives exactly as before; a browser filter is never a security
boundary.

### Events

On `document`, for every upload:

| Event | `detail` |
|---|---|
| `bh:media-upload-start` | `{ file, original, context }` |
| `bh:media-uploaded` | `{ response, item, file, original, context }` |
| `bh:media-upload-failed` | `{ error, file, original, context }` |

### Item badges

Show the data your `media.item` filter added on library and picker cards:

```js
BasehimMedia.addItemBadge(function (item) {
    var x = item.ext && item.ext['my-app'];
    return x ? { text: x.badge, tone: x.tone, title: 'Saved by My App' } : null;
});
```

Return null, one badge or a list. At most three show per card. Text is shown
as text, never HTML, and is cut at 24 characters. `tone` is gray, green,
amber, red or blue.

### Basehim.ajax.upload(action, data, files, options)

For your own upload actions. `files` is `{ field: File }`, or a single File
for the field `file`. The options are those of `Basehim.ajax.request()`
except `method`: `onProgress(percent, loaded, total)`, `signal`,
`idempotencyKey`, and `timeout` (none by default). Errors are `AjaxError`s
with `code`, `message`, `status`, `fields` and `requestId`, plus
`UPLOAD_TOO_LARGE` when a web server refuses the size. A stale CSRF token is
refreshed and the upload retried once.

## Moving an app's own upload route over

A typical pre-1.2.87 handler:

```php
$this->adminPost('/my-app/releases/upload', [$this, 'releasesUpload']);

public function releasesUpload(Request $request): Response
{
    if (!$this->verifyCsrf($request)) return Response::json(['ok' => false, 'error' => 'CSRF'], 419);
    if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        return Response::json(['ok' => false, 'error' => 'Choose a release zip to upload']);
    }
    $tmp = $_FILES['file']['tmp_name'];
    // … validate version, inspect zip …
    move_uploaded_file($tmp, BASEHIM_ROOT . '/storage/my-app/releases/' . $file);
    return Response::json(['ok' => true, 'version' => $version]);
}
```

The same as an action:

```php
$this->ajax('release-upload', [
    'handler'    => [$this, 'releaseUpload'],
    'capability' => 'manage_options',
    'area'       => '/admin/my-app',
    'idempotent' => true,
    'rate_limit' => ['max' => 20, 'per' => 600],
    'input'      => [
        'version' => ['type' => 'string', 'required' => true, 'regex' => '/^\d+\.\d+\.\d+$/'],
        'notes'   => 'string|max:20000',
    ],
    'files'      => ['file' => ['types' => ['zip'], 'max_size' => 200 * 1024 * 1024, 'mimes' => ['application/zip']]],
]);

public function releaseUpload(AjaxContext $ctx): array
{
    $zip = $ctx->file('file');
    $shape = $this->inspectZip($zip->path());              // read it where it is
    if (!$shape['complete'] && $shape['entries'] === 0) {
        throw AjaxException::invalid('That file is not a readable zip.', ['file' => ['Not a readable zip.']]);
    }
    $version = (string) $ctx->input('version');
    $path = $zip->moveTo($this->appStorageDir('releases'), 'release-' . $version . '.zip');
    return ['version' => $version, 'size' => filesize($path), 'sha256' => hash_file('sha256', $path)];
}
```

```js
Basehim.ajax.upload('my-app.release-upload', { version: v, notes: n }, { file: input.files[0] }, {
    idempotencyKey: true,
    onProgress: function (p) { progress.textContent = p + '%'; }
}).then(function (d) { bhToast('v' + d.version + ' uploaded'); })
  .catch(function (err) { bhToast(err.message, 'error'); });
```

What goes away: the CSRF check, `$_FILES` and its error codes, the size
check, the "file too large for the server?" guess in the browser, and the
`{ok, error}` envelope. Errors arrive as `AjaxError` with a message per field,
and an upload retried with the same idempotency key is not stored twice.
Keep the old route for a while if other sites or scripts post to it.
