# Basehim 1.2.87: media apps without touching core

## What was wrong

Every app that took a file had to step around core. The AJAX gateway accepted
JSON only (`MediaActions`: "Uploads stay on /admin/media/upload: the gateway
takes JSON only"), so CloudHim, for example, keeps three routes of its own
(release, theme and app zips). Each repeats the CSRF check, `$_FILES` and its
error codes, storage and a FormData helper, and when a file is too large the
browser can only guess why ("file too large for the server's upload
limit?").

The media library itself offered two hooks, `media.uploaded` and
`media.deleted`. An app could not:

- refuse an upload, or change which types and sizes are accepted;
- process a file before it is stored, or choose its name or folder;
- add a thumbnail size;
- replace a file in place (Image Compressor had to rewrite the file and then
  patch the row by hand, leaving the thumbnails stale);
- find an item's file safely, or show anything on library cards.

## What changed

### Uploads through the AJAX gateway

- `AjaxRegistry`: new option `files`, one rule per field (`types`: a list of
  extensions or `'media'`; `max_size`: bytes or `'media'`; `required`;
  `mimes`). Checked at registration. Extensions that run on a web server
  (`MediaService::NEVER_ALLOWED`) can never be declared. Signed-in POST
  actions only, at most 10 fields.
- `AjaxGateway`: an action with `files` also takes multipart/form-data, with
  the fields `action` and `data` (the JSON object) plus the files. All 14
  checks run as for JSON, and the CSRF token still comes from the header. Then
  every file is checked: arrived whole and really uploaded, extension, size,
  content matching the name (`MediaService::contentProblem()`, SvgGuard),
  optional `mimes`, no undeclared fields. Failures are 422 with a message per
  field. A body PHP dropped for `post_max_size` is 413 `UPLOAD_TOO_LARGE`,
  naming the limit. Multipart to an action without `files` is 415, as before.
  Files count towards the idempotency hash.
- New `App\Core\Ajax\UploadedFile` (`name`, `extension`, `size`, `mime`,
  `sha256`, `imageSize`, `path`, `contents`, `moveTo`). `AjaxContext::file()`
  and `files()`.
- New core action `media.upload` (multipart, field `file`; `title`,
  `alt_text`, `caption`, `name_hint`, `context`). `BasehimMedia.uploadFile()`
  uses it whenever the page's `ajax.js` has `upload()`, and still resolves
  with `{ success: true, data: item }`. `POST /admin/media/upload` stays for
  older callers.
- `ajax.js`: `Basehim.ajax.upload(action, data, files, options)` with
  progress, cancel, the usual `AjaxError`s, one retry on a stale token, and
  `UPLOAD_TOO_LARGE` for a web server's own 413 page.
- `App::appStorageDir($sub)`: a private folder under `storage/apps/{slug}`,
  with deny-all files written in. The name was checked against every app on
  cloudhim.com and in this repository (see BH-014), and is listed in
  `AppBaseClassTest`.

### Media hooks and methods

- Filters: `media.upload.allowed_types`, `media.upload.max_bytes`,
  `media.upload.check` (refuse with a sentence), `media.upload.file` (store a
  processed copy, checked again), `media.upload.filename`,
  `media.upload.directory` (safe paths only), `media.upload.row` (descriptive
  fields only), `media.sizes` (validated, at most 12), `media.item` (data
  under `ext`, 8 KB, for the admin screens).
- Actions: `media.uploaded` gains a second argument
  (`context`, `author_id`, `source`); new `media.updated`,
  `media.file_replaced`, `media.sizes_generated` and `media.deleting`.
- Uploads carry a `context`: `library`, `editor`, … from the screen (no
  colon, so a browser can't claim a server context); `api`; `import`;
  `app:{slug}`; `ajax:{action}`.
- `MediaService`: `uploadRules()`, `localPath()` (contained, local disk
  only), `regenerate()`, `replaceFile()` (same type, content checks, staged
  next to the original, atomic rename, row and thumbnails updated,
  `expect_sha1`), `decorate()`. `regenerateOne()` now finds files through
  `localPath()`. The pixel check is a method, `checkPixels()`, used for the
  upload, a filter's replacement and `replaceFile()`.
- `MediaApi`: `fromUpload()`, `replaceFile()`, `regenerate()` (all
  `media.write`), `path()`, `sizes()` (`media.read`), `uploadRules()` and
  `lastError()` (the reason the last call returned null, for the person).
- `media.list`, `media.get` and `media.upload` return decorated items. Library
  and picker cards show badges apps add with `BasehimMedia.addItemBadge()`
  (text only, five tones, three per card).
- `media.js`: upload filters (`addUploadFilter`, first in the Image
  Compressor work for this release), events `bh:media-upload-start`,
  `bh:media-uploaded` and `bh:media-upload-failed` for every upload, and
  options `context`, `signal` and `legacy`.
- The admin upload route and the REST API pass a context (`library` or the
  screen's, `api`).

Docs: new `docs/MEDIA-EXTENSIONS.md`; `APP-AJAX.md` (the `files` option,
Uploads, `media.upload`, limits), `APP-API.md`, `APP-DEVELOPMENT.md`.

### From the review before release

An independent review of this patch found, and these were fixed with tests:

- **Uploads would have failed on every site.** `Request::capture()` leaves
  the body empty for `/ajax` (the gateway reads JSON from `php://input`
  itself), so the multipart branch never saw `action` or `data`. The unit
  tests had built `Request` objects directly. `capture()` now passes `$_POST`
  for multipart requests to `/ajax`; a test goes through `capture()`.
- A size added by `media.sizes` for one type could overwrite another item's
  original: names were reserved with the general sizes only, and generated
  sizes were written over existing files. Names are now reserved for the
  file's own type as well, and `buildSizes()` never writes over a file.
- `media.upload` had no `area`, so a site that tightened `/admin/media` with
  `admin.area_policy` would still have allowed uploads through it.
  `'area' => '/admin/media'` added.
- `media.upload.check` passed a file through when its callback threw.
  It fails closed now (`HookRegistry::applyFiltersStrict()`); the docs no
  longer claim other filters stop an upload.
- The SVG guard on a filter's replacement and in `replaceFile()` looked at the
  detected type only (an SVG starting with a BOM is `text/plain`). It now also
  keys off the extension.
- The server contexts `import` and `api` could be sent by a browser. They are
  `core:import` and `core:api` now. The gateway resolves `'media'` rules with
  the action's own `context` input, so `media.upload` and the store agree.
- `replaceFile()` checked the source and then copied it. It now copies first,
  checks the copy, and refuses files over the upload limit.
- `UploadedFile::moveTo()` refuses names with executable parts (`x.php`,
  `x.php.zip`); `cleanName()` repairs Latin-1 names before stripping control
  characters (they lost their extension).
- `MediaApi::fromUpload()` requires the signed-in person to hold
  `upload_media`. The gateway now binds `auth.user` for handlers, as admin
  routes do; without it the new item was credited to user 1.
- `media.js` sends three uploads at a time and retries a rate-limited one;
  `media.upload` allows 300 a minute and accepts long titles (cut to 255, as
  the old route stored them).

## Compatibility

Nothing changes for an app that uses none of this. `uploadFile()` keeps its
response shape and falls back to the old route. `media.uploaded` listeners
registered with one argument get one. JSON requests to the gateway are
handled by the same code as before; the parsing moved into a helper,
`decodeObject()`, shared with the multipart path.

## Files

`app/Core/Request.php`, `app/Core/HookRegistry.php`,
`app/Core/Ajax/UploadedFile.php` (new), `app/Core/Ajax/AjaxGateway.php`,
`app/Core/Ajax/AjaxRegistry.php`, `app/Core/Ajax/AjaxAction.php`,
`app/Core/Ajax/AjaxContext.php`, `app/Core/App.php`,
`app/Core/Api/MediaApi.php`, `app/Core/Api/Resource.php`,
`app/Services/MediaService.php`, `app/Http/Controllers/Ajax/MediaActions.php`,
`app/Http/Controllers/Admin/MediaController.php`,
`app/Http/Controllers/Api/MediaController.php`, `routes/ajax.php`,
`admin/assets/js/ajax.js`, `admin/assets/js/media.js`,
`admin/assets/js/media-library.js`, `admin/assets/css/media-picker.css`,
`index.php`, `docs/MEDIA-EXTENSIONS.md` (new), `docs/APP-AJAX.md`,
`docs/APP-API.md`, `docs/APP-DEVELOPMENT.md`, `CHANGELOG.md`, this file and
the release notes.

## Tests

- `tests/Unit/AjaxUploadGatewayTest.php` (new) runs the real gateway in
  process: multipart with data and files; CSRF, origin, capability and
  sign-in enforced; 415 without `files`; only `action`/`data` form fields;
  every file rule (missing, type, no extension, size, script under a text
  name, text under an image name, undeclared field, PHP upload error);
  non-uploads refused; 413 for a dropped body and an oversized request;
  idempotency; `'media'` rules following settings and filters (php never
  allowed); core `media.upload` storing a real PNG with hooks and `ext`.
- `tests/Unit/MediaExtensionsTest.php` (new) runs MediaService on real files
  over an in-memory repository: file rules at registration; `UploadedFile`
  (errors, names, `moveTo()` containment and no overwrite, detected type);
  every filter and action above, including a refused file leaving no
  placeholder, a filter's replacement checked again (script, pixel limit),
  unsafe folders ignored, other columns protected, sizes validated;
  `localPath()` against traversal and symlinks; `replaceFile()` success
  (thumbnail rebuilt from the new pixels) and refusals leaving the original;
  `decorate()` limits; a failing row filter.
- `tests/js/media-upload.test.js` with `tests/Unit/MediaUploadJsTest.php`
  (new) runs the real `ajax.js` and `media.js`: the multipart format, progress,
  error envelopes, token refresh, 413 pages, cancel, `uploadFile()` through
  `media.upload` with context, events, filters and the old-route fallback,
  badge sanitising.
- `tests/Integration/MediaUploadGatewayTest.php` (new) over HTTP with the
  ajax-fixture app's new `upload` and `to-library` actions, and
  `Browser::ajaxUpload()` in the harness.
- `AppBaseClassTest` lists `appStorageDir`.
