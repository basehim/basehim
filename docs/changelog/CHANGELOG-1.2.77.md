# Basehim 1.2.77: dashboard cards redesigned and animated

The dashboard's cards and buttons follow the new reference design, and the
admin's shared components (buttons, cards, fields, badges, empty states) move
from hand-written CSS into compiled Tailwind. Numbers count up and charts
grow in when their data arrives.

No database changes. No change is required to app widgets; they can use the
new tile options below.

## The cards

- **Header**, for every card: the widget's icon in a tinted square (the new
  `dashboard.tone`, default blue), the title, and the description as a
  one-line subtitle; then a bordered Refresh button, the widget's inline
  setting if it has one, and ⋮. **Collapse moved into ⋮** (Collapse / Expand),
  next to the widget's actions, Settings and Remove.
- **Inline setting.** A `select` field marked `'inline' => true` also appears
  in the header; changing it saves the card's settings and reloads it.
  Publishing activity's period uses it, with a new option, *This year*
  (January to this month). Hidden on narrow cards, where ⋮ → Settings has it.
- **Stat tiles** (At a glance, and every widget using the `stat` view): a
  tile tinted in its tone with a gradient icon square, the label, a pill at
  the top right, the number, the hint and small bars in the tile's colour.
  New item keys:
  - `icon`: any name from core's icon set;
  - `badge` (`text`, `tone`, `icon`): a count that needs attention, shown in
    the pill instead of a trend (At a glance: comments waiting, with a clock);
  - `people` (`items` of `name` and `url`, `more`): an avatar stack;
  - `wide`: spans the row, with a quiet illustration (People).
  - Tones gain `indigo`.
  Tiles sit two to a row, four on a full-width card, and the last tile fills a
  row it would leave half empty. Each tile measures its own width: on a narrow
  one the label moves under the icon and the hint may take two lines.
- **At a glance** now has icons and colours for Posts, Pages, Media,
  Comments and People. Posts, Pages and Media show their growth over the last
  30 days (*+12%*: items added in 30 days against what there was before);
  Comments shows the number waiting for review; People shows the three
  accounts that signed in most recently, and how many more there are.
- **Charts**: an axis of round numbers with dashed guides, gradient bars with
  their values above them, and the summary as a strip with an icon
  (`summary_icon` in the payload).
- **Icons in data.** A widget's data can name any icon in core's set; the
  server sends the SVG of every icon a response uses with it (`icons`), so
  apps don't register them. A name core doesn't have is dropped.

## Animation

- Stat numbers count up to their value (about a second, easing out, in the
  viewer's number format); a refresh counts from the number shown before.
- Bars grow from the axis one after another, then their values rise in; line
  charts draw themselves left to right.
- Only when new data arrives, not when a card is redrawn (customize mode,
  collapse), and never for people whose system asks for reduced motion.

## The three buttons

New post, Upload and Customize are large tile buttons: an icon square, the
label and a chevron; Customize is the primary blue. This is a core button
size, `bh-btn--lg`, with `bh-btn__icon` (tints `--green`, `--violet`,
`--amber`), `bh-btn__label` and `bh-btn__chev`. On phones the three share one
row, without the squares and chevrons.

## Compiled Tailwind, no hand-written CSS

- `admin/assets/css/dashboard.css` is gone, and so are the component rules in
  the admin layout's inline `<style>` (`.bh-btn`, `.bh-card`, `.bh-label`,
  `.bh-input`/`.bh-select`/`.bh-textarea`, `.bh-help`, `.bh-badge`,
  `.bh-empty`). All of them are now Tailwind components, written with
  `@apply` in `admin/assets/css/src/tailwind.css` and compiled into
  `admin/assets/css/tailwind.min.css`, which every admin layout loads, so the
  full-screen and sign-in layouts get them too.
- One family of shapes: cards and large buttons `rounded-2xl` with a
  slate-200 hairline and one elevation (`shadow-card`); buttons, inputs and
  tiles `rounded-xl`; icon squares `rounded-xl`. Primary buttons are a subtle
  blue gradient everywhere. Badges gain `--violet` and `--indigo`.
- New build files (on GitHub, not in the download): `tailwind.config.js`,
  `package.json` (`npm run build:css`), `admin/assets/css/src/tailwind.css`.
  The rebuilt `tailwind.min.css` contains every selector the previous file
  had, plus classes the views already used that the old build had missed
  (`md:w-1/2`, `text-lg`, `pb-4`…). It was compiled with Tailwind 3.4.17; the
  config pins 3.4.19, which the previous file was built with.

## Fixed

- **Select options with numeric values** (BH-032). `WidgetRegistry::fieldOptions()`
  treated any integer key as a plain list, and PHP turns `'6'` into an
  integer, so `['6' => 'Last 6 months']` offered "Last 6 months" as the value.
  Publishing activity's period saved the label and always showed six months.
  Only a true list now uses values as labels. The browser also keeps the
  options' order (`order` in the catalogue), which JSON loses for numeric keys.

## Files

New: `admin/assets/css/src/tailwind.css`, `tailwind.config.js`,
`package.json`, `tests/Integration/DashboardCardsTest.php`.
Removed: `admin/assets/css/dashboard.css`.
Changed: `admin/assets/css/tailwind.min.css`, `admin/assets/js/dashboard.js`,
`admin/views/dashboard/index.php`, `admin/views/layouts/app.php`,
`app/Core/WidgetRegistry.php`, `app/Services/Dashboard/DashboardService.php`,
`app/Services/Dashboard/CoreDashboardWidgets.php`, `docs/ADMIN-UI.md`,
`docs/DASHBOARD-WIDGETS.md`, `.gitattributes`, `index.php`.

## Compatibility

- App stat widgets keep working; tiles without an `icon` show a chart icon.
- Collapse is in ⋮ instead of a header button.
- Screens that override `.bh-btn` sizes in their own CSS keep their
  overrides (those selectors are more specific).
- A site updated by patch keeps the old `dashboard.css` file on disk, unused.

## Tests

`tests/Integration/DashboardCardsTest.php` (3): an app tile's icons, badge,
people and wide flag, with icons delivered and bad names and links dropped;
At a glance's and Publishing activity's new data and the inline period; the
compiled CSS and the page without `dashboard.css`. Full suite on Dolt:
365 passed, 0 failed, 4 skipped. Checked in Chromium at 1440 and 1920 px and
on a 390 px phone, including Customize, Add widgets, reduced motion, and the
Posts, Reading, System, Apps and Users screens.
