# Basehim Block Editor

The post editor (Block Editor 2.0, Basehim 1.2.32+) is a full-screen, Gutenberg-style writing surface: a block inserter and List View on the left, the canvas with a floating block toolbar in the middle, and Post / Block settings on the right.

## Stored format

Content is saved as JSON in the post's `content` field with `content_format = blocks`:

```json
{"version":1,"blocks":[
  {"id":"b_1","type":"heading","data":{"text":"Hello","level":2}},
  {"id":"b_2","type":"columns","data":{},"innerBlocks":[
    {"id":"b_3","type":"column","data":{},"innerBlocks":[
      {"id":"b_4","type":"paragraph","data":{"text":"Left"}}]}]}
]}
```

`innerBlocks` appears only on container blocks, so documents written by the 1.x editor load unchanged. `App\Services\BlockRenderer` renders the JSON to HTML on the public site and links `admin/assets/css/blocks.css` once per page.

### Shared settings (any core block)

| Key | Meaning |
| --- | --- |
| `anchor` | HTML `id` |
| `className` | Extra CSS classes |
| `styleName` | Style variation, rendered as `is-style-{name}` |
| `align` | `left`, `center`, `right` for text blocks; `left`, `center`, `right`, `wide`, `full` for media and layout blocks |
| `textColor`, `backgroundColor` | Hex colours |
| `fontSize` | `small`, `medium`, `large` or `x-large` |

### Core blocks

| Type | Data |
| --- | --- |
| `paragraph` | `text` (inline HTML), `dropCap` |
| `heading` | `text`, `level` (1–6) |
| `list` | `style` (`ul` or `ol`), `items` (inline HTML strings), `start`, `reversed` |
| `quote`, `pullquote` | `text`, `cite` |
| `code` | `code`, `language` |
| `preformatted` | `text` |
| `html` | `html` (raw, trusted) |
| `details` (container) | `summary`, `open` |
| `image` | `url`, `alt`, `caption`, `width`, `href`, `linkTarget`, `id` |
| `gallery` | `images` (`url`, `alt`, `caption`), `columns`, `crop`, `caption` |
| `video` | `src`, `poster`, `caption`, `autoplay`, `loop`, `muted`, `controls`, `playsInline`, `preload` |
| `audio` | `src`, `caption`, `autoplay`, `loop` |
| `file` | `href`, `fileName`, `showDownloadButton`, `downloadText`, `showPreview` |
| `embed` | `url` (a page URL from YouTube, Vimeo, Dailymotion, Spotify, SoundCloud, Loom, CodePen, TED, X or Google Maps; any other URL is used as the iframe source), `caption`, `responsive` |
| `cover` (container) | `url`, `backgroundType`, `overlayColor`, `dimRatio`, `minHeight`, `minHeightUnit`, `contentPosition`, `hasParallax`, `focalPoint`, `alt` |
| `media-text` (container) | `url`, `mediaType`, `alt`, `mediaPosition`, `mediaWidth`, `verticalAlign`, `isStackedOnMobile`, `imageFill`, `href` |
| `buttons` (container of `button`) | `justify`, `orientation` |
| `button` | `text`, `url`, `linkTarget`, `rel`, `width` (25, 50, 75 or 100) |
| `columns` (container of `column`) | `verticalAlign`, `isStackedOnMobile` |
| `column` (container) | `width` (percent), `verticalAlign` |
| `group` (container) | `layout` (`row` or `stack`), `justify`, `wrap`, `tagName`, `padding` |
| `divider` (Separator) | `styleName` (`wide` or `dots`), `backgroundColor` |
| `spacer` | `height` (px) |
| `table` | `head`, `body`, `foot` (arrays of rows of inline-HTML cells), `columnAlign`, `hasFixedLayout`, `caption` |
| `widget` | `widget` (key), `settings` |

## Extending from an app

