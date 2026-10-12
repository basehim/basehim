# Basehim 1.2.44

## Uploads from the editor get SEO-friendly names

The block editor and the Media Library already share one naming function
(`MediaService::fileStem()`, since 1.2.x): "My Photo.PNG" becomes
`my-photo.png` either way. File names with no meaning stayed meaningless,
though. A phone photo (`1000477992.jpg`, `PXL_20260101_123456.jpg`,
`IMG_2034.JPG`), a pasted image (`image.png`), a screenshot, a WhatsApp image or
a UUID kept that name, so an image dropped into a post carried no keywords.

Uploads made from the post and page editor (image, gallery, cover, video,
audio and file blocks, the featured image, and files dropped or pasted on the
canvas) now send the post's title along. When the file's own name is
meaningless, the file is named after the post instead, and so is its title in
the library:

| Uploaded file | Post title | Stored as |
|---|---|---|
| `1000477992.jpg` | Best Hiking Trails | `best-hiking-trails.jpg` |
| `image.png` (pasted), second image | Best Hiking Trails | `best-hiking-trails-2.png` |
| `arduino-uno-pinout.png` | Best Hiking Trails | `arduino-uno-pinout.png` (unchanged) |
| `1000477992.jpg` | *(no title yet)* | `1000477992.jpg` (unchanged) |

Meaningful names are never touched. Uploads from the Media Library page have
no post to borrow from, so they're unchanged. A file whose name is in a
non-Latin script, which used to end up as plain `image.jpg`, now takes the
post's title too.

## Editor: the ⋮ menus close when tapped again

The block toolbar's "Options" button, the editor's top-right ⋮ menu and every
other toolbar dropdown could open their menu but not close it. A second tap
closed the menu and reopened it straight away. On a phone, where tapping
somewhere else is awkward, that left the menu stuck open. A second tap on the
same button now closes it, on touch and mouse alike.

## Social sharing uses the featured image

Facebook, X, LinkedIn, WhatsApp and Slack previews now show the post's image.
Core sets the share tags on every theme, so no theme changes are needed:

- The image is, in order: the social image chosen in the post's SEO
  settings (saved by the editor but never used until now), then the
  featured image.
- When there is one, it replaces whatever `og:image` / `twitter:image` the
  theme printed. Themes disagreed here: some always sent the site logo, some
  sent the featured image as a relative URL, which every crawler ignores, and
  the bundled themes sent no image at all.
- Added alongside: `og:image:width`, `og:image:height`, `og:image:alt` (the
  image's alt text, or the post title), `og:image:secure_url` on https,
  `twitter:image` and `twitter:image:alt`. URLs are always absolute.
- Filled in when the theme leaves them out: `twitter:card`
  (`summary_large_image` when there's an image), `twitter:title`,
  `twitter:description` and `og:url`.
- Pages without an image keep the theme's own tags (a site logo, for
  example).

Themes get the chosen image as `$seo['og_image']` (with `og_image_width`,
`og_image_height`, `og_image_alt`) if they want to use it themselves.

## Generator tag

`bh_head()` now prints `<meta name="generator" content="Basehim CMS">`. It
deliberately has no version number, so it doesn't advertise which fixes a site
is missing.

Files changed: `app/Services/MediaService.php`,
`app/Http/Controllers/Admin/MediaController.php`,
`app/Http/Controllers/Web/RendersTheme.php`, `bootstrap.php`,
`admin/assets/js/media.js`, `admin/assets/js/block-editor.js`,
`admin/views/posts/edit.php`, `index.php`, `install.php`.
