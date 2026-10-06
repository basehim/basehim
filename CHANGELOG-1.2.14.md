# Basehim 1.2.14 — Recent Posts thumbnails: fill correctly, choose a shape

## Square thumbnails did not fill their box

The Recent Posts widget's default styles were all inside `:where()`, which has
no specificity. That lets a theme restyle the widget — but it also lost to a
theme's generic reset such as `img { height: auto; }`, which nearly every theme
has. The image's `height: 100%` was overridden, so it no longer filled its
square and the box showed a gap.

The rules that size the image itself are now outside `:where()`:

    .widget-recent-posts__thumb img { display:block; width:100%; height:100%; max-width:none; object-fit:cover }

This outranks a bare `img` reset, while any theme rule aimed at the widget
(`.sidebar .widget-recent-posts__thumb img …`) still wins. Everything else stays
in `:where()`.

## New option: Thumbnail shape

- **Square — cropped to fill** (the default, and what existing widgets keep)
- **Original shape — the whole image, nothing cropped**: a 16:9 picture stays
  16:9, a portrait stays tall
- **Wide 16:9 — cropped to fill**

It combines with **Thumbnail size** (small beside the title, medium above it).
Only a small square uses core's pre-cropped `thumbnail` file; every other
combination uses the uncropped `medium` image, so "original shape" is really the
whole picture.

The list gets a class for the shape (`widget-recent-posts--shape-square`,
`--shape-original`, `--shape-wide`) alongside the size class, for themes to
style.

Checked with a theme's `img { height: auto; max-width: 100% }` reset in place:
every size and shape fills its box (or keeps its shape) exactly.

## Files

    app/Core/Application.php    Recent Posts: thumbnail shape, image sizing that survives theme resets
