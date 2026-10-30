# Basehim 1.2.93

2026-10-30 · Patch release · installs on 1.2.92

A frontend widget framework for core, apps and themes, the core widgets rebuilt
on it, and a new Widget areas screen. No database changes: placements stay in
the `widgets` / `areas` setting, and records saved before 1.2.93 are read as
they are. The admin dashboard's widget framework is not changed.

## Why

Frontend widgets had registration and areas but little else:

1. **No validation.** `WidgetAreaService::updateWidget()` kept any scalar the
   form sent. A "count" of `abc` or `500`, or an option that doesn't exist, was
   saved, and each widget's render code had to defend itself.
2. **Themes couldn't restyle core widgets.** Their markup was built in PHP
   strings in `Application.php`; the only way to change it was CSS.
3. **No caching.** Categories and Tags ran a grouped count query on every page.
4. **No metadata.** No category, version, migrations, area rules, assets or
   app dependencies. Assets were inline `<style>` blocks guarded by `static`
   variables.
5. **Saves could lose changes.** Every change read the whole placements row,
   changed it in PHP and wrote it back; two admins saving at once lost one edit.
6. **The public API leaked settings.** `GET /api/v1/widget-areas/{area}`
   returned every saved setting, including raw HTML and anything an app stored.
7. **The admin was form posts:** no drag and drop, preview, duplicate, hide,
   or move between areas; the reorder endpoint was never used; widgets of a
   previous theme's areas were invisible.

## The framework

`app/Core/Widgets/` ([docs/FRONTEND-WIDGETS.md](../FRONTEND-WIDGETS.md)):

- **`Schema`**: the settings schema. Types text, textarea, html, number,
  checkbox/toggle, select, multiselect, radio, url, color, date, terms, posts,
  media; `default`, `required`, `min`/`max`/`step`/`maxlength`, `options`
  (array or callable), `show_if`, `capability`, `private`. `validate()` for
  saving (errors per field); `normalise()` for rendering (never fails: invalid
  values fall back to defaults). Checkbox values stay `'1'`/`'0'` strings, as
  widgets always read them.
- **`Definition`**: the optional `frontend` block of a widget definition:
  `category`, `version`, `migrate`, `data`, `template`, `title`, `cache`,
  `areas`, `assets`, `requires`, `aliases`, `preview`. Templates must be inside
  the owner's own folder. Checked at registration; a bad definition is
  refused with the reason, like any other.
- **`Placements`**: instances in the existing setting. Every change runs under
  the row lock of `SettingService::update()`. New optional record keys `v`,
  `class`, `hide_title`, `disabled`; old records read with defaults.
