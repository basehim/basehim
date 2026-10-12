# Basehim 1.2.16 — Media library: thumbnails, progressive preview, Shift+click ranges

## The library downloaded every original

The Media library's grid, list and detail modal all showed each image's
original file — 60 full-size photos to draw one page of thumbnails. It now uses
the copies core generates (Settings → Media → Regenerate):

- **Grid (masonry and uniform):** the medium copy, with the large copy and the
  original in `srcset`, so a dense screen still gets a sharp picture.
- **List view:** the 150 px thumbnail.
- An SVG, or an image smaller than the medium size (so none was made), shows
  its original — which for those is already small.

The **Choose from Media Library** picker in the post editor shows the medium
copy too; what it inserts is still the original's URL.

## Detail modal: the original loads over a quick preview

Clicking an image opens the modal at once with the medium copy, slightly
blurred, and a *Loading original…* indicator; the original fades in when it has
downloaded. Opening another file before that finishes cancels the swap, so a
late download never replaces the wrong picture. If the original cannot be
loaded the indicator says so.

The modal lists the image's generated sizes — thumbnail, medium, large, each
with its dimensions and a link — or points to *Settings → Media → Regenerate*
when there are none.

## Shift+click selects a range

In select mode, click one file, then Shift+click another: everything between
them, in the order shown, takes the first file's state. So Shift+click after
selecting a file selects the range, and Shift+click after unselecting one clears
it. Works on the checkboxes, grid cards and list rows; Shift+click outside
select mode starts selecting. No text is highlighted while Shift-clicking.

## Fixed: Select did nothing visible

`setSelectMode()` began with `root.querySelector('.nml').classList` — but
`root` *is* the `.nml` element, so the lookup returned null and the function
threw on every click of **Select**. The bulk bar (with Delete) and the selecting
style never appeared. The line is removed.

## Fixed: the library script was cached forever

The Media page loaded `media-library.js?v=2`, a fixed version, so browsers could
keep an old copy after an update. It now carries the Basehim version, like the
other admin scripts.

## Checked in Chromium, on basehim.com's real library page and data

Grid: 11 of 12 cards the medium copy, 1 its original (216×216, smaller than
medium). List: 12 of 12 the thumbnail. Modal: medium, blurred, indicator shown
while the original was held back; original swapped in, blur and indicator gone
when released; a late original for a closed file ignored. Selection: click #1,
Shift+click #5 → 1–5; Shift+click #8 → 1–8; click #3 then Shift+click #6 →
1, 2, 7, 8. Select mode shows the bulk bar; no JavaScript errors.

## Files

    admin/assets/js/media-library.js    thumbnails, progressive modal, sizes list, Shift+click, Select fix
    admin/assets/js/media.js            the editor's picker shows the medium copy
    admin/assets/css/media-picker.css   loading indicator, sizes list
    admin/views/media/index.php         the library script carries the Basehim version
