# Basehim 1.0.0

The version resets to 1.0.0. This is the 1.44 line — no legacy naming, no
desktop agents — with every change from the 1.42.12–17 patches folded in.

## Carried over from 1.42.12 — missing icons

Four icons existed in `app/Core/Icon.php` but not in `admin/assets/js/icons.js`.
Anything JavaScript drew with them came out **blank**: the sidebar collapse
chevron and every drag grip. The button was there, sized and clickable, and
completely invisible.

    bars-2                 menu drag grips, block editor drag handles
    pencil                 menu item edit control
    chevron-double-left    sidebar collapse toggle
    chevron-double-right   the same toggle, once collapsed

The markup is copied from the PHP definitions rather than redrawn, so the two
sets cannot disagree about what an icon looks like. Every icon name the admin
JavaScript asks for — 27 of them — was then rendered and checked; none is empty.

## Carried over from 1.42.13/14 — the block editor

The editor had twelve block types and no way to reach them without the mouse.

**Slash menu.** Type `/` on an empty line to insert any block, filtered as you
type. It means never leaving the keyboard to add a heading or a list.

**Inline formatting toolbar.** Select text and a toolbar appears over it: bold,
italic, underline, strikethrough, inline code, link.

**Undo and redo.** There was none at all. `Ctrl+Z` and `Ctrl+Y`, with buttons
that disable when there is nothing to undo. Snapshots are debounced, so undo
steps back by edit rather than by keystroke.

**Duplicate a block** with `Ctrl+D`, deep-copied — sharing one data object would
mean editing either changed both.

**Paste as structure.** Pasting from a document used to dump styled markup into
one paragraph. The HTML is now split on its block-level elements: headings stay
headings, lists stay lists, and fonts and colours are discarded.

**Alignment controls** in every block's toolbar — left, centre, right, justify —
where before the only route was a dropdown buried in the inspector. Added to
lists, quotes, code and embeds, which had none.

**`Ctrl+S` saves** rather than offering to save the page.

### Image alignment never worked

The renderer emits `<figure class="align-center">`, and no stylesheet in core or
in any bundled theme defines those classes. Aligning an image did nothing — in
the editor and on the published page. The class is kept for themes that style
it, and an inline style is emitted alongside so alignment works without theme
cooperation. Centring an embed needs a margin rather than `text-align`, and a
centred list needs `list-style-position:inside` or its bullets sit far from the
text. Both are handled.

Unaligned output is byte-identical to before, so this cannot change an existing
page that was not using alignment.

## Carried over from 1.42.15 — apps can claim missed URLs

An app could not handle a URL the site does not otherwise serve — an imported
permalink, a legacy URL scheme, a custom 404 — without breaking the site.

`Application::boot()` runs inside `bootstrap.php`, **before** `index.php`
requires the route files. So a route an app registers with `get()` is added
ahead of every core route, and the router returns the first match. An app
registering `/{path:*}` shadows the entire site, `/admin` included. That is not
something an app author can avoid; registration order is not theirs to control.

**`Router::fallback()`** registers a route tried only after every normal route
has missed. A fallback may return `null` to decline. An exception inside one is
logged and skipped, so a broken app cannot turn a missing page into a 500.

**A `route.miss` filter** fires just before the 404 renders. A listener returns
a `Response` to claim the request, or `null` to pass.

It fires in **both** places — when nothing matched, and when a matched route
answered 404. Core registers `/{slug}` last and that matches every
single-segment path, so a hook that only fired on "no route matched" would never
run for the ordinary case of an old permalink.

## Carried over from 1.42.16 — menus with dropdowns

**Nesting a menu item made it disappear from the site entirely.**

The data model was fine: `parent_id` was in the schema, `saveTree()` persisted
nesting, `buildTree()` returned proper `children`. But every theme looped the
menu flat and never read `children`, and a flat loop only sees top-level rows.
No dropdown, no link — nothing. A page could silently stop being reachable.

**`menu_html()`**, **`menu_assets()`** and **`menu_has_children()`** in
`bootstrap.php`, alongside `icon()` and `link_to()`. Three levels, with
`aria-haspopup`, `aria-expanded`, a caret on parents, and item icons. Beyond
three levels, deeper items are lifted beside their parent rather than hidden —
hiding them would repeat the bug this exists to fix.

The third level opens sideways, a submenu near the window edge flips leftward,
Escape closes and restores focus, and `prefers-reduced-motion` disables the
transitions. On touch the first tap opens a parent, but a parent with a real URL
still navigates with a pointer — taking that away would make whole sections
unreachable.

**Both bundled themes** now use it, in the desktop nav and the mobile menu. The
mobile menu is stacked, because a hover dropdown needs a pointer. `dark-night`
carries a small override, since the shared styling assumes a light surface.

A theme that has not been updated keeps its flat loop and behaves as before.

### The menu builder

**Nesting did not work, and the cause was geometric.** Drop detection was bound
to `.mb-item`, which contains its own children — so the measured box spanned the
whole subtree, and the "nest here" test ran over a rectangle hundreds of pixels
tall. It is measured against the row now.

**Vertical drop zones.** Edges reorder, the middle nests. On a 44px row that is
a 22px target, rather than an invisible "past 60% of the width" rule.

**Visible drop targets.** Reordering draws a rule with a dot at the boundary;
nesting highlights the row, indents it and shows a caret. While dragging, every
row gets a dashed outline so where nesting is possible is visible before the
zone is found.

**Tablets and phones.** HTML5 drag events do not fire on touch at all, so the
builder could not reorder a menu on a tablet or phone — the drag never started.
The native API is gone, replaced with pointer events: one code path for a mouse,
a stylus and a finger. A brief hold starts a drag, so the same gesture can still
scroll the list; dragging from the grip starts at once; the edges auto-scroll,
without which a long menu cannot be reordered on a phone at all.

**A three-level limit** in the builder, so it cannot accept a nesting the front
end would silently flatten — the item would sit in one place here and appear
somewhere else on the site.

## Not carried over

The desktop agent registry stays removed, as does the `NOVA_*` compatibility
layer and the plugin-era naming. Anything written against those needs updating;
the CloudHim app shipped alongside this release already is.
