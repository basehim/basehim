# Admin UI components

Basehim's admin layout (1.2.28+) provides a small set of components so core
screens and apps look and behave the same. Use them instead of hand-written
Tailwind class strings.

Since 1.2.77 they are Tailwind components: written with `@apply` in
`admin/assets/css/src/tailwind.css` and compiled into
`admin/assets/css/tailwind.min.css`, which every admin layout loads. They share
one family of shapes: cards and large buttons `rounded-2xl` with a slate-200
hairline and the `shadow-card` elevation; buttons, inputs and tiles
`rounded-xl`; icon squares `rounded-xl` in the accent's lightest shade.

**One accent colour (1.2.80).** Components use a single accent, `accent-*` in
`tailwind.config.js` (blue: 50 `#eff6ff` … 950 `#172554`), and slate neutrals.
Red is kept for errors and destructive actions, amber only for warning icons.
Controls share one height scale: 32 px (`--sm`), 36 px, 40 px (`--lg`), small
fields included. What a card says wraps; titles, labels and details are never
cut with "…". Sites
never build anything. After changing a view or the source, rebuild with
`npm run build:css` (Tailwind 3.4, see `tailwind.config.js`); a class made up
at runtime must appear whole in a scanned file or the source.

## Buttons

```html
<button type="submit" class="bh-btn bh-btn--primary">Save changes</button>
<a href="…" class="bh-btn bh-btn--secondary">Cancel</a>
<button type="button" class="bh-btn bh-btn--danger">Delete</button>
<button type="button" class="bh-btn bh-btn--ghost bh-btn--sm">More</button>
```

Variants: `--primary` (solid accent; one per screen, the main action),
`--secondary`, `--danger` (destructive), `--ghost` (low emphasis). Sizes:
`--sm` (32 px), default (36 px), `--lg` (40 px). `--icon` makes a square
button for an icon alone; give it an `aria-label`:

```html
<button type="button" class="bh-btn bh-btn--secondary bh-btn--sm bh-btn--icon" aria-label="Refresh"><?= icon('arrow-path', 'w-4 h-4') ?></button>
```

A large button may lead with an icon square (`bh-btn__icon`, the accent's
lightest shade; white on a primary button) and end with `bh-btn__chev`. Before
1.2.80 the squares came in `--green`, `--violet` and `--amber`; those names
still work and now look the same as the default.

While a request runs: `bhBusy(button, true, 'Saving…')`, then
`bhBusy(button, false)` — shows a spinner, blocks clicks, restores the label.
It is the same loader as `bhLoader(button, …)` (1.2.83), which can also end
with `done('Saved')` or `fail('Not saved')`: a check or exclamation icon, then
the label comes back. A busy button's spinner and icon come from that loader
only; don't add your own (no `::before` on `[aria-busy]`, no ✓ characters):
a second source is what drew two spinners before 1.2.83.

Wording: "Save changes" for saving a form; name other actions by what they do
("Delete", "Revoke", "Install updates"), sentence case.

## Cards

```html
<section class="bh-card">
  <div class="bh-card__head"><h2 class="bh-card__title">Profile</h2> … </div>
  <div class="bh-card__body"> … </div>
</section>
<div class="bh-card bh-card--pad"> … </div>
```

## Form fields

```html
<label class="bh-label" for="site-title">Site title</label>
<input id="site-title" name="title" class="bh-input">
<p class="bh-help">Shown in the browser tab.</p>
```

Also `.bh-select` and `.bh-textarea`. Fields fill their container; add
`--auto` for natural width (e.g. dropdowns in a filter row) and `--sm` for a
compact field (toolbars). Mark an invalid field with
`aria-invalid="true"`. Always give a label `for=` (a label right before its
field is linked automatically, but explicit is better).

## Switches, choices and segmented controls (1.2.82)

An on/off setting is a switch: a real checkbox with `role="switch"` laid over
its track, so clicks, the keyboard (Space) and forms work as usual.

```html
<span class="bh-switch">
  <input type="hidden" name="comments" value="0">   <!-- posts 0 when off -->
  <input type="checkbox" role="switch" class="bh-switch__input" id="comments" name="comments" value="1" checked>
  <span class="bh-switch__track" aria-hidden="true"></span>
</span>
```

Give it a `<label for=…>` (or an `aria-label`). Radio options that need a
sentence each are rows: `.bh-choices > label.bh-choice` with a radio, a
`.bh-choice__title` and a `.bh-choice__text`; the chosen row is outlined in
the accent. Two to four short exclusive options sit in one track:
`.bh-seg > label > input[type=radio].sr-only + text` (a chosen `block` value
shows in red). Notes inside a card: `.bh-note` (neutral) and
`.bh-note--warn`, an icon then the text.

