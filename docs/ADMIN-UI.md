# Admin UI components

Basehim's admin layout (1.2.28+) provides a small set of components so core
screens and apps look and behave the same. Use them instead of hand-written
Tailwind class strings.

Since 1.2.77 they are Tailwind components: written with `@apply` in
`admin/assets/css/src/tailwind.css` and compiled into
`admin/assets/css/tailwind.min.css`, which every admin layout loads. They share
one family of shapes: cards and large buttons `rounded-2xl` with a slate-200
hairline and the `shadow-card` elevation; buttons, inputs and tiles
`rounded-xl`; icon squares `rounded-xl` in a light tint of their colour. Sites
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

Variants: `--primary` (one per screen, the main action), `--secondary`,
`--danger` (destructive), `--ghost` (low emphasis). Sizes: `--sm`, and `--lg`
for a screen's few large actions (the dashboard's New post, Upload,
Customize), which lead with an icon square and end with a chevron:

```html
<a href="…" class="bh-btn bh-btn--secondary bh-btn--lg">
  <span class="bh-btn__icon bh-btn__icon--green" aria-hidden="true"><?= icon('arrow-up-tray', 'w-5 h-5') ?></span>
  <span class="bh-btn__label">Upload</span><?= icon('chevron-right', 'bh-btn__chev') ?>
</a>
```

Icon square tints: default blue, `--green`, `--violet`, `--amber`; on a
primary button it is white on the button's blue. Icons from `icon()` sit
inside and are sized for you.

While a request runs: `bhBusy(button, true, 'Saving…')`, then
`bhBusy(button, false)` — shows a spinner, blocks clicks, restores the label.

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
