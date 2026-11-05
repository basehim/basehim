# Basehim 1.2.99 — 2026-11-05

Mail fixes, data table row actions by density, Customizer site identity, and
the home page title. Fixes BH-091, BH-092, BH-093, BH-098 and BH-103. No
database changes; new settings take their defaults until saved.

## Mail (`app/Services/Mailer.php`)

- **BH-091: lines over 998 characters.** Both parts are now sent
  `Content-Transfer-Encoding: quoted-printable` (`quoted_printable_encode`,
  CRLF line ends), so no line passes 76 characters. Before, the template's
  HTML was one line sent as `8bit`, and relays broke long messages at
  arbitrary points (inside tags or links) or refused them. A line that is a
  lone `.` is encoded as `=2E`, so sendmail set up without `-i` doesn't end
  the message early.
- **BH-092: no Message-ID.** Every message gets
  `Message-ID: <32 hex characters@site domain>`. A `Message-ID` passed in an
  app's `headers` option is kept instead.
- **BH-093: From name not quoted.** Addresses are built by `formatAddress()`:
  a display name with RFC 5322 specials (`, ( ) < > [ ] : ; @ \ . "`) is
  quoted with `\` and `"` escaped; non-ASCII names are encoded as before.
  Reply-To and To accept `Name <address>` and go through the same function.
  Line breaks in names, addresses and the subject are replaced with spaces,
  so a value can never add a header.

## Data table row actions (`datatable.js`, `datatable.css`)

A row's actions come from one list (`rowItems()`), shown according to the
Screen Options **Density**:

- **Comfortable** (the default): text links without icons (View or Preview,
  Edit, Quick Edit, declared actions, Publish or Move to Draft, Move to
  Trash) on a line of their own under the row (`tr.bh-dt-actrow`), across
  every column after the checkbox. The line is shown while either line is
  hovered or focused, always on touch screens, and keeps its space while
  hidden, so hovering doesn't move anything. The ⋮ column is hidden. A long
  list wraps without a divider at the start of a line. Destructive actions
  are red.
- **Compact**: the ⋮ menu, now darker (`#475569`) and larger (20 px), with
  an open state.

Cards (List, Grid, phones) keep the ⋮. Quick Edit returns focus to whichever
control the density shows. Selecting a row marks both lines.

## Customizer: site identity

- **BH-098:** "Site description" is now **Site tagline**, as in Settings →
  General and the installer.
- **Logo width:** a slider from Auto (0, the theme's own size) to 400 px.
  The preview updates live, without a reload. Core options with
  `preview: css` now become CSS variables too: `--bh-logo-width` plus
  `--bh-logo-height: auto`, through the new `css_also` option key. The new
  `zero_label` key means a 0 adds no variable and shows "Auto". Themes may
  also use `zero_label` and `heading`.
- **Show in the header:** **Site name** and **Site tagline** switches, both
  on by default (`appearance.show_site_title`, `appearance.show_tagline`).
  Themes receive `$show_site_title`, `$show_tagline` and `$logo_width`
  (documented in `docs/THEME-DEVELOPMENT.md`). The default and Dark Night
  themes honour them, and their header rows grow for a large logo. With the
  name hidden and no logo, the home link keeps an `aria-label`.
- Toggle options use core's switch (`bh-switch`) at the right of their
  label, instead of a checkbox reading "Enabled". The new `heading` option
  key draws a group heading.
- **BH-103:** logo and site icon previews show the whole image, contained
  on a checkerboard; the site icon box is square. The box takes its height
  from `aspect-ratio`, which `max-height: 100%` couldn't resolve, so a 512 px
  image overflowed and was cropped. A narrowed media picker's type filter
  says "All images" instead of "All types".
- The live CSS-variable preview no longer puts Custom CSS inside
  `:root{}`, where it could break the declarations after it.

## Home page title (`SeoHeadService`)

The home page's `<title>` is now **Site name {separator} Tagline**, using
the separator from Settings → SEO, or just the name when there is no
tagline. This also applies to a static home page without its own SEO title.
Settings → SEO → Home page → Title, and a page's own SEO title, still take
priority. Before, the home page showed only the site name unless the
default title format included `%tagline%`.

## Files

`app/Services/Mailer.php`, `app/Services/CustomizerService.php`,
`app/Services/SeoHeadService.php`,
`app/Http/Controllers/Web/RendersTheme.php`,
`admin/views/customizer/index.php`, `admin/views/settings/seo.php`,
`admin/assets/js/{datatable,customizer,media}.js`,
`admin/assets/css/{datatable,customizer}.css`,
`content/themes/default/{templates/partials/header.php,assets/theme.css}`,
`content/themes/dark-night/{templates/partials/header.php,assets/dark-night.css}`,
`docs/THEME-DEVELOPMENT.md`, `docs/DATA-TABLE.md`, `index.php`.

## Tests

- `tests/Unit/Release1299Test.php` covers:
  - mail: line lengths, the round trip, the lone dot, Message-ID, quoting and
    header injection;
  - Customizer options and CSS variables;
  - the switch markup and the image preview CSS;
  - the theme headers;
  - the density CSS.
- `tests/Integration/Release1299Test.php` covers:
  - the home title with a custom separator, with no tagline, and with a Home
    page title set;
  - the header switches and the logo variable on the live page;
  - the Customizer screen and saving.
- `tests/js/quick-edit.test.js` has four new cases:
  - the Comfortable links, their order and their own line;
  - View or Preview by status;
  - the links running Quick Edit, Publish and Trash;
  - Compact's ⋮ menu and focus return.
- MariaDB: 700 passed, 2 failed. Both failures are the known BH-047 ones.
