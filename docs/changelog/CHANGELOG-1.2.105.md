# Basehim 1.2.105

Data table Quick View: a full-width item for a notes or description block. No
database changes.

## Why

Quick View items are a two-column grid of short label/value pairs, and an item
with no label was dropped. That left no good way to show a paragraph — release
notes, a description — inside a Quick View.

## The change

A Quick View item may now set `wide => true`. A wide item:

- spans the full width of the Quick View instead of a half-width grid cell;
- keeps the text's own line breaks (`white-space: pre-wrap`);
- may have no label (a plain block), where an ordinary labelless item is still
  dropped as a stray value;
- allows more text (5,000 characters, against 2,000 for an ordinary item).

For an app, a Quick View section item becomes:

```php
['label' => '', 'wide' => true, 'value' => $notes]
```

- `app/Http/Controllers/Ajax/TableActions.php` keeps the `wide` flag through
  `cleanView()` and relaxes the labelless-item rule for wide items.
- `admin/assets/js/datatable.js` renders a wide item full-width and omits an
  empty label.
- `admin/assets/css/datatable.css`: `.bh-qv__item--wide` spans both columns and
  wraps pre-formatted; an empty `dt` is hidden.

CloudHim 1.9.0 uses this to show release notes in a release's Quick View.

## Tests

`tests/Unit/Release12105Test.php`.
