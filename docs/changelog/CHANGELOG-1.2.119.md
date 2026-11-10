# Basehim 1.2.119

Dashboard welcome card (option B) and an admin sidebar refresh (R1), each
chosen from options mocked on the real admin. No database changes.

## Dashboard welcome card

- **Customize is an icon button** (`.bh-btn--ghost.bh-btn--icon`, "Customize
  dashboard"): a page setting, so it no longer competes with the everyday
  actions. Wide screens: at the end of the row. Phones: in the card's
  top-right corner, with the greeting kept clear of it.
- **Phones: New post and Upload split the row in equal halves** (they were
  sized by their words — 106 / 92 / 114px). The card stays 173px at 390px.
- Customize stays inside `[data-bh-dash-view-actions]`, so Customize mode still
  hides it; CSS and markup only.

## Sidebar (R1)

Measured at 1366×900, collapsed, and as the phone drawer.

**Bugs fixed**

- **Search revealed whole groups.** Searching "tag" showed Posts with all five
  sub-items. A group's own name still shows all of it; otherwise only matching
  sub-items show, opened (`.is-search-open`).
- **Search left a stray divider** above the first result.
- **The collapsed rail lost counts** (the Comments badge was hidden). It's now
  an 8px dot on the icon.

**Refresh**

- **One marker for the current page:** the accent pill. The second marker (a
  blue bar on the screen edge) is gone. Nav uses the `accent-*` token, not raw
  `blue-*`.
- **One item height:** groups were 40px, links 36px — all 36px.
- **Sections are separated by their heading alone** (a hairline rule plus a
  heading was doubled). The collapsed rail, which has no headings, keeps a
  short rule.
- **Sub-items hang off a guide line** under the parent's icon, the current one
  marked on the line and announced (`aria-current="page"`); the grey dots are
  gone. The group toggle is a 24px target.
- **Menu search is a core-sized field** (36px, 14px; it was 38px, 13px) and
  shows its existing `/` shortcut (hidden in use and on touch screens).
- **The footer is one row** — version with update status, then collapse — 57px
  instead of 117px, so ~60px more menu shows before scrolling. The status is a
  dot (green up to date, pulsing amber with a count when updates wait); its
  words stay for screen readers and the tooltip.
- **"View site" is the site address** under the site name (with an icon); the
  separate footer row is gone. **The logo goes to the Dashboard** (it opened
  basehim.com in a new tab).
- The touched sidebar styles moved from the layout's inline `<style>` into
  core components in `src/tailwind.css` (`@apply`); the inline block keeps the
  collapse and drawer mechanics.

## Build

`tailwind.min.css` rebuilt and verified a superset of the previous bundle;
five utilities only the old sidebar used (`ease-in-out`, `h-1`, `pl-11`,
`pt-2`, `w-1`) were added to `tailwind.safelist.js` for apps and themes.

## Files

- `admin/views/dashboard/index.php`, `admin/views/layouts/app.php`
- `admin/assets/css/src/tailwind.css` + compiled `tailwind.min.css`
- `tailwind.safelist.js`

## Tests

- `tests/Unit/Release12119Test.php`.
