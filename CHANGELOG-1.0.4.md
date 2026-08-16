# Basehim 1.0.4 — media picker

Four fixes to the picker that opens from the post editor.

## The featured image icon would not centre

It sat hard against the left edge of the button.

The class list was `w-6 h-6 block mb-2`. `display:block` on an inline SVG turns
it into a block box, and a block box does not respond to the button's centred
text — text alignment centres inline content, not block children. `mx-auto`
centres the box itself.

One word, but not an obvious one: the button looks like it should centre
everything inside it.

## Thumbnails ignored the screen

The grid was `minmax(140px, 1fr)` at every width. On a phone that leaves one
column and a sliver of a second; on a wide monitor, a wall of small squares with
most of the dialog unused.

It is now `minmax(clamp(88px, 22vw, 150px), 1fr)`, so the minimum tracks the
viewport between a floor and a ceiling — three usable columns on a phone, larger
thumbnails on a desktop, one rule.

Thumbnails are `object-fit: contain` rather than `cover`. A picker exists to tell
images apart, and `cover` crops exactly the part that identifies them. A
checkerboard behind them makes transparency read as transparency rather than as
white.

## There was nowhere to enter alt text or a caption

The picker could only choose a file. Setting alt text meant leaving the post,
opening the media library, finding the image, editing it and coming back — so in
practice images went out with no alt text at all.

The dialog now has a details pane beside the grid: preview, dimensions, file
size, and fields for alt text and caption. They save on their own a moment after
typing stops, and immediately when a field loses focus — which matters, because
the next thing anyone does is press Select.

The edit is to the media item rather than to the post, so there is nothing for a
Save button to belong to. The endpoint already existed
(`/admin/media/{id}/update`); nothing about the API changed.

Alt text is hidden for files that are not images, where it would mean nothing.

## Interface

- The panes stack below 760px, with the details pane capped in height so the
  grid never collapses to one row with everything else pushed off-screen. Below
  480px the dialog goes full-screen.
- The grid is keyboard-navigable: cards take focus, Enter or Space selects.
- Double-clicking a card selects and confirms in one action rather than three.
- A search icon inside the field, and a clearer selected state.
- Proper `role="listbox"` and `aria-selected`, so the selection is announced.

## After installing

**Hard-refresh the admin** (Ctrl+Shift+R). The stylesheet and script are cached
by the browser, and without a refresh the old ones keep running — which looks
exactly like the patch not having worked.