## Settings screens (1.2.82)

Core's Settings pages are built from `admin/views/settings/_ui.php`, which an
app's own settings page can use too (`require_once` it):

```php
<?= bh_set_card_open('Comments', 'Who can comment, and when.') ?>
  <?= bh_set_toggle('allow_comments', $on, 'Allow comments', 'Visitors can comment on posts.') ?>
  <?= bh_set_row('Close after', '<input id="close" name="close_after" class="bh-input">', 'Days. 0 keeps them open.', ['for' => 'close']) ?>
<?= bh_set_card_close() ?>
<?= bh_set_save() ?>
```

A section is the core card; its rows put the name and help on the left and
the control on the right (`wide` puts a long control under the name, `sub`
indents a setting that depends on the one above). On phones the same markup
becomes a phone-style settings screen: the group's heading above an inset
panel, larger tap targets, and Save docked at the bottom.
`/admin/settings` is the phone's list of sections, and the sections open
without a page load (`settings.section`, `admin/assets/js/settings-nav.js`),
sliding in as in a phone's own Settings app.

## Marketplace (1.2.84)

`/admin/marketplace` is built from the components on this page and the
`.bh-mk-*` classes in `admin/assets/css/src/tailwind.css`: the dashboard's
header panel, a toolbar card (Apps / Themes, search, sort), category chips,
a featured card and a grid of cards — four a row on large screens, three on
tablets, two on phones (themes one). It has no stylesheet of its own and no
colours outside the accent and slate. `admin/assets/js/marketplace.js` talks
to the `apps.market_*` / `themes.market_*` gateway actions through
`Basehim.ajax`, and busy buttons use `bhBusy()`. The details window
(`partials/item-modal.php`, `.bh-im*`) is styled from the same sheet.

## Badges

`<span class="bh-badge bh-badge--green">Published</span>` —
`--gray`, `--blue`, `--green`, `--amber`, `--red`, `--violet`, `--indigo` (the last two since 1.2.77).

## Empty states

```html
<div class="bh-empty">
  <span class="bh-empty__icon"><?= icon('document-text') ?></span>
  <p class="bh-empty__title">No posts yet</p>
  <p class="bh-empty__text">Posts you write appear here.</p>
  <div class="bh-empty__actions"><a class="bh-btn bh-btn--primary" href="…">Write a post</a></div>
</div>
```

## Dialog windows (1.2.102)

For a form or details in a pop-up window, use the shared dialog (`ui.css`)
rather than your own modal and stylesheet. It is a native `<dialog>`, so the
browser handles Escape, focus and the page behind it. On phones it opens as
a sheet from the bottom, with the body scrolling and the buttons in view.

```html
<button type="button" class="bh-btn bh-btn--primary" data-bh-dialog-open="key-dialog">New key</button>

<dialog class="bh-dialog" id="key-dialog" aria-labelledby="key-dialog-title" data-bh-dialog-focus="#key-name">
  <form method="POST" class="bh-dialog__form">
    <header class="bh-dialog__head">
      <div><h2 class="bh-dialog__title" id="key-dialog-title">New key</h2>
           <p class="bh-dialog__sub">Shown once.</p></div>
      <button type="button" class="bh-dialog__close" data-bh-dialog-close aria-label="Close"><?= icon('x-mark') ?></button>
    </header>
    <div class="bh-dialog__body"> fields </div>
    <footer class="bh-dialog__foot">
      <button type="button" class="bh-btn bh-btn--secondary" data-bh-dialog-close>Cancel</button>
      <button type="submit" class="bh-btn bh-btn--primary">Create</button>
    </footer>
  </form>
</dialog>
```

Sizes: `bh-dialog--sm` (24rem), default (32rem), `bh-dialog--lg` (44rem).
`window.bhDialog(id)` opens one from script. A click on the backdrop closes
it. For yes/no questions keep using `bhConfirm()`.

## Checkboxes (1.2.102)

A switch is for one on/off setting; a list of choices ("which roles",
"which scopes") uses the checkbox:

```html
<div class="bh-checks">
  <label class="bh-check">
    <input type="checkbox" class="bh-check__input" name="roles[]" value="editor">
    <span class="bh-check__text">Editor <span class="bh-check__help">Optional line.</span></span>
  </label>
</div>
```

The box is 18px in the accent colour. The whole row is the target, at least
44px tall on touch screens. `bh-checks` lays several out in columns that fit
the width.

## Small tables (1.2.102)

