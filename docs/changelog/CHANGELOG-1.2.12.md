# Basehim 1.2.12 — alt text from apps

## Apps' alt text was dropped

`MediaApi::uploadFromPath($path, $filename, $authorId, $meta)` passed `$meta`
straight to the uploader, which reads `alt_text`. Every app that saves to the
media library — Component Builder, Image Studio, Office, Photo Studio, Pinout
Maker, Video Editor — passes `alt`, which nothing read. Everything those apps
saved had no alt text: 18 of 23 media items on one live site.

`$meta` was never documented, which is how six apps came to guess the same
wrong name. Rather than change six apps, core now accepts both:

- **`alt` is the short form of `alt_text`**, in `uploadFromPath()` and in
  `update()`. If both are given, `alt_text` wins.

Existing media keeps whatever alt text it has — what the apps sent was never
stored, so it cannot be recovered. It can be added in the media library.

## `update()` silently dropped width, height and file size

`MediaApi::update()` accepted only `title`, `alt_text`, `caption` and
`description`. Image Studio, after editing an image in place, updates the new
`width`, `height` and `file_size` — all three were discarded, so an edited
image kept its old dimensions in the library and on the page.

`update()` now also accepts `width`, `height` and `file_size`, as whole numbers
of zero or more. Anything else is still ignored — the file path and URL cannot
be changed through it.

## Documented

`docs/APP-API.md` shows the `$meta` keys for `uploadFromPath()` — `title`,
`alt_text` (or `alt`), `caption` — and the fields `update()` accepts.

## Verified on a live install, through an app's own API object

- Upload with `alt` → stored as alt text; caption kept.
- Both `alt` and `alt_text` → `alt_text` kept.
- `alt_text` alone → unchanged behaviour.
- `update()` with `alt`, `width`, `height`, `file_size` → all stored.
- `update()` with a non-number width, a negative height, a file path and a URL
  → refused; nothing changed.

## Files

    app/Core/Api/MediaApi.php    alt → alt_text; width/height/file_size in update()
    docs/APP-API.md              $meta and update() fields documented
