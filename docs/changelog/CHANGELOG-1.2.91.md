# Basehim 1.2.91

2026-10-29 · Patch release · installs on 1.2.90

Fixes a regression in 1.2.90 that left some apps' screens unstyled and stuck
on "Loading…".

## App folder URLs carried a version tag (BH-070)

**Problem.** 1.2.90 (BH-049) made `App::asset()` add `?v={app version}` to
every URL it returns, including folder URLs. Many apps ask for their assets
folder once and append file names to it, in PHP or in JavaScript:

```php
$base = $this->asset('');          // 1.2.90: …/assets/?v=2.1.0
$css  = $base . 'css/compute.css'; // …/assets/?v=2.1.0css/compute.css
```

The result points at the folder with a query string, not at the file, so the
app's stylesheets and scripts didn't load. Found on a live site in Compute,
Local AI, Photo Studio and 22 other apps (31 places), and in Apps Migration on
staging.

**Fix.** `asset()` adds the tag only when the path ends in a file name with an
extension (`css/app.css`, `js/app.min.js`, `fonts/x.woff2`). An empty path, a
folder (`img`, `img/`), a dotfile, or a path that already has a query string
or fragment comes back exactly as in 1.2.89. The rule is a private helper, so the app base
class gains no new name an app could clash with.

Apps already patched by hand to strip `?v=` from the folder URL keep working:
there is nothing left to strip.

Files: `app/Core/App.php`, `docs/APP-DEVELOPMENT.md`.

## Tests

- `tests/Unit/Release1291Test.php` (new): which paths are versioned.
- `tests/Integration/Release1290Test.php`: new test over HTTP. Folder URLs from
  `asset('')`, `asset('/')`, `asset('img')` and `asset('img/')` carry no tag,
  an app's own `?v=` is kept, and folder URL + file name returns 200. It fails
  on 1.2.90 and passes on 1.2.91.