Load a script on the editor page with `$this->addEditorScript($this->asset('js/editor.js'))`, then:

```js
BasehimEditor.registerBlock('acme/notice', {
  title: 'Notice', icon: 'exclamation-triangle', category: 'widgets',
  description: 'A highlighted notice.', keywords: ['alert'],
  defaults: { text: '', tone: 'info' },
  richField: 'text',            // Enter splits, Backspace merges
  // ctx (2.0) gives the same rich text as core blocks: Enter, Backspace,
  // formatting toolbar, links, placeholders and undo all work.
  edit: function (el, block, api, ctx) {
    el.appendChild(ctx.rich({ field: 'text', tagName: 'p', placeholder: 'Write a notice…' }));
  },
  // Controls in the block toolbar.
  toolbar: function (tb, block, api) {
    tb.group().button({ icon: 'sparkles', label: 'Toggle tone', onClick: function () {
      api.updateBlock(block.id, { tone: block.data.tone === 'info' ? 'warning' : 'info' });
    } });
  },
  // The Block tab of the sidebar. `ui` builds standard controls.
  inspector: function (el, block, api, ui) {
    ui.select('Tone', block.data.tone, [['info', 'Info'], ['warning', 'Warning']], function (v) {
      api.updateBlock(block.id, { tone: v });
    });
  },
  // Optional HTML fallback, stored in data.html, used when no PHP renderer exists.
  save: function (block) { return '<p class="notice">' + block.data.text + '</p>'; }
});
```

Container blocks set `container: true` and render their children with `ctx.inner(element)`. `allowedBlocks`, `parent` and `template` (a function returning `[[type, data, innerTemplate?], …]`) work as in Gutenberg.

`ui` offers `text`, `textarea`, `number`, `range`, `select`, `toggle`, `buttons`, `media`, `help` and `panel`.

The 1.x signatures (`edit(el, block, api)`, `inspector(el, block, api)`, Font Awesome icon names) still work.

Other entry points: `addToolbarButton`, `addBlockAction`, `addSidebarPanel`, `on('init' | 'change' | 'select' | 'save' | 'block:add' | 'block:remove')`, `addFilter('save.data' | 'load.data')`, `getBlocks`, `setBlocks`, `insertBlock(type, data, index, parentId)`, `insertBlocks`, `updateBlock`, `removeBlock`, `moveBlock`, `duplicateBlock`, `getSelected`, `select`, `deselect`, `serialize`, `htmlToBlocks`, `undo`, `redo`.

### PHP

```php
$this->registerBlockRenderer('acme/notice', fn(array $data, array $block) =>
    '<p class="notice notice--' . htmlspecialchars($data['tone'] ?? 'info') . '">'
    . htmlspecialchars(strip_tags($data['text'] ?? '')) . '</p>');
```

Filters: `blocks.pre_render`, `blocks.render.{type}` (receives the block with its `innerBlocks`), `blocks.rendered`, and `blocks.stylesheet` (return another URL, or an empty string to stop the front-end stylesheet being linked).

## Keyboard shortcuts

Shift+Alt+H in the editor lists them all. The main ones:

| Keys | Action |
| --- | --- |
| Ctrl+S | Save |
| Ctrl+Z / Ctrl+Shift+Z | Undo / redo |
| / | Choose a block (in an empty paragraph) |
| `# `, `- `, `1. `, `> ` | Heading, list, numbered list, quote |
| Ctrl+B, Ctrl+I, Ctrl+K | Bold, italic, link |
| Shift+Enter | Line break |
| Esc | Select the current block |
| Ctrl+Shift+D | Duplicate block |
| Shift+Alt+Z | Remove block |
| Ctrl+Alt+T / Ctrl+Alt+Y | Insert before / after |
| Ctrl+Shift+Alt+T / Y | Move up / down |
| Ctrl+Shift+, | Show or hide the settings sidebar |
| Shift+Alt+O | List View |
