# Basehim 1.2.109

The Filter & sort drawer is matched to the admin sidebar. No database changes.
`datatable.css` is compiled from `src/datatable.css`.

## The change

The drawer was wider than it needed to be. It now matches the admin's
off-canvas sidebar so the two read as one system:

- **Width `16rem`** (`w-64`, with a `max-w-[85vw]` guard), the same as
  `.bh-sidebar` on small screens — instead of `min(22rem, 88vw)`. More of the
  list stays visible beside it.
- **The same backdrop**: `slate-900/40` with `blur(4px)` and a `.3s` fade,
  matching `#bh-backdrop`, rather than the lighter `blur(2px)`.
- The slide uses the sidebar's easing and duration (`.32s
  cubic-bezier(.4,0,.2,1)`); the unmount timer was lengthened to match so the
  slide-out plays in full.

All in `admin/assets/css/src/datatable.css` (`.bh-dt-drawer__panel`,
`.bh-dt-drawer__overlay`) and a one-line timing change in
`admin/assets/js/datatable.js`.
