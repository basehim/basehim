# Admin UI components

Basehim's admin layout (1.2.28+) provides a small set of components so core
screens and apps look and behave the same. Use them instead of hand-written
Tailwind class strings.

## Buttons

```html
<button type="submit" class="bh-btn bh-btn--primary">Save changes</button>
<a href="…" class="bh-btn bh-btn--secondary">Cancel</a>
<button type="button" class="bh-btn bh-btn--danger">Delete</button>
<button type="button" class="bh-btn bh-btn--ghost bh-btn--sm">More</button>
```

Variants: `--primary` (one per screen, the main action), `--secondary`,
`--danger` (destructive), `--ghost` (low emphasis). Size: `--sm`.
Icons from `icon()` sit inside and are sized for you.

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
`--gray`, `--blue`, `--green`, `--amber`, `--red`.

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

All are also on `window.Basehim.ui` (`confirm`, `alert`, `toast`, `busy`).

A form or link with `onsubmit` / `onclick="return confirm('…')"` is switched
to `bhConfirm` automatically — but new code should call `bhConfirm` itself.
`confirm()` can't be replaced that way (it has to answer at once), so don't
use it in admin code.

## Also automatic

- Tables scroll sideways on narrow screens.
- Keyboard focus shows a ring on links, buttons and fields that set none.
- `prefers-reduced-motion` turns animations off.
