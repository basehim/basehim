# Basehim 1.2.120

Sidebar footer and Settings navigation. No database changes.

## Footer: the update status in words

1.2.119 showed the update status as a dot and a bare count ("● 3"). The footer
row is now a 32px logo tile beside two 16px lines — the tile exactly as tall
as both — then the collapse button:

- **Basehim 1.2.120**
- **● You're up to date** (green), **● 3 updates available** (amber, pulsing),
  or **● Check for updates** (grey).

The live refresh (dashboard check, Updates page) writes the same words. The
count chip remains the source those scripts watch; the words carry the number.

## Settings

- **Settings opens the Settings home on phones.** The sidebar link is now
  `/admin/settings`, which phones show as the list of sections and wide screens
  as General (marked current in the sub-menu) — so desktop is unchanged.
- **The sub-menu lists every section** (13; it was a hand-kept copy of 9,
  missing AI Agents, Security, Search and Admin bar). It is now built from
  `admin/views/settings/_sections.php`, the list the tabs and the phone's
  Settings home already used, so it can't fall behind again.
- **Appearance is gone from the tabs, the Settings home and the sub-menu.** Its
  address already forwarded to Customize, where its logo, favicon, footer and
  CSS live. The entry stays (`'menu' => false`), so old links still land there.

## Files

- `admin/views/layouts/app.php`, `admin/views/settings/_sections.php`,
  `_nav.php`, `_head.php`
- `admin/assets/css/src/tailwind.css` + compiled `tailwind.min.css` (verified a
  superset of the previous bundle)

## Tests

- `tests/Unit/Release12120Test.php`.
