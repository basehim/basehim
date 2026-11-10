# Basehim 1.2.118

The phone search placeholder is 14px, like every other field's (it was 16px).
Typed text stays 16px on phones: iOS zooms the page into any field whose text
is under 16px when it's tapped, and that size is what prevents it. CSS only.

- `admin/assets/css/src/datatable.css` + compiled `datatable.css` —
  `.bh-dt-search input::placeholder { font-size: .875rem; }` at ≤600px.
- Test: `tests/Unit/Release12118Test.php`.
