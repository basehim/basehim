# Basehim 1.2.4 — readable media file names

## Uploads keep their name

Every upload was stored under a random UUID, so `My Awesome Image.png` became
`0b21e089-f770-47a0-a099-6f8841e15e42.png`. The name is part of the image URL,
and a URL that says nothing is a wasted signal to image search and to anyone
reading a link.

Uploads are now named after the file:

    My Awesome Image.png     →  my-awesome-image.png
    Café Crème (1).JPG       →  cafe-creme-1.jpg
    Don't Stop.jpeg          →  dont-stop.jpeg
    shell.php.png            →  shell-php.png
    تصویر.png                →  image.png

Accents are transliterated, everything else becomes a hyphen, and the stem is
capped at 100 characters. A name with nothing transliterable in it falls back
to `image` or `file`. The extension is kept, lowercased.

The `uuid` column is untouched; it still identifies the row. Only the name on
disk and in the URL changed.

**Existing media is not renamed.** Its URLs are already in posts, in other
sites' links and in search indexes, and renaming would break all of them.

## Duplicates get a number

    my-awesome-image.png
    my-awesome-image-2.png
    my-awesome-image-3.png

Names are unique per upload directory (`YYYY/MM`, or the single flat directory
when "organise uploads" is off), checked against both the files on disk and
the media table.

### Thumbnails had to be part of the check

Thumbnails are named `{stem}-thumbnail`, `-medium` and `-large`. With random
names these could never collide; with human names they can, in both
directions:

- **A new file whose own thumbnails would overwrite something.** Uploading
  `photo.png` writes `photo-medium.png`. If an earlier upload was literally
  named `photo-medium.png`, it would be overwritten.
- **A new file that sits where an existing file's thumbnail belongs.**
  `Photo Thumbnail.png` would take the name `photo-thumbnail.png`, which is
  already the thumbnail of `photo.png`.

Both are now refused and the upload takes the next number: `photo-2.png` in the
first case, `photo-thumbnail-2.png` in the second. Each case was tested with
real uploads, and the file that would have been overwritten was confirmed
unchanged.

Stems are also unique across extensions: `photo.png` and `photo.jpg` in the
same month become `photo.png` and `photo-2.jpg`. With WebP conversion on, both
would otherwise write `photo-medium.webp`.

### Concurrent uploads

The name is chosen and claimed under an exclusive lock
(`storage/locks/media-names.lock`), and the claim is an exclusive create of the
file itself, which fails if the file exists. Five simultaneous uploads of the
same name produced `concurrency-test.png` through `concurrency-test-5.png`, no
two alike. If the lock cannot be taken, the exclusive create still guarantees
two originals never share a name.

A failed upload releases its name: the placeholder is removed if the move
fails, and the file and its thumbnails are removed if the database insert
fails.

## Two bugs in the same path

### Apps could not save to the media library at all

`MediaApi::uploadFromPath()` is how Image Studio, Photo Studio, Office, Pinout
Maker, Circuit Design, Component Builder and Video Editor save files. It passed
the app's file to `MediaService::upload()`, which begins with
`is_uploaded_file()` — true only for a file PHP received in the current HTTP
request, which an app-generated file never is. Every call failed with
"No file uploaded."

`MediaService::importFile()` now takes a file already on the server. It copies
the file rather than moving it, so the caller's cleanup still works, and
`uploadFromPath()` uses it. The HTTP path still requires `is_uploaded_file()`.

### `POST /api/v1/media` reported failure after succeeding

The REST controller treated `upload()`'s return value as an id and passed it to
`find(int $id)`. It is the created row, so `find()` threw a `TypeError`, and
the client received **422** after the file and row had been created. A client
that retried on error created duplicates. It now returns the row with **201**.

## Files

    app/Services/MediaService.php               naming, importFile()
    app/Repositories/MediaRepository.php        storagePathsLike()
    app/Core/Api/MediaApi.php                   uploadFromPath() → importFile()
    app/Http/Controllers/Api/MediaController.php   201 with the created row

No migration. No settings. No theme changes.
