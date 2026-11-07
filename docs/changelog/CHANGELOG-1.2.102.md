# Basehim 1.2.102

The admin responsive and component pass. 71 admin screens were measured at
7 widths (320–1440 px), before and after. Full audit:
`claude/admin-responsive-audit.md` in the project. No database changes.

## Navigation drawer (below 1024 px)

- Escape closes it.
- The menu button carries `aria-controls="bh-sidebar"` and `aria-expanded`.
- Opening it moves focus to its close button and keeps Tab inside, and the
  drawer acts as a dialog (`role="dialog"`, `aria-modal`). Closing it returns
  focus to the menu button.
- While closed it is `inert`, so keyboard users no longer tab through ~40
  off-screen links before the page.
- Crossing to a wide screen closes it and clears the scroll lock.
- The menu and close buttons are 40 px.

## Data tables

- **Phone cards** (`datatable.css`): under 420 px of card width the author and
  values move under the title (the threshold was 300 px). A date no longer
  stacks four lines in a 3 rem column, and titles keep the full width. This
  covers every phone and the two-up cards at 768 px.
- **Selection:** a table with no bulk action shows no checkboxes
  (`data-select="none"` on the table, set by `partials/datatable.php`).

## Users on the Data Table

- New table `users.list` (`routes/tables.php`, `Services/Table/UserTableProvider`):
  - search, Role and Status filter menus, sorting (name, last sign-in,
    registered), column choices, density and phone cards;
  - Edit (link) and Delete (row action, asked first). Delete is offered only
    where `AccessControl::canManage()` allows: never yourself, never someone
    at your level or above.
- `UserRepository::paginate()` gains a `status` filter and a `sort`
  whitelist (`UserRepository::SORTS`). The default order is unchanged.
- The old screen's role filter listed "Roles" (the config file's top-level
  key) instead of the roles; the filter now lists every role, custom ones
  included, from `AccessControl::roles()`.
- `admin/views/users/index.php` is the table plus the delete form. The
  controller is unchanged.

## Shared components (`admin/assets/css/ui.css`, layout script)

- `bh-dialog`: a native `<dialog>` with head, body and foot; a bottom sheet
  on phones. The layout opens `[data-bh-dialog-open]`, closes
  `[data-bh-dialog-close]` and backdrop clicks, and adds `window.bhDialog()`.
- `bh-check` / `bh-checks`: the checkbox for "pick several", with 44 px rows
  on touch screens.
- `bh-table` / `bh-table--stack`: a small table that stacks into labelled
  rows on phones. The layout's mobile "tables scroll sideways" rule skips it.
- Touch screens: the settings "‹ Settings" link is 44 px tall.
- These live in `ui.css` because the compiled Tailwind bundle can't be
  rebuilt in this environment. Views use only utilities already compiled.

## Screens moved onto core components

- **API → Keys:**
  - `bh-dialog` with `bh-input`, `bh-select`, `bh-label` and `bh-check`
    (its 90-line `<style>`, 22 hex colours, hand-made modal and global Escape
    listener are gone);
  - the list is `bh-table--stack` with core badges and buttons;
  - the empty state is `bh-empty`.
- **Widgets / Widget areas:** core tab bar (`widgets/_nav.php` →
  `partials/tabs.php`) instead of a dark-pill switcher.
- **Menus:**
  - the editor's buttons, fields and checkboxes are `bh-btn`, `bh-input` and
    `bh-check`;
  - the list is `bh-table--stack`.
- **Categories / Tags:** `bh-table--stack`, core fields and buttons, and
  labelled Edit buttons with `aria-expanded`.
- **Checkboxes** (Settings → Admin bar roles and options, Roles,
  Themes → Replace, app consent): `bh-check`.
- **Hand-made fields and buttons:** the two most common patterns (a
  `w-full px-3 py-2 border …` field, a `px-4 py-2 bg-blue-600 …` button)
  replaced with `bh-input`, `bh-select`, `bh-textarea` and
  `bh-btn bh-btn--primary` across Users, Profile, Menus, Roles, Themes,
  System, Pages, app consent and the 404 page (22 places). The field labels
  on those forms became `bh-label`.
- **API → Reference:** copy buttons are `bh-btn--ghost` icon buttons with a
  label (were 14 × 24 px in light grey).
- **Updates:** the connection line wraps instead of being cut off.
- `.nbtn*` / `.ncard` in the layout are marked deprecated; core no longer
  uses them.

## Docs and tests

- `docs/ADMIN-UI.md` (Dialog windows, Checkboxes, Small tables),
  `docs/DATA-TABLE.md` (Selection and phone cards).
- `tests/Unit/Release12102Test.php`.
