# Basehim 1.2.117

Phone toolbar polish from install feedback on 1.2.116. CSS/JS plus one
viewport attribute; no database changes.

## 1. The stuck shadow jerked

A browser moves a `position: sticky` element on its compositor thread, but the
`is-stuck` class that styles the stuck state is set from the main thread a
frame later. Recorded frame by frame on a fast flick, the bar spent a frame at
rest styled as stuck (and the reverse), and its `backdrop-filter` blur snapped
on and off while the shadow faded — two visible jumps per crossing.

The lag is inherent to styling stick state from script, so the fix makes it
invisible: on phones the bar is opaque `slate-50` (the page's own colour) at
rest and stuck alike, and the only stuck cue is a hairline and soft gradient
drawn by `::after` and faded by `opacity`. Recorded again, the bar's fill,
filter and shadow never change; only the `::after` opacity eases.

## 2. Select was narrower than its neighbours

In the no-wrap toolbar row it could shrink (29px beside 36px buttons). It is
`flex: none` now.

## 3. A focused search lost its stickiness (real phones)

With the keyboard open, browsers by default keep the layout viewport full
height and pan the visible part, so sticky elements pin to an edge that is out
of view. The admin's viewport now carries `interactive-widget=resizes-content`
(Chrome and Firefox on Android): the page shrinks to the space above the
keyboard and the sticky bar stays visible. While the search has focus
(`.is-typing`), the phone pager stops riding on the keyboard and waits at the
end of the list. (iOS Safari ignores the attribute; its keyboard handling is
the platform's.)

## 4. 36px controls on phones

By request, the phone toolbar uses the core 36px control height (it was 40px
since 1.2.115), as do the phone bulk-bar buttons, so phones and desktop share
one size.

## Files

- `admin/assets/css/src/datatable.css` + compiled `datatable.css`.
- `admin/assets/js/datatable.js` — `is-typing`.
- `admin/views/layouts/app.php` — the viewport attribute.

## Tests

- `tests/Unit/Release12117Test.php`; `tests/Unit/Release12115Test.php` updated.
