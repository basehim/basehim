# Basehim 1.3.0

A new minor line: **Appearance**. Each person chooses light, dark or their
device's setting, and one of seven four-colour accents, saved on their
account and followed by the whole admin, the editor, the Customizer, the
media library, data tables and the public site's admin bar. Apps get a
supported way to follow it. A pre-release audit of every admin screen
fixed the contrast and dark-mode issues it found.

This is a **full release** (a complete tree): it installs over any 1.2.x
site. Released with Analytics 1.13.0, which needs it.

No database changes. The choice is stored in user meta
(`admin_appearance`); a person who never chooses gets System (their
device's light or dark) and Default.

## Appearance

### What a person chooses

The account menu (avatar, top right) has an **Appearance** section:

- **Mode:** Light, Dark or System (follows the device, and changes with it;
  the default until someone chooses).
- **Accent:** Default (Tailwind's blue), Sunset, Rose, Amphibian, Strawberry,
  Kiwi, Pink LA. The picker shows each palette's two lead colours as
  overlapping circles; choosing one opens and closes the list smoothly
  (`grid-template-rows` 0fr → 1fr, none with reduced motion).

Saved with `profile.appearance` (AJAX, `read` capability, 30 a minute; `mode`
and `accent` validated against the lists) in `AppearanceService`; the page
changes at once, without a reload.

### How the colours work (`tailwind.theme.js`)

- Every colour is a CSS variable, one per role: `--bh-bg-*`, `--bh-tx-*`,
  `--bh-bd-*` (background, text and border need different dark tones: a pale
  fill becomes a dark tint, dark text becomes light text, white text stays
  white). Tailwind utilities and `theme()` resolve to `rgb(var(--…) / α)`, so
  `bg-slate-100/50` and the rest keep working.
- `<html data-theme data-appearance data-accent>` switches them. For System
  the server can't know the device, so `partials/appearance-head.php` sets
  `data-theme` before the first paint and follows `prefers-color-scheme`.
- Each accent is four colours plus `main` (the UI) and `second` (the picker
  and charts). Its 50–950 scale is worked out from the main colour: 500 is
  the colour, 600 the first shade that keeps white text at 4.5:1, so primary
  buttons and links are readable with any palette. `blue` in markup means the
  accent, so older views follow it.
- Plain names for hand-written CSS: `--bh-page`, `--bh-surface`,
  `--bh-raised`, `--bh-text`, `--bh-strong`, `--bh-muted`, `--bh-line`,
  `--bh-line-strong`, `--bh-accent`, `--bh-accent-hover`, `--bh-on-accent`,
  `--bh-accent-soft`, `--bh-accent-text`, `--bh-palette-1…4`, and status
  colours `--bh-success|warning|danger|info|neutral` with `-soft` / `-text`.
- `html { accent-color }` gives native checkboxes, radios and sliders the
  accent everywhere, app screens included.

### Built from sources now

Every admin stylesheet is compiled from `admin/assets/css/src/` with
Tailwind, so all colours are theme references: `ui.css`, `media-picker.css`,
`block-editor.css`, `customizer.css` are new sources; `admin-bar.css` builds
with its own config (`tailwind.adminbar.config.js`: no preflight, colours
scoped to `#bh-admin-bar`, its dialogs and toasts). `npm run build` runs
them all; each built file is reproducible byte for byte.

### Screens

- **Data tables:** chips and empty thumbnails follow the accent (were indigo);
  secondary text slate-500; the sticky controls, column header and pager are
  frosted glass (`.bh-glass`).
- **Media library and picker:** its own `--nml-*` colours mapped to the
  theme (the title and menus were unreadable in dark); icon colours are
  classes; on phones names wrap, details take 38vh, filters and Upload share
  a row.
- **Block editor:** greys moved to the admin's slate; pressed and selected
  controls use `--bhe-solid` (they used a text colour as a fill); text in the
  accent uses `--bhe-accent-ink`, light in dark mode; the HTML block preview
  takes the mode; the post link wraps (`.bhe-row__link`).
- **Customizer** (full-screen layout): follows the account (it read a user
  its views don't pass, so always fell back to Default, and forced light).
- **At a glance:** charts use the palette's four colours.
- **Admin bar** (public site): Settings → Admin bar → Colours has "Each
  person's Appearance", the new default; a "dark" saved before 1.3.0 without
  an explicit choice reads as it (`color_chosen`).

## Colour palette and fixed status colours

Ten named colours, sampled from the brand chart: red `#E81C2B`, orange
`#F4891E`, yellow `#EACE2A`, green `#0AA34F`, blue `#459FE3`, pink `#D80B8F`,
purple `#6A3499`, brown `#6D340D`, black `#0F1113`, white `#FFFFFF`. The
statuses are aliases: **success = green, warning = orange, danger = red,
info = blue**, the same whatever the accent.

- Each colour's shades are worked out in `tailwind.theme.js`
  (`namedShades()`), never typed in: the fill (deepened by at most 20% when
  that lets white text read on it: red and green; otherwise black text:
  orange, yellow, blue, white), the text on it, the colour as text (4.5:1 on
  its tint, light and dark), a tint and a border. Variables: `--bh-red`,
  `--bh-on-red`, `--bh-red-text`, `--bh-red-subtle`, `--bh-red-border`, and
  the same for every name and status (`--bh-success-soft` kept).
- Bootstrap-style classes, always shipped: `.bh-bg-*`, `.bh-text-bg-*`,
  `.bh-bg-*-subtle`, `.bh-text-*`, `.bh-border-*`, `.bh-border-*-subtle`. In
  Tailwind builds (core, or apps with the preset): `bg-red`, `bg-red-subtle`,
  `text-red`, `text-on-red`, `border-red`, `bg-success-subtle`… (`blue-600`
  stays the accent; bare `blue` is the palette's).
- **Themes:** `admin/assets/css/colors.css` (1.5 KB gzipped, no reset) carries
  the palette alone; the default theme links it. Built by
  `tailwind.colors.config.js` (`npm run build:colors`).
- **Statuses no longer follow the accent:** the dashboard's trend pills (up
  = success, down = danger; amber counts warning, red danger), an amber tile's
  hint ("2 waiting for review") and a red tile, `.bh-badge--blue|green|amber|red`,
  `.bh-note--warn`, new `.bh-note--info|success|danger`, status rows and
  states, toast dots and the Customizer's status line.
- `Basehim.appearance.get().colors` adds `success`, `warning`, `danger`, `info`.

See `docs/ADMIN-UI.md`, "Colour palette".

## Section tabs: frosted glass

The sticky tab bar is frosted glass (the page colour at 80% with a blur),
like the data table's sticky header, instead of a solid bar. Its edges no
longer fade out: tabs run to the edge, and with a mouse each scroll arrow
sits on a pane of the same glass. The arrow no longer makes the bar 4px
taller when it appears (every page with tabs jumped), and stays centred on
the labels.

## Pages paint finished (no flash of unstyled content)

Measured on every admin route (layout shift while loading, and filmstrips at
6× CPU throttling). Before → after:

- **Stylesheets in `<body>`:** the data table, block editor and Customizer
  linked their CSS from the view, inside `<body>`; the browser painted
  everything above it first (the editor showed the admin's sidebar, then
  covered it). New `View::style($href)` / `headStyles()`: views ask, the
  layout prints them in `<head>`; a page without that layout still gets them,
  first. The full-screen layout's `tailwind.min.css` also lacked `?v=`, so a
  browser could keep the old copy after an update.
- **Data tables** (Posts, Pages, Comments, Users, Templates, Apps…; up to
  0.0116): the plain server table painted, then `datatable.js` rebuilt it
  (filters, pager, rows). It is now built as soon as its script runs, and
  `html.bh-js` keeps the server's markup hidden until then (its place kept;
  shown after 2.5s if the script never finishes). Measuring the sticky
  toolbar also animated its padding on every load (the table crept 24px in
  4px steps); styles are settled before transitions return. → 0.
- **Editor** (0.058): the source view and empty panels showed, then the
  blocks; the canvas and sidebar now appear once every script has drawn them.
  → 0.
- **Settings pages** (0.002 each): the tab arrow grew the bar. → 0.
- **Customizer** (0.027): the first section opened after paint, and an empty
  status line took 20px; both are rendered by the view now. → 0.
- **Media** (the toolbar slid 68px): the date filter's months are rendered
  by the view. → 0.
- **Dashboard:** a card waiting for its data keeps the height it had last
  time (per card and width, in this browser), so the grid doesn't jump when
  data comes. → 0 from the second visit. (The first visit still moves where
  the Analytics app's own card grows when its chart loads.)
- **Web font:** Inter loads from Google Fonts with `display=swap`; a
  metric-matched local fallback ("Inter Fallback": Arial scaled to Inter's
  size, ascent and descent) makes the swap barely move text, and the layouts
  preconnect to the font hosts.

## Dashboard on phones

Page gutter, card padding and tile padding stacked up: cards sat 20px apart
and a stat tile's content got 133px of a 390px screen. On phones, cards (and
the welcome card) are as far apart as the screen's side gutter (16px), and At
a glance's tiles become cells of one grid divided by hairlines, the card's
padding inside each, as the Analytics card lays out its figures (146px of
content, numbers aligned with the card's heading). Larger screens unchanged.

## Fixed after the test-channel install (cloudhim.com)

- **CloudHim answered 500 (BH-163).** Its view includes core's data table
  partial with `require`, so inside it `$this` is the app; the partial called
  `$this->style()`, which exists only on core's `View`
  (`Call to undefined method CircuitsDIY\CloudHim\App::style()`). Apps that
  include it through `View::include()` (Security Center, Image Compressor,
  Dev Tools, Backup & Restore) didn't fail but lost `datatable.css`:
  `adminView()` renders the layout with `renderWithSections()`, which reset
  what the partial had asked for. New `App\Core\Http\HeadStyles`
  (request-wide, static): a partial calls `HeadStyles::add()`; a layout still
  to come (`View::extend()`, or `App::adminView()` before it renders the app's
  body) prints it in `<head>`, otherwise it is printed in place.
  `View::style()` / `headStyles()` remain, on top of it. Reproduced with a
  probe app embedding the table each way: 500 / unstyled before, styled with
  the link in `<head>` (in place for a bare fragment) after.
- **Hidden-until-drawn is opt-in (BH-164):** only core's partial and post
  editor mark their markup (`data-bh-draw`); app markup that borrows `.bh-dt`
  or `.bhe-root` classes, and an editor an app embeds, are never hidden.
- **Classes apps use went missing (BH-165):** the rebuilt admin stylesheet had
  dropped classes 1.2.128 shipped that core no longer names (`ring`,
  `-mx-4`, `ease-in`, `shadow-lg`, `text-6xl`…; 21). Safelisted, and a test
  compares every build with the list 1.2.128 shipped
  (`tests/fixtures/admin-css-classes-1.2.128.txt`).
- **Error pages ignored the person's appearance (BH-166):** the 500 page was
  light and blue whatever was chosen. New `App\Core\Http\StatusPage` draws
  every page outside the admin layout (server errors with or without debug
  details, 404, Access denied, a route's error, the "One moment" page while a
  broken app is switched off, OAuth errors, an app switched off): the
  person's mode (System follows the device, with the CSP nonce) and accent,
  core's versioned stylesheet and components, no inline CSS (five had their
  own with fixed colours). `AppearanceService::forRequest()` never throws, so
  a failing database still gets a page. "Try again" only links to a path on
  this site. An unknown `/admin/…` address gets the admin's 404, not the
  public theme's.

## For apps

- **CSS:** `rgb(var(--bh-surface))` and the other names above; or build with
  `tailwind.preset.js` and use the same utilities as core.
- **JavaScript:** `Basehim.appearance.get()` (`{mode, theme, dark, accent,
  colors, palette}` as hex), `.color(name, alpha)`, `.onChange(fn)`; event
  `basehim:appearance`. Defined in the head, before any app script.
- **PHP:** `$this->api()->appearance()` → `mode`, `theme` (null for System),
  `accent`, `accent_label`.

See `docs/ADMIN-UI.md` → "Light, dark and accent (1.3.0)".

## Scroll strips and sticky bars

- **Section tabs get scroll buttons** (BH-138): `scroll-strip.js` adds an
  arrow at each end of a strip with a mouse or trackpad, shown only while
  there is more that way, centred on the labels; touch keeps the fade. Applies
  to `.bh-tabs` and any `[data-bh-strip]` (Marketplace categories, Comments'
  status filter — BH-140). `window.bhScrollStrip(el)` for strips added later.
- **A data table under tabs** (BH-139): the table's sticky controls stuck at
  the header's height and slid behind the tabs. `datatable.js` measures every
  sticky bar above it (`header`, `.bh-tabs`, `[data-bh-sticky]`).
- `scroll-padding-top` (5rem, 8.5rem with tabs) so anchors and keyboard focus
  land below them (BH-141). Tab icons take the label's colour and weight
  (BH-143). `icons.js` gained `chevron-left` / `chevron-right` (BH-142).

## Pre-release audit

Every admin route was checked in light and dark at 1440 and 390 px for
script errors, failed requests, sideways overflow and WCAG AA text contrast.

- **BH-144:** four views had a `<style>` of hex colours (profile photo field
  and dialog, menu editor, Updates' app dialog, widget areas): in dark mode
  the "Profile photo" label was invisible (1.00:1). Moved into
  `src/tailwind.css` as theme colours, blue → accent.
- **BH-145:** Tailwind copies every plain rule that names a utility class
  into each `@apply` of it. `[data-bh-menu-panel]:not(.hidden)` became 104
  selectors, and `main .grid { gap: 1rem }` overrode the gap of about 20
  components on phones (since 1.2.x). Now matched as attributes
  (`[class~="hidden"]`, `[class~="grid"]`).
- **BH-146:** light-mode grey hint text (`text-slate-400`) was 2.56:1 on
  white on nearly every screen; `text-slate-500` 4.34:1 on raised fills. Each
  is darkened only as far as 4.5:1 on its surface (`lightNeutralText()`).
- **BH-147–151:** same-hue badges (`text-X-600` on `bg-X-50`) → 700; the
  Updates "Retry connection now" button → `bh-btn--primary`; editor accent
  text in dark; text fields and dialogs drew the browser's grey in dark mode
  (now the surface colour by default, zero specificity); API page code panels.
- **BH-152–153:** leftover literal colours and static inline styles in the
  editor, media picker and `admin-bar.js`; `AppearanceService::clean()` no
  longer throws on a non-string stored value.
- A stray PHP `error_log` file was tracked in the repository; removed and
  ignored.

## Security: PHP's `error_log` file could be downloaded (BH-154)

The root `.htaccess` refused `*.log` but not `error_log`, the file cPanel
and many other hosts have PHP write into the site folder; it has no
extension, so `/error_log` was served to anyone, with server paths and error
details. The built-in server (`server.php`) served it too.

- `.htaccess`: `<FilesMatch "(^error_log$|\.(env|log|sql|lock|yml|yaml|ini)$)">`.
- Existing sites: the updater never rewrites `.htaccess`, so the first admin
  page after the update changes that one line, exactly as Basehim wrote it
  (`SecurityHeaders::upgradeHtaccessErrorLog()`, recorded as
  `security.htaccess_error_log`; a hand-edited file is left alone and the
  result is logged). The 1.2.90 X-XSS-Protection step now shares the same
  atomic line replacement.
- `server.php` refuses `error_log` at any depth.

Sizes (gzipped): `tailwind.min.css` 31.0 → 43.2 KB (about 7 KB is the
variables for seven accents in two modes); the other sheets shrank, all
admin sheets together 76.9 → 81.1 KB.

## Upgrading

Full release: install over 1.2.x. The updater keeps `.env`, `.htaccess`,
`config/app.php`, uploads and storage. No migrations. Apps written for 1.2
keep working and look as before in light mode; their hand-written colours
won't follow dark mode until they use the variables above.

## Tests

798 passing. New: `tests/Unit/Release130Test.php` (the version, a complete tree, the `error_log` rule and its one-time upgrade, the palette and its contrast in both stylesheets, statuses off the accent, stylesheets in `<head>` and `View::style()`, the guards that keep script-drawn parts hidden until drawn, glass tabs) and `tests/Unit/AppearanceTest.php` (accents in sync across
PHP and JS, saving and cleaning, the apps API, each screen's sources, the
admin bar build, scroll strips, and the audit's guards: no fixed colours in
a view's `<style>`, no plain rule naming an applied utility, light grey text
at 4.5:1). Earlier release tests now read the CSS sources.
