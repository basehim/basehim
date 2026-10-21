# Basehim 1.2.78: dashboard cards sized for Basehim, and as the reference on phones

Follow-up to 1.2.77, after review.

## Sizes match the rest of the admin

1.2.77's cards and buttons were larger than everything else in Basehim. They
now use the admin's own scale:

| | 1.2.77 | 1.2.78 (as elsewhere in Basehim) |
|---|---|---|
| Greeting | 24 px bold | 20 px semibold, like every page heading |
| Card title / subtitle | 18 px bold / 14 px | 15 px semibold / 12 px, like `.bh-card__title` |
| Card icon square, header buttons | 44 px, 36 px | 36 px, 32 px |
| New post / Upload / Customize | 56 px high, 16 px text | 40 px high, 14 px text |
| Tile number / label / hint | 32 / 15 / 14 px | 24 / 13 / 12 px |
| Tile icon, pill, avatars | 40 px, 12 px, 32 px | 28 px, 11 px, 24 px |
| Chart values / months / axis | 14 / 14 / 12 px | 12 / 12 / 11 px |

## At a glance

- **Desktop:** all tiles in one row, the same size, People included (on a
  wide card, up to six tiles; more wrap four to a row).
- **Phones and narrow cards:** as in the reference: two tiles to a row with
  the icon, label and pill on one line, and People across the row with its
  avatars and illustration. 1.2.77 moved the label under the icon on narrow
  tiles; that is gone. On the narrowest tiles the pill drops its icon and the
  label gives way first. Hints may take two lines instead of being cut off.
- Cards use 12 px inner spacing on phones (20 px from 640 px up).

## Buttons on phones

New post, Upload and Customize share one row with their icon squares, each as
wide as its words, so "Customize" is no longer cut off; the chevrons show from
640 px up.

## Charts

- The period picker shows only on cards wide enough for it beside the title;
  on narrower cards it is in ⋮ → Settings, and the title is no longer cut to
  "Publish…".
- Month labels are no longer clipped when there are many (This year).

## Files

`admin/assets/css/src/tailwind.css` and the compiled
`admin/assets/css/tailwind.min.css`, `docs/DASHBOARD-WIDGETS.md`,
`tests/Integration/DashboardCardsTest.php`, `index.php`.

## Tests

Full suite on Dolt: 365 passed, 0 failed, 4 skipped. Checked in Chromium at
390, 1440 and 1920 px.
