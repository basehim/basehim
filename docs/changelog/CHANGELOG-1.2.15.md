# Basehim 1.2.15 — HTML becomes real blocks in the visual editor

## The problem

Switching a post from HTML (or Markdown) to the visual editor put the whole
post into **one Custom HTML block** — a raw-code box. There were no paragraphs
to put an image between, no headings to move, no text to select and link.
Every post imported from WordPress opened this way.

## HTML → blocks

The editor now converts HTML into real blocks, in the browser, with the
browser's own HTML parser (so messy real-world markup is read exactly as a
browser reads it):

| HTML | Block |
|---|---|
| `<p>`, loose text, `<br><br>` runs | Paragraph (alignment kept) |
| `<h1>`–`<h6>` | Heading (h1 → h2: the post title is the page's h1) |
| `<img>`, `<figure>` + `<figcaption>`, `[caption]` shortcode | Image (alt text, caption, alignment) |
| `<ul>`, `<ol>` | List |
| `<blockquote>` | Quote (with its citation) |
| `<pre><code class="language-…">` | Code (language kept) |
| `<hr>` | Divider |
| `<iframe>`, WordPress YouTube embed | Embed |
| WordPress buttons | Button |

Missing block tags are fixed on the way: plain text gets paragraphs from blank
lines; two `<br>` in a row start a new paragraph; empty and `&nbsp;` paragraphs
are dropped; plain wrapper `<div>`s (WordPress groups, columns, sections) are
opened up; WordPress block comments are removed.

**Images are lifted out of paragraphs.** The paragraph renderer keeps only
simple formatting (bold, italic, links, code…), so an `<img>` left inside a
paragraph would vanish on save. Images inside paragraphs, links and spans
become image blocks of their own, where the reader saw them. A resized copy
linked to the full-size file uses the full size; lazy-loaded images use their
real address, not the placeholder.

**Nothing is lost.** What a block cannot hold without loss stays as a Custom
HTML block of its own — just that piece: tables, forms, scripts, video, nested
lists, an image linked to another page, social-media embeds, boxes styled with
a background or border. Neighbouring pieces share one box.

An `<img>` with no address (a broken import) becomes an empty image block, so
the editor shows *Choose from Media Library* where the picture belongs.

A note above the editor says what happened: *Converted into 46 blocks. 1 part
that blocks can't represent (tables, embeds, scripts…) was kept as Custom HTML.*

Markdown is converted to HTML on the server first
(`POST /admin/posts/editor/render` with `from=markdown`), then into blocks.

Content opened in the visual editor that is not block JSON is converted the
same way, instead of loading as one Custom HTML block.

For apps: `BasehimEditor.htmlToBlocks(html)` returns `[{ type, data }]`.

## Checked on a real imported article

A 12 KB WordPress article (7 headings, 19 paragraphs, 15 images, a table, a
list), switched from HTML to Blocks in Chromium: 46 blocks — 7 headings, 22
paragraphs, 15 images, 1 list, the table as Custom HTML. An image inserted
between two paragraphs landed there. Saved and rendered by the server: every
heading, image, the 48 table cells and the list present; **all 716 words of the
article identical**, none missing, none added.

## Files

    admin/assets/js/block-editor.js                 htmlToBlocks(); used when loading HTML
    admin/views/posts/edit.php                      HTML/Markdown → Blocks converts; the note
    app/Http/Controllers/Admin/PostController.php   editor render: from=markdown
