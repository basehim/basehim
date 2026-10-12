# Basehim 1.2.32 — Block Editor 2.0

Patch for 1.2.31. The post editor is rebuilt as a full-screen, Gutenberg-style
editor with nested blocks, a table block and the other missing core blocks.
Content saved by the old editor loads and renders unchanged.

## The faults

- **Editing felt like a form, not a page.** The canvas sat in a card between
  admin chrome, the title and slug were separate fields, and block settings
  were mixed into the post settings column.
- **No tables.** A table imported from WordPress or pasted from a spreadsheet
  became a Custom HTML box that could only be edited as code.
- **No layout blocks.** Columns, groups, covers, media & text, galleries,
  video, audio, files, details (accordion), pullquotes and preformatted text
  were missing; blocks could not be nested.
- **Smaller faults:** heading levels 1, 5 and 6 could not be chosen; links
  could never open in a new tab (the renderer dropped `target`); spacers were
  capped at 400px; the embed block wanted an iframe address rather than the
  video's page address; images had no width or link; and posts of app content
  types were saved to `/admin/{type}s`, which does not exist (their screens
  live at `/admin/content/{type}`).

## Changes

- `admin/assets/js/block-editor.js` — rewritten (Block Editor 2.0):
  - Full-screen shell: header with inserter, undo/redo, List View, Save
    draft / Preview / Publish or Update, settings and options; Post and Block
    tabs in a collapsible sidebar; breadcrumb in the footer.
  - Floating block toolbar: parent selector, block switcher (transforms and
    styles), drag handle, move up/down, block controls, bold / italic / link
    and more formats (highlight, inline code, strikethrough, underline,
    subscript, superscript, clear), options menu (duplicate, insert
    before/after, copy, group/ungroup, app actions, delete).
  - Writing like Gutenberg: Enter splits and Backspace/Delete merge blocks,
    arrow keys move between blocks, `/` block search, Markdown shortcuts
    (`#`, `-`, `1.`, `>`, ```` ``` ````, `---`), Esc for navigation mode,
    typing hides the toolbar, Ctrl+Z history with one step per pause.
  - New core blocks: table (header/footer sections, insert/delete rows and
    columns, column alignment, fixed layout, stripes, caption), columns /
    column, group (with Row and Stack layouts), cover, media & text, gallery,
    video, audio, file, details, pullquote, preformatted, buttons / button.
    Improved: image (upload, resize handle, link, rounded style, wide/full),
    embed (paste a YouTube, Vimeo, Spotify, SoundCloud, Loom, CodePen, TED, X
    or Google Maps page address), separator styles, spacer resize, code
    language list, Custom HTML preview.
  - Every block: colours with a contrast warning, font size, drop cap, HTML
    anchor and extra CSS classes.
  - Inserter panel with categories and search, Patterns tab (templates), quick
    inserter, List View with collapsing tree, drag and drop with drop line,
    drop or paste images to upload.
  - Paste: HTML and Google Docs become blocks (tables included), Markdown text
    is converted, a pasted link on selected text makes a link, a pasted video
    address becomes an embed, blocks copy between posts.
  - App API unchanged and extended: `edit(el, block, api, ctx)` gets
    `ctx.rich()` / `ctx.inner()`, `toolbar(tb, block, api)`,
    `inspector(el, block, api, ui)`, `styles`, `container`, `allowedBlocks`,
    `parent`, `template`; `insertBlock()` takes a parent id; `moveBlock`,
    `duplicateBlock`, `select`, `undo`, `redo` added.
- `admin/assets/js/block-editor-ux.js`, `admin/assets/css/block-editor-ux.css`
  — retired (now part of the editor); kept as empty files.
- `admin/assets/css/block-editor.css` — rewritten for the new editor.
- `admin/assets/css/blocks.css` (new) — front-end styles for block content.
- `admin/views/posts/edit.php` — the full-screen editor page. Same form
  fields as before. Title in the canvas; Summary (status, slug, link, editor
  format), Featured image, Categories, Tags, Excerpt, Discussion and SEO
  panels in the sidebar; correct URLs for app content types.
- `app/Services/BlockRenderer.php` — renders nested blocks and all new block
  types; anchors, classes, styles, colours, font sizes and wide/full
  alignment; safe URLs everywhere (no `javascript:`/`data:`); links may open
  in a new tab (with `rel`); links `blocks.css` once per page (filter
  `blocks.stylesheet`). 1.x data renders as before.
- `admin/views/api/reference.php` — block editor reference updated.
- `BLOCK-EDITOR.md` (new) — block format, core blocks and the app API.

## Files

- `index.php`, `install.php` (version only)
- `admin/assets/js/block-editor.js`
- `admin/assets/js/block-editor-ux.js`
- `admin/assets/css/block-editor.css`
- `admin/assets/css/block-editor-ux.css`
- `admin/assets/css/blocks.css` (new)
- `admin/views/posts/edit.php`
- `admin/views/api/reference.php`
- `app/Services/BlockRenderer.php`
- `BLOCK-EDITOR.md` (new)
- `CHANGELOG-1.2.32.md`, `RELEASE-NOTES-1.2.32.md` (new)