- **`FrontendWidgets`**: rendering and changes. Availability (frontend surface,
  `requires`), migration, normalisation, signed-out output cache, data
  provider, template lookup (theme `templates/widgets/{area}/{key}.php`, then
  `templates/widgets/{key}.php`, short names for core widgets, then the
  widget's own), title and wrapper (`%3$s` = widget key is new), assets once
  per page, failure isolation with output buffers restored. Changes: `add`,
  `save`, `setOptions`, `remove`, `move` (any area, any position), `order`,
  `duplicate`, with area rules checked. Filter `widget.output`, action
  `widgets.changed`.
- **`CoreWidgets`** + `templates/`: core's widgets as data providers and
  templates.

`WidgetRegistry::register()` normalises the `frontend` block (frontend surface
only; dashboard and editor handling is unchanged) and accepts a template
instead of `render` for frontend-only widgets. `WidgetAreaRegistry` areas take
`supports`, `widgets` and `max_widgets`. `WidgetAreaService` keeps its methods
and return values and delegates to `FrontendWidgets`. `widget_area()` takes
`$opts` (`class`, `wrapper`); `has_widget_area()` counts only instances that
will show.

Cache clearing: `post.created/updated/published/deleted/restored`,
`term.*`, `comment.created/status_changed/deleted`, `media.updated/deleted/
file_replaced`, `user.updated/deleted`, and every widget change, by
`Cache::flushTag('widgets')`.

## Core widgets

Same keys, setting names, stored values and markup. New settings: Search
button text; Recent Posts tag, author and excerpt length; Categories and Tags
cloud display with a size range, direction, and terms to leave out; Categories
sub-categories under their parent and a depth. Recent Posts dates use the
site's timezone (`Time::date`), not UTC. The HTML widget's content needs
`unfiltered_html` to change. Recent Posts caches for 5 minutes (varying by
page when it leaves out the post being read), Categories and Tags for 10.
The term dropdown's element id now comes from the instance id, so cached
copies can't repeat it. `PostRepository::widgetList()` accepts `tag`,
`author` and `exclude`.

## Admin

`admin/views/widgets/areas.php` + `admin/assets/js/widget-areas.js`, on new
AJAX actions in `app/Http/Controllers/Ajax/WidgetActions.php`
(`widgets.state/add/save/options/remove/move/order/duplicate/preview`,
`manage_apps`, area `/admin/widgets`). Settings travel as a JSON string,
validated by the widget's schema; errors come back as 422 with
`settings.{field}` messages. Drag and drop by a handle, Arrow Up/Down on the
focused handle, "Area" select to move between areas, screen-reader
announcements. Inactive areas are listed. The preview is a sandboxed iframe
with scripts off. The pre-1.2.93 form routes still work; `areaUpdate` now
validates and reports errors.

## API

- `GET /api/v1/widget-areas/{area}`: hidden and unavailable instances left
  out; `private` and `html` settings left out; HTML rendered by the framework.
- MCP `get_widget_area`: every instance, with `enabled`, `unavailable` and
  normalised settings.

## Files

New: `app/Core/Widgets/{Schema,Definition,Placements,FrontendWidgets,WidgetError,CoreWidgets}.php`,
`app/Core/Widgets/templates/{html,search,recent-posts,terms}.php`,
`app/Http/Controllers/Ajax/WidgetActions.php`, `admin/assets/js/widget-areas.js`,
`docs/FRONTEND-WIDGETS.md`, `tests/Unit/FrontendWidgetsTest.php`,
`tests/Integration/FrontendWidgetsTest.php`.

Changed: `app/Core/Application.php`, `app/Core/WidgetRegistry.php`,
`app/Core/WidgetAreaRegistry.php`, `app/Services/WidgetAreaService.php`,
`app/Repositories/PostRepository.php`, `app/Http/Controllers/Admin/WidgetController.php`,
`app/Http/Controllers/Api/WidgetController.php`, `app/Http/Controllers/Api/McpController.php`,
`admin/views/widgets/areas.php`, `routes/ajax.php`, `bootstrap.php`, `index.php`,
`CHANGELOG.md`, `docs/THEME-DEVELOPMENT.md`, `docs/APP-DEVELOPMENT.md`.

## Tests

13 unit tests (schema, definitions, placements, changes and area rules,
rendering, failure isolation, legacy render widgets, `requires`, migrations,
theme templates, cache separation, assets, public settings, dashboard
unaffected) and 8 integration tests (permissions and CSRF, every AJAX action,
pre-1.2.93 records, theme override, cache never served to signed-in users and
cleared on change, an app's widget through deactivation and the public API,
the old form routes, the dashboard).

## Compatibility

- Saved placements, settings and widget keys are unchanged; reading never
  rewrites them. Saving a widget adds the optional keys to its record.
- Widgets registered with `render` work as before.
- A theme that styled core widgets' classes is unaffected: the markup is the
  same. Inline asset `<style>`/`<script>` tags now carry `data-bh-widget`
  instead of the old ids `bh-recent-posts-css`, `bh-term-widget-css`,
  `bh-term-widget-js`.
- Code that read `WidgetAreaService::assignments()` gets normalised records
  (the same keys, plus the optional ones).
