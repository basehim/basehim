# Basehim 1.1.3 — the Circuits DIY theme, and menu_at()

## A theme could only reach two menus

Core passes `$primary_menu` and `$footer_menu` into every template. A theme that
declares its own locations in `theme.json` — a utility bar, three footer columns
— had no way to reach them. The variables simply did not exist, so those menus
rendered empty with nothing to indicate why.

**`menu_at('location')`** returns the items for any declared location, and an
empty array when nothing is assigned, so a template can call it unconditionally:

    <?= menu_html(menu_at('utility')) ?>

Found while building a theme with five menu locations, which is the sort of gap
that only shows up when something real is built against the framework.

## Circuits DIY theme

A publication theme for an electronics site: a dense project grid, a category
mega menu, a utility bar, and a four-column footer.

**Twenty-five options across five Customizer sections** — accent and hover
colours, header, utility bar and footer backgrounds, body and heading fonts,
base text size, page width, cards per row, card style, and toggles for
excerpts, author, views, date and reading time.

Every colour, font and dimension reaches the stylesheet as a CSS custom
property, so the live preview updates them without a reload. The theme reads no
setting in PHP in order to style itself.

### Details worth knowing

**The mega menu is core's `menu_html()`, restyled.** A parent whose children
have children becomes a multi-column panel rather than cascading dropdowns —
a category tree three levels deep as nested menus is close to unusable with a
mouse. The theme supplies CSS; the markup and the open/close behaviour are
core's.

**Pagination is windowed.** The site it was built for has 293 pages, so a full
list is not an option. First, last, and a window around the current page.

**Card excerpts are clamped to three lines**, so one long excerpt cannot make a
card taller than its row.

**Code blocks set `tab-size: 4`.** The default of eight columns wrecks an
Arduino sketch, and this is a site made largely of sketches.

**Social links only render when filled in.** A row of grey icons linking nowhere
looks like a site that has been abandoned.

The utility bar is hidden below 900px — every page in it is also in the footer,
and on a phone the space is worth more than the links.
