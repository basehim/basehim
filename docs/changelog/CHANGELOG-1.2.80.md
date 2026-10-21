# Basehim 1.2.80: one accent colour, matching cards and buttons

Scope: cards and buttons (and the dashboard greeting above them). Asked for
on 21 October 2026: too many colours on the dashboard cards, components that
should look identical and professional, and important details cut off.

## Colour

- `tailwind.config.js`: `theme.extend.colors.accent`, the blue scale
  (50 `#eff6ff` … 950 `#172554`). Components use `accent-*` and slate.
- Red only for errors and destructive actions; amber only for Site health's
  warning icons.
- Stat tiles: one slate panel, accent icon squares (solid), spark bars in
  accent shades. Trend up: accent pill; down: neutral pill; an attention badge
  (`badge`) is a solid accent pill. Avatars in accent 600/500/400, the People
  illustration in accent tints.
- `tone` values are still accepted: blue, green, violet and indigo show the
  accent; amber means "needs a look" (outlined badge, filled feed dot, accent
  hint); red stays red; slate is neutral. Header icon squares are always
  accent (red and slate kept).
- Charts: first series accent, others accent-300 and slate-400. Status rows:
  passing checks in the accent.

## Cards

- `.bh-dcard` is the core card: `rounded-2xl`, slate-200 hairline,
  `shadow-card`; header with a bottom divider, 16 px inside on phones and 20 px
  from 640 px, title 15 px semibold. `.bh-card__head` and `.bh-card__title`
  match it.
- No truncation: card titles and descriptions, tile labels (they wrap between
  words; the pill moves aside), hints, list titles and details, comment
  quotes. Header tools stay at the top right.

## Buttons

- One scale shared with fields: `--sm` 32 px, default 36 px, `--lg` 40 px;
  `.bh-input--sm` / `.bh-select--sm` are 32 px.
- New `bh-btn--icon` (square, combines with the sizes).
- `--primary` is solid `accent-600` (hover 700) instead of a gradient.
- `bh-btn__icon` is always the accent's lightest shade (`--green`, `--violet`,
  `--amber` remain as names).
- Card tools use core classes: Refresh `bh-btn--secondary bh-btn--sm
  bh-btn--icon`, ⋮ and the customize strip `bh-btn--ghost bh-btn--sm
  bh-btn--icon`, the inline period `bh-select bh-select--sm`. The old
  `.bh-dash-icon-btn` and `.bh-dcard__tool` styles remain for older markup.

## Dashboard greeting

- A light panel with the person's picture, today's date, "Good morning /
  afternoon / evening" by the person's timezone (their profile's, else the
  site's), and "Here's what's happening on <site> today."
  (`DashboardController::greeting()`).
- New post is the primary button; Upload and Customize are secondary (Customize
  is primary when the person can do neither). Plain icon buttons, no chevrons.

## Files

- `tailwind.config.js`, `admin/assets/css/src/tailwind.css`,
  `admin/assets/css/tailwind.min.css`
- `admin/assets/js/dashboard.js`, `admin/views/dashboard/index.php`
- `app/Http/Controllers/Admin/DashboardController.php`
- `tests/Integration/DashboardCardsTest.php` (greeting test; selector list)
- `docs/ADMIN-UI.md`, `docs/DASHBOARD-WIDGETS.md`, `CHANGELOG.md`, `index.php`

## Checked

369 passed, 4 skipped (Dolt). Screenshots at 1440 px and 390 px reviewed and
approved before release.