Lists that are long, searchable or need bulk actions belong in the
[Data Table](DATA-TABLE.md). A short list (a site's menus, its API keys) can
be a plain table:

```html
<table class="bh-table bh-table--stack">
  <thead><tr><th>Name</th><th>Location</th><th><span class="sr-only">Actions</span></th></tr></thead>
  <tbody><tr>
    <td class="bh-table__primary">Main menu</td>
    <td data-label="Location"><span class="bh-badge bh-badge--blue">primary</span></td>
    <td class="bh-table__actions"><a class="bh-btn bh-btn--secondary bh-btn--sm" href="…">Edit</a></td>
  </tr></tbody>
</table>
```

On phones (under 640px) `--stack` turns each row into a block:
- the primary cell leads;
- each other cell sits under its `data-label`;
- the actions run along the bottom.

A cell with `colspan` (an inline editor) stays full width. Without `--stack`,
a table scrolls sideways inside the page on phones, as every admin table
always has.

## Dialogs and toasts (JavaScript)

| | |
|---|---|
| `await bhConfirm(message, { danger, confirmLabel, cancelLabel })` | yes/no in the admin's dialog → `true` / `false`. Destructive questions are red and start on Cancel. |
| `await bhAlert(message)` | a message to acknowledge. `alert()` uses it too. |
| `bhToast(message, type, { timeout })` | a short notice, bottom right. `type`: `success` (default), `error`, `warning`, `info`. Returns a function that dismisses it. |
| `bhBusy(button, busy, label)` | button loading state (above). |
| `bhLoader(anchor, label, { place })` | the shared loader — inside a button, or a pill elsewhere (below) → `{ set, done, fail, remove }`. |

All are also on `window.Basehim.ui` (`confirm`, `alert`, `toast`, `busy`, `loader`).

## Loader

One loader for every wait on the server. On a **button** (Save changes on the
Settings screens, Save draft in the post editor, Delete in the media library)
the spinner and a word or two go *inside* the button: it is disabled while the
request runs, shows ✓ or ! when it finishes, and then gets its own label back.
Anywhere else it is a dark pill with a spinner — the media library shows one
over an image while the original loads. Use it rather than a spinner of your
own, so waiting looks the same everywhere.

```js
var l = bhLoader(button, 'Saving…');           // spinner inside the button
doTheWork()
  .then(function () { l.done('Saved'); })        // ✓ Saved, label back after 2s
  .catch(function (err) { l.fail('Not saved'); bhToast(err.message, 'error'); });  // red, back after 3s
```

Keep the words short — they replace the button's label for a moment; the full
message belongs in a toast or the page's banner. `done(text, { timeout })` and
`fail(text, { timeout })` take the delay before the label comes back. While it
runs the button carries `bh-btn-busy` and `aria-busy="true"`.

For the pill, pass a place: `'before'` or `'after'` the anchor, `'inside'` it,
or `'over'` it, centred (give the anchor `position: relative`). A pill's
`done()` turns green and fades after 2s; `fail()` stays until removed.
`set(text)` changes the words while either kind runs; `remove()` ends it at
once. A second loader on the same anchor replaces the first. The pill in
markup:

```html
<span class="bh-loader" role="status"><span class="bh-loader__spin"></span><span class="bh-loader__text">Loading…</span></span>
```

`is-done` and `is-error` give the finished states; `bh-loader--over` centres it.
The styles are in `admin/assets/css/ui.css`, loaded on every admin page, which
also gives every `<pre class="overflow-x-auto">` (and `.bh-code`) a scrollbar
you can see.

A form or link with `onsubmit` / `onclick="return confirm('…')"` is switched
to `bhConfirm` automatically — but new code should call `bhConfirm` itself.
`confirm()` can't be replaced that way (it has to answer at once), so don't
use it in admin code.

## AJAX requests (JavaScript)

Call the server through the AJAX gateway rather than hand-writing `fetch()`.
The client is loaded on every admin page and handles the CSRF token, JSON,
errors and timeouts:

```js
bhBusy(button, true, 'Saving…');
Basehim.ajax.request('media.update', { id: 42, alt_text: input.value })
  .then(function (data) { bhToast('Saved'); })
  .catch(function (err) { bhToast(err.message, 'error'); })   // err.code, err.fields, err.requestId
  .then(function () { bhBusy(button, false); });
```

`err.message` is written for people and safe to show. Actions are registered
in PHP; see APP-AJAX.md.

## Also automatic

- Tables scroll sideways on narrow screens.
- Keyboard focus shows a ring on links, buttons and fields that set none.
- `prefers-reduced-motion` turns animations off.
