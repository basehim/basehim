# Basehim 1.2.128

At a glance redesigned with real charts, fixes found on a phone (the
Circuits DIY dashboard at 390 px), MCP scheduling, and error pages found
while checking them. No database changes.
Released with Analytics 1.12.0, which fixes that app's dashboard card
(see its own changelog).

## At a glance: charts from real data

### Why

Each tile ended in three decorative bars that showed the same shape whatever
the figures. The section was also mostly solid blue (icon squares, bars,
pills), so nothing in it stood out. Designs were compared on a canvas; the
chosen one keeps the tiles and gives each a chart of its own data.

### The charts

`stat` items take an optional `chart` (normalised by
`DashboardService::statChart()`, drawn by `statChart()` in
`dashboard.js`):

| `type` | Data | Drawn as |
|---|---|---|
| `line` | `points`: up to 60 `{value, label}` | a trend line with a soft fill and the newest point marked |
| `bars` | `points`, as above | one bar per period, the newest in the accent |
| `split` | `parts`: up to 6 `{label, value, note?}`; `unit: "percent"` shows shares | one bar in shares, with a key |

Every chart has `label` (read by screen readers); every point or part has its
own tooltip. `empty` is shown in place of a chart with nothing in it. A tile
without a chart keeps the old decorative bars, so apps' cards look as before.
Charts sit at the foot of the tile (`mt-auto`), so they line up across a row.

Core's tiles (`CoreDashboardWidgets`):

- **Posts:** `line`, posts published in each of the last 12 weeks (weeks
  counted back from now); only your own without `edit_others_posts`.
- **Pages:** `split` by status: Published, Drafts, Other (waiting,
  scheduled, private).
- **Media:** `split` of the library's size by kind of file (Images,
  Documents, Video and audio, Other), at most three parts: the two largest
  and the rest as "Other". Tooltips give file counts and sizes.
- **Comments:** `bars`, approved and waiting comments received each week
  for 12 weeks (spam and trash left out).
- **People:** `split`, accounts signed in during the last 30 days
  (`last_login_at`) against the rest. The wide tile's illustration shows
  only when it has no chart.

### Also on the tiles

- **Icons:** a soft square (`bg-accent-50`, `text-accent-600`, 32 px)
  instead of a solid accent square; red tiles get a light red one. The
  loading placeholder matches.
- **Periods named:** the card's description is "Charts: last 12 weeks ·
  changes: last 30 days".
- **Small bases:** `growth()` shows a count ("+13 new") when fewer than ten
  came before, not only past 999%. 5 files becoming 18 read "+260%".
- **Comments:** the pill is the comments' trend; the waiting count stays in
  the hint (amber tone while any wait). The badge repeated the hint.
- **Pages:** the hint is "Last updated …" (`Time::ago()`); the key already
  gives the published count.

## At a glance: numbers at different heights on a phone

### Why

A stat tile's top row held the icon, the label and the pill (a trend such as
`+120%`, or the count waiting for review). The row wraps, so on a tile about
140 px wide the pill dropped to a second line under "Comments", "Posts" or
"Media", and that tile's number sat lower than its neighbour's. Pages, with no
pill, was out of step with every other tile.

### Fix

The pill moves to a row with the number (`.bh-dstat__vrow`: the number on
the left, the pill on the right). Every tile's number now starts right under
the label row, at the same height. On a very narrow tile (one column at
320 px, `19,178` with `+60%`) the pill drops under the number rather than
squeezing it. Desktop keeps the same look with the pill level with the
number. A wide tile without a pill still shows its chevron in the top row.

- `admin/assets/js/dashboard.js`: `VIEWS.stat` builds the value row.
- `admin/assets/css/src/tailwind.css`: `.bh-dstat__vrow`; the pill no
  longer takes `ml-auto` in the top row. `tailwind.min.css` rebuilt with the
  Tailwind 3.4.19 CLI (a rebuild of 1.2.127's source reproduced the shipped
  file byte for byte first).

## At a glance: thousands without separators

The tiles' numbers are formatted by the browser (`4,733`), but the hints
were `sprintf('%d …')`: "4622 published · 111 drafts". The hints, the
plural helper and the review count now use `number_format()`.

## MCP: scheduling and backdating

The REST API took `published_at` and `scheduled` in 1.2.126; MCP didn't.

- `create_post`, `update_post`, `create_page` and `update_page` take
  `published_at` (ISO 8601 such as `2026-11-20T09:00:00Z`, UTC unless an
  offset is given; empty clears it) and the status `scheduled`.
- An unreadable time, or `scheduled` without a time, is refused with a
  message, before anything is saved.
- Without publish rights, `scheduled` becomes `pending`, as `published`
  already did.
- The answers include `published_at`.
- `list_posts` and `list_pages` accept `status: scheduled`.

`PostService` settles status and time together as for every other caller
(a scheduled time already past publishes).

## Error pages

- **No viewport.** The error pages written outside the admin layout had no
  `<meta name="viewport">`, so phones drew them at 980 px and scaled them
  down to tiny text: `ErrorHandler`'s generic and debug pages, the admin
  404 and 500 views, the 403 page from `AdminAreaPolicy`, and `SitePage`'s
  plain fallback.
- **Debug page icons printed as text.** The debug page is a heredoc, which
  doesn't run PHP tags, so its two `<?= Icon::svg(…) ?>` calls were sent as
  text. They are rendered into variables first.
- **403 page** (BH-041): inline styles and a lock emoji replaced by the
  admin stylesheet, a `bh-card`, the `lock-closed` icon and a `bh-btn`.
- **Admin 404**: "Go Back" was `javascript:history.back()` styled by hand;
  it is now Dashboard (primary) and View site (secondary), core buttons.
- **Admin 500**: the 16 px icon in an 80 px red gradient box is a 40 px icon
  on a light red square; the button is `bh-btn--primary`.
- The generic error page uses the accent colour and `bh-btn--primary`.

## Files

- `admin/assets/js/dashboard.js`
- `admin/assets/css/src/tailwind.css`, `admin/assets/css/tailwind.min.css`
- `app/Services/Dashboard/CoreDashboardWidgets.php`
- `app/Services/Dashboard/DashboardService.php`
- `app/Http/Controllers/Api/McpController.php`
- `app/Core/ErrorHandler.php`
- `app/Http/Middleware/AdminAreaPolicy.php`
- `app/Core/Http/SitePage.php`
- `admin/views/errors/404.php`, `admin/views/errors/500.php`
- `index.php`: 1.2.128.

## Tests

- `tests/Unit/Release12128Test.php`: the value row in `dashboard.js` and the
  compiled CSS; grouped hints and the plural helper.
- `tests/Unit/Release12128Test.php` also: the chart types in `dashboard.js`
  and their compiled styles; the decorative bars only as a fallback.
- `tests/Integration/Release12128Test.php`: the At a glance tiles carry their
  charts from real counts (a new post lands in the newest week, a PDF shows
  in the media split); and over HTTP, MCP schedules and
  backdates a post, lists scheduled posts, refuses a bad or missing time
  without saving, schedules and unschedules a draft, and turns a
  contributor's `scheduled` into `pending`.

- Updated for the new behaviour: `tests/Unit/Release12126Test.php` (a count
  below ten before) and `tests/Integration/DashboardCardsTest.php` (no
  comments badge).

Checked in a browser: the charts at 390 and 1440 px, as an administrator and
as an author (own posts only), and the empty state; At a glance at 320, 360,
390 and 1440 px, with real
numbers (`4,733`, `19,178`, `201` with `139` waiting); the 403 and 404 pages
and the generic and debug error pages at 390 px.
