# Basehim 1.2.8 — the block editor saves what you edit

Both reports were reproduced in a real browser before anything was changed: the
edit screen, the editor scripts and the form were loaded in Chromium, edited
the way an author does, and every field the browser submitted was recorded.

## B. Editing a published post turned it into a Draft

**Worse than reported:** it also removed the post's categories and featured
image, and reopened comments.

The editor moves the post-settings cards — Status, Categories, Tags, Featured
image, Comments — into its sidebar. `renderSidebar()` begins with
`sidebarEl.innerHTML = ''` and only puts the cards back on the **Post** tab.
Clicking into a block switches the sidebar to the **Block** tab automatically,
so as soon as an author edited any text, those cards were no longer in the
page. Fields outside the page are not submitted. The server read the missing
fields as their defaults: status `draft`, no categories (now Uncategorized, as
of 1.2.7), no featured image, comments open.

Recorded before the fix, saving after editing a paragraph:

    status, term_ids[], featured_media_id, comment_status   → not sent

After:

    status: published, term_ids[]: 5, featured_media_id: 31, comment_status: closed

**Fix, in the editor:** the cards stay in the form whichever tab is showing —
hidden on the Block tab, still submitted.

**Fix, on the server:** the settings panel now carries a hidden
`_post_settings` field. When a save arrives without it, `PostController`
leaves status, categories, tags, featured image and comment setting exactly as
they are instead of applying defaults. No future editor change or app script
can unpublish a post this way again.

## A. Changes made in HTML/source mode were not saved

`block-editor.js` wrote its block JSON into the content field on load, on every
change, and again on submit — **whatever format was selected.**

- For a post saved as HTML, the source box showed block JSON instead of the
  post's HTML the moment the page loaded.
- Whatever the author typed in HTML or Markdown mode was overwritten with the
  editor's copy just before the form was sent, so the original content came
  back after saving.
- Switching a block post to HTML showed JSON, not HTML.

**Fix:** the editor writes to the content field only while the format is
**Blocks**. In HTML and Markdown mode the source box is the author's, and is
saved exactly as typed.

**Switching Visual → HTML (or Markdown) now converts:** the blocks are rendered
to real HTML by the same renderer that renders the post for visitors
(`/admin/posts/editor/render`). If conversion fails, the editor stays in Visual
mode with a message, rather than leaving block JSON in a box that would be
saved as HTML.

## Media picker layout

**Images drawn over each other.** Each card has `aspect-ratio` and
`overflow: hidden`, which gives it an automatic minimum height of zero. When
the grid's height was limited, the browser squeezed the rows instead of
scrolling, and the cards overlapped. This happened on every phone and **on
desktop too** with a larger library: with 24 images at 1280×720, rows were
70 px apart for 164 px cards. Rows are now sized to their cards and the grid
scrolls.

**Selected image too small on phones.** The details pane put the preview in a
16:7 strip, which was then squeezed further — the selected image was 36×55 px
on a 390 px phone. It now shows as a square beside the file's name and size,
about 40% of the width (94×141 px for the same image), with alt text and
caption below.

Desktop layout is unchanged apart from the row fix.

## Verified

In Chromium, with the real editor scripts:

- saving without touching a block, and after editing a paragraph (Block tab);
- changing status and categories on the Post tab, then editing, then saving;
- switching tabs: the settings hide on Block, return intact on Post;
- an HTML post: source shown as HTML, edits saved as typed;
- Visual → HTML: real HTML; edit; back to Visual: the edit carried over;
- conversion failure: stays in Visual with a message, saves valid blocks;
- the picker at 1280×900, 820×1100 and 390×844, with 8 and 24 images.

On a live install, through the real update handler: a save without the
settings panel keeps status, categories and featured image; an explicit change
is applied; an HTML save is stored as sent.

## Files

    admin/assets/js/block-editor.js                  write content only in Blocks mode; keep settings in the form
    admin/views/posts/edit.php                       Visual→HTML conversion; _post_settings marker
    app/Http/Controllers/Admin/PostController.php    absent settings panel leaves settings unchanged
    admin/assets/css/media-picker.css                grid rows; phone details layout

## After updating

Reload any editor tab that was open before the update. A tab opened earlier has
no settings marker, so a status or category change saved from it would be
ignored (safely — nothing is reset).
