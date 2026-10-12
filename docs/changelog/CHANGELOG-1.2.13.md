# Basehim 1.2.13 — image sizes for themes, batch thumbnails, Recent Posts options

## Thumbnails could not be generated on a large library

Core makes a thumbnail (square crop), medium and large version of every
uploaded image. Images that came in another way — the WordPress migrator, for
one — have none, and **Settings → Media → Regenerate thumbnails** rebuilt every
image in one request. On a library of a couple of thousand images that request
outlasts any web server's time limit, so it failed; one live site had sizes for
1 image out of 2,083.

Regeneration now runs in batches:

- The button starts a run with a progress bar: *n of total images, thumbnails
  made, failed, skipped*. Each request works for about eight seconds and says
  where to continue, so no request can time out.
- **Only images without thumbnails** (the default) resumes where an earlier
  run stopped; **All images** rebuilds everything after the sizes change.
- **Stop** ends a run; starting again continues from there.
- Without JavaScript the old one-request button is still offered.

New: `MediaService::regenerateBatch()`, `MediaService::imageCounts()`,
`MediaRepository::imageBatch()`, `MediaRepository::imageCount()`, and
`POST /admin/settings/media/regenerate-batch` (JSON). `regenerateAll()` stays,
for the command line; both share one per-image routine.

## Image sizes for themes

Posts loaded by core now carry their featured image's sizes
(`featured_sizes`: name → url, width, height), fetched in the same query, plus
the original's `featured_width` / `featured_height`.

New template helpers:

    bh_post_image($post, 'medium', ['class' => …, 'sizes' => …])
        A responsive <img>: src is the size asked for, srcset lists the other
        sizes of the same shape so the browser downloads the smallest sharp
        file, with width/height (no layout shift) and loading="lazy".
        The square-cropped thumbnail is never mixed into another size's srcset.
    bh_image_url($post, 'thumbnail')   one size, falling back to the original
    bh_image_sizes($post)              every size, smallest first

Documented in docs/THEME-DEVELOPMENT.md.

## Recent Posts widget

New options:

- **Show:** latest, most viewed or most commented posts.
- **From category:** any one category, or all.
- **Show thumbnails**, with **Thumbnail size:** small (a square beside the
  title) or medium (a wide image above it). Posts without an image get a
  neutral placeholder so the list stays aligned.
- **Show the date**, **Show a short excerpt**.
- **Leave out the post being read** — on by default.

A widget saved before this release keeps exactly the markup it had — a plain
list of links — unless a new option is turned on; the only change is that the
post being read is left out of the list. Default styles for the new layouts
are in `:where()`, so any theme rule wins.

The widget form now accepts a function as a select field's `options`, called
only when the form is shown — the category list is not queried on every page.

## Files

    app/Services/MediaService.php          regenerateBatch(), imageCounts(), one per-image routine
    app/Repositories/MediaRepository.php   imageBatch(), imageCount()
    app/Repositories/PostRepository.php    featured sizes in every post query; widgetList()
    app/Http/Controllers/Admin/SettingController.php   batch endpoint; counts for the media screen
    routes/admin.php                       the batch route
    admin/views/settings/media.php         progress-bar regeneration
    admin/views/widgets/areas.php          select options may be a function
    app/Core/Application.php               Recent Posts widget options and styles
    bootstrap.php                          bh_post_image(), bh_image_url(), bh_image_sizes()
    docs/THEME-DEVELOPMENT.md              featured images in the right size
