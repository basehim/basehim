# Basehim 1.2.48

**Markdown posts open in the visual editor as blocks**, the way HTML posts
convert, and Markdown that was saved under the HTML format is recognised
everywhere.

## Why

Drafts written by AI tools, scripts, the REST API or MCP are often Markdown
saved with the default `html` format. Such a post:

- opened in the HTML source view, and switching it to the visual editor
  produced paragraphs full of `**` and `*`, with the code sample broken up;
- would have shown its raw `**` and `*` to visitors once published.

Even a post correctly labelled `markdown` converted badly. A numbered list
with a code sample under one step became a single Custom HTML block, and
bold lines used as headings stayed plain paragraphs.

On CDIY this was draft #75, "How to blink LED using Arduino". All 4,743 CDIY
posts were checked: it is the only Markdown one. The other drafts are HTML
(WordPress block markup) or empty placeholders, and are unaffected.

## What changes

**Opening a Markdown post** shows it in the visual editor straight away,
converted into blocks, with a note: *"This post is written in Markdown, so it
has been opened as 23 blocks. Saving stores it as blocks."*

- Nothing is written until you save, and leaving without saving gives no
  "unsaved changes" warning.
- **Keep Markdown** in the note puts the original back, byte for byte, and
  this browser remembers the choice for that post.
- Undo starts from the converted post, not from an empty page.

**Better conversion** (Markdown → blocks):

| Markdown | Before | Now |
|---|---|---|
| `**Introduction**` on its own line | paragraph with `**` (or bold text) | Heading, one level below the post's `#` headings (H2 when it has none) |
| Numbered steps with a code sample under step 6 | the whole list as one Custom HTML block | List 1–6, Code block (language kept), List continuing at 7 |
| A list item with an image or a table | Custom HTML | the list split around an Image or Table block |
| `Q: …` / `A: …` on consecutive lines | run together | line break kept |
| Prose hard-wrapped by an editor | — | still one paragraph |
| `3. Third step` as the first item | numbered from 1 | numbered from 3 (on the site too) |
| `- [x] Done` task items | checkbox lost | ☑ / ☐ kept in the text |

A nested bullet list stays one Custom HTML block, because the list block holds
a single level; the note says when that happens.

**HTML-labelled Markdown is treated as Markdown**:
- in the editor;
- on the public site, so it is no longer printed with raw `**`;
- in the plain text given to AI agents (`llms.txt`, the agent catalogue).

The check is strict. Any block-level HTML tag or WordPress block comment means
HTML, and it needs two kinds of Markdown structure (headings, lists, code,
bold, links, tables, quotes) before it calls something Markdown. Run on CDIY,
it picked out exactly one post: #75.

Switching from **HTML** to **Visual editor** by hand also converts as
Markdown when the HTML box actually holds Markdown.

## For developers

- `Markdown::looksLikeMarkdown($content)`: the detection, also used by the
  `post.content` filter.
- `Markdown::toHtml($md, Markdown::FOR_BLOCKS)`: the conversion for the
  editor. Without options, rendering is unchanged (CommonMark rules).
- `posts.render` accepts `for: 'blocks'`.
- `BasehimEditor.resetHistory()`; the `bh:editor-baseline` event resets the
  unsaved-changes baseline.
- docs/APP-DEVELOPMENT.md: new "Content formats" section.

No database changes. Posts are only converted when someone opens and saves
them.

Files changed: `app/Services/Markdown.php`, `app/Core/Application.php`,
`app/Services/AiAccessService.php`, `app/Http/Controllers/Ajax/PostActions.php`,
`routes/ajax.php`, `admin/views/posts/edit.php`,
`admin/assets/js/block-editor.js`, `admin/assets/css/block-editor.css`,
`docs/APP-DEVELOPMENT.md`, `docs/APP-AJAX.md`, `index.php`, `install.php`.
