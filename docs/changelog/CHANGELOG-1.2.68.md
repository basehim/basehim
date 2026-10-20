# Basehim 1.2.68

> **Withdrawn.** As first built, this release added `App::registerDashboardWidget()`,
> which clashed with the Analytics app's own private method of that name and
> stopped sites running it from loading. Install [1.2.69](CHANGELOG-1.2.69.md)
> instead; it contains everything below without that method.

A dashboard widget framework: core and apps register cards through one
contract, each person arranges their own dashboard (stored in the database, so
it follows them across devices), and every card's data is loaded on its own,
behind its own permission. Also closes an information leak on the dashboard.

Developer guide: [docs/DASHBOARD-WIDGETS.md](../DASHBOARD-WIDGETS.md).

## Audit — what was there before

| Piece | 1.2.67 | Problem |
|---|---|---|
| `DashboardController` | Built six counts and two lists for every user | No permission checks; slow queries block the page |
| `admin/views/dashboard/index.php` | Hard-coded stat cards, Recent posts, Recent comments, then a "Widgets" section for registered dashboard widgets | Core panels weren't widgets; nothing could be moved, hidden or configured |
| `WidgetRegistry` | `register(key, def)` with `render` callable, `surfaces`, `fields`, `dashboard.width/priority` | No owner checks (any app could replace any key, including core's), malformed definitions silently dropped, no permission metadata, exception messages printed into page HTML |
| `App::registerWidget()` | Namespaced keys without a dot | A key with a dot (`core.x`, `other-app.x`) went through as-is |
| Per-user storage | `users.meta` JSON (`datatable` key) via `UserService::meta/setMeta` | Reused as is for layouts |
| AJAX | `AjaxRegistry` + `AjaxGateway` (capability, CSRF, schema, rate limit) | Reused; no new API layer |
| `widgets.render` | Any `edit_posts` user could render any widget on any surface | A contributor could render an admin-only dashboard widget |
| Drag and drop | None on the dashboard (sidebar ordering only, in localStorage) | Built for the dashboard on Pointer Events |
| Tests | No dashboard tests | Added unit and integration suites |

### Security: the dashboard showed drafts and pending comments to everyone (issues.md BH-001)

`/admin/dashboard` is open to every signed-in role. It listed the five newest
posts of **any** status and author (other people's drafts, pending and private
titles) and the five newest comments of **any** status (pending and spam, with
names and text), plus site-wide user, media and comment counts — to
subscribers and contributors too. Each panel is now a widget with its own
capability, and the data is scoped inside the provider:

| Card | Capability | Scope |
|---|---|---|
| At a glance | any of `edit_posts`, `edit_pages`, `upload_media`, `moderate_comments`, `manage_users` | each count only with its own capability; posts/pages are "yours" without `edit_others_*` |
| Recent posts | `edit_posts` | own posts only without `edit_others_posts` |
| Recent comments | `moderate_comments` | approved + pending; never spam or trash |
| Publishing activity | `edit_posts` | own posts only without `edit_others_posts` |
| Site health | `manage_settings` | — |
| Your recent activity | `read` | the user's own activity log |

A subscriber now sees only their own activity.

## What changed

1. **Widget registration contract.** `WidgetRegistry::register()` returns
   true/false; refusals carry a reason (`rejected()`) and are logged to the
   owner's app log and the core log. Keys have an owner: `core.*` is core's, a
   key belongs to whoever registered it first, the same owner may replace its
   own (logged as a notice). New definition keys: `capability`, and under
   `dashboard`: `view`, `data`, `endpoint`, `sizes`, `size`, `default`,
   `multiple`, `refresh`, `cache`, `collapsible`, `removable`, `refreshable`,
   `actions`, `empty`, `configure`, `visible`, `aliases`. Old definitions are
   accepted unchanged (`width` is read as `size`; field types and lazy
   `options` callables are kept as written).
2. **`App::registerWidget()`** forces the owner to the app, so an app can't
   register in core's or another app's name. Dashboard cards use it with
   `'surfaces' => ['dashboard']`. (No new method is added to the `App` base
   class — see the note on 1.2.68's first build below.)
3. **Dashboard engine** — `App\Services\Dashboard\DashboardService`:
   discovery and permission checks, layout resolution (user → site default →
   built-in default), reconciliation with what's registered now (dormant
   entries for missing or forbidden widgets are kept, not rendered; aliases for
   renamed keys), settings validation against the field schema, isolated data
   loading with per-user/role/global caching, card actions with their own
   capability, and payload normalisation (bounded sizes, safe links, sanitized
   HTML).
4. **Persistence** — `users.meta` → `dashboard` per user (no new table, no
   migration), and `settings` → `dashboard.default_layout` for an
   administrator's site default. Behind a `LayoutStore` interface so the rules
   are unit-tested without a database.
5. **Seven AJAX actions** (`widgets.dashboard_state`, `_data`, `_save`,
   `_update`, `_reset`, `_action`, `_default`) on the existing gateway.
6. **The dashboard page** paints immediately with the layout and loads every
   card's data in one batched request; native views for stat, list, table,
   chart, feed, status and HTML; loading, empty, unavailable, error (with
   Retry) and gone states; Refresh, Collapse, ⋮ menu (app actions, Settings,
   Remove) — each only when the widget supports it.
7. **Customize mode** — a staged copy of the layout with Save / Cancel; drag
   by the handle (mouse, pen and touch via Pointer Events) with column guides
   on the 12-column grid; ↑/↓ buttons and arrow keys on the handle; size
   picker per card; Add widgets panel grouped by Core and App with search,
   New badges and Restore for removed cards; Reset to default; Use as site
   default (`manage_settings`). On phones the bar docks to the bottom of the
   screen and cards move with the arrows.
8. **`widgets.render`** now also requires the widget's own capability.
9. **`WidgetRegistry::render()`** logs a failing widget's exception instead of
   printing its message into the page.
10. Core's frontend widgets keep their keys; the dashboard cards are under
    `core.dashboard.*`.

## Decisions and assumptions

- **Storage in `users.meta`, not a new table.** It is core's established
  per-user store (Data Table preferences), it is already per installation (via
  `DB_PREFIX`) and per user id, and it needs no migration — so nothing to
  migrate, nothing destructive, and a patch release is enough. The layout is
  small (≤ 40 cards, ≤ 4 KB settings each; `setMeta` caps the column at 64 KB).
  If layouts ever need querying across users, a table can replace
  `UserMetaLayoutStore` without touching the engine.
- **No legacy preferences existed** to migrate: 1.2.67 stored nothing about the
  dashboard. Users without a layout get the defaults.
- **Settings change only through `dashboard_update`**, never through a layout
  save, so a stale or forged layout can't overwrite them.
- **Removed cards are hidden, not deleted**, so Restore brings back their
  settings.
- **Widgets that declare no capability stay visible to every signed-in user**
  (`read`), matching 1.2.67, so existing app widgets don't vanish. Flagged in
  issues.md (BH-001) for a decision on defaulting app widgets to
  `access_app:{slug}`.
- **Legacy HTML widgets render server-side on page load** (into hidden holders
  moved into their cards), so inline scripts keep running exactly as on the
  old dashboard; on Refresh they reload through the data action.
- **Endpoint widgets may not use the `html` view**, since their output never
  passes the server's sanitizer.
- Charts are drawn with HTML/CSS (bars) and inline SVG (lines) — no library,
  no build step.

## Files

New:
- `app/Services/Dashboard/DashboardService.php` — the engine.
- `app/Services/Dashboard/LayoutStore.php`, `UserMetaLayoutStore.php` — storage.
- `app/Services/Dashboard/WidgetContext.php` — what providers/actions receive.
- `app/Services/Dashboard/WidgetUnavailable.php`, `InvalidSettings.php` — exceptions.
- `app/Services/Dashboard/CoreDashboardWidgets.php` — core's six cards.
- `app/Http/Controllers/Ajax/DashboardActions.php` — the AJAX handlers.
- `admin/assets/js/dashboard.js`, `admin/assets/css/dashboard.css` — the UI.
- `docs/DASHBOARD-WIDGETS.md` — developer guide.
- `docs/examples/dashboard-widgets/` — example app (stat, table + settings,
  status + action, endpoint, legacy HTML).
- `tests/Unit/DashboardWidgetsTest.php`, `tests/Integration/DashboardTest.php`.

Changed:
- `app/Core/WidgetRegistry.php` — the contract above.
- `app/Core/App.php` — `registerWidget()` forces the owner.
- `app/Core/Application.php` — `DashboardService` binding; core cards registered.
- `app/Http/Controllers/Admin/DashboardController.php`, `admin/views/dashboard/index.php` — the new page.
- `app/Http/Controllers/Ajax/AdminActions.php` — `widgets.render` checks the widget capability.
- `routes/ajax.php` — the dashboard actions.
- `tests/fixtures/apps/ajax-fixture/src/App.php` — fixture dashboard widgets.
- `tests/Integration/EditorActionsTest.php` — media.delete assertion updated for 1.2.67's `results` list (issues.md BH-003).
- `README.md`, `docs/APP-DEVELOPMENT.md` — registration examples.
- `index.php` — version 1.2.68.

## Tests

`php tests/run.php` — 270 passed. New: 18 unit tests (registry contract,
ownership and collisions, malformed definitions, legacy compatibility, layout
resolution, per-user layouts, add/hide/restore/move/resize, forbidden widgets,
new widgets not overwriting layouts, disabled apps, aliases, settings
validation, reset and site default, failing providers, payload sanitizing,
cache scope, action capabilities) and 12 integration tests over HTTP (core +
app render, failing widget isolation, persistence across sign-in and devices,
two users, add/hide/restore/move/resize, widget without optional tools,
settings round trip, protected data for unauthorized users, the BH-001 leak,
removed app, malformed requests, existing dashboard compatibility).

Run on Dolt 2.5 (MySQL-compatible). One existing test fails on Dolt only
(`editor: a double click…`, a FULLTEXT limitation in Dolt — issues.md BH-009);
it is unrelated to this release. Not run on MariaDB in this environment.

Checked in a browser (Chromium) at 1366 px and 390 px as admin, editor,
author, contributor and subscriber: drag and drop, arrow moves, sizes, Add
widgets, Restore, settings validation, Collapse persisting to a second
session, an app action with confirmation, Cancel with unsaved changes, and the
mobile bottom bar.

## Remaining limitations

- Keyboard reordering moves a card one place at a time; there's no "move to
  position N".
- Drag and drop reorders; it doesn't place a card in a specific empty column —
  the grid flows left to right.
- `users.meta` writes are read-modify-write (issues.md BH-008): two saves at
  the same instant from two tabs can lose one.
- Endpoint widgets' data is not cached by the engine (the app's action decides).
- The site default applies to people who haven't customised; there is no
  per-role default yet.
