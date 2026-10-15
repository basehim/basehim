# Data tables

Companion to **APP-DEVELOPMENT.md**, **APP-AJAX.md** and **APP-PERMISSIONS.md**.

Basehim's admin list screens (Posts, Pages, …) share one **Data Table**
system: a core service renders a consistent table — search, filters, sortable
columns, server-side pagination, row selection and bulk actions, column
show/hide and reorder, density, and responsive desktop/mobile layouts — and an
app can add a column to an existing table without replacing it.

It is built on the pieces that already exist: one query per feature
(`paginate()`), the AJAX gateway (`POST /ajax`), capability and area checks,
and the `users.meta` column for per-user preferences. There is no second
router and no new storage.

```php
// In your app's boot(): add an "SEO Score" column to the Posts table.
$this->addFilter('datatable.columns.content.posts', function (array $cols): array {
    $cols[] = [
        'id'      => 'my-seo.score',       // namespaced by your slug
        'label'   => 'SEO Score',
        'prepare' => [$this, 'scores'],    // one bulk query for the whole page
        'render'  => [self::class, 'badge'],
        'capability'      => 'edit_posts', // hidden from users without it
        'default_visible' => false,        // opt-in
    ];
    return $cols;
});
```

## The parts

| | |
|---|---|
| **Table** | A registered screen, keyed `namespace.name` (`content.posts`). Core registers tables in `routes/tables.php`. |
| **Column** | One column: how its cell value is produced, and whether it can be sorted, filtered, hidden or reordered. |
| **Provider** | A thin adapter that maps the generic request onto a feature's existing `paginate()`. Core owns the SQL; the table service never does. |
| **Preferences** | Per-user column visibility, order, page size and density, in `users.meta`. |

The core tables in 1.2.54: `content.posts`, `content.pages`, `comments.list`.

## Adding a column to a core table

A theme cannot do this (themes have no PHP lifecycle to register one — see
APP-AJAX.md). From an **app**, in `boot()`, add a filter named
`datatable.columns.` + the table key. It receives the current list of column
arrays and returns it with yours appended.

```php
public function boot(): void
{
    $this->addFilter('datatable.columns.content.posts', [$this, 'addColumn']);
}

public function addColumn(array $cols): array
{
    $cols[] = [
        'id'              => 'my-app.reading_time',  // must be namespaced by your slug
        'label'           => 'Reading time',
        'render'          => [self::class, 'render'],
        'sortable'        => false,
        'default_visible' => false,
        'can_hide'        => true,
        'capability'      => 'edit_posts',
        'responsive'      => 'desktop-only',
        'align'           => 'center',
    ];
    return $cols;
}
```

### A column definition

| Key | | |
|---|---|---|
| `id` | required | Unique within the table, namespaced by your slug (`my-app.x`). A duplicate is dropped and logged. |
| `label` | required | Column heading. |
| `value` | one of value/render | A row key. Core reads `$row[value]` and **HTML-escapes** it. Use for plain fields. |
| `render` | one of value/render | `callable(array $row, mixed $prepared): string` returning HTML. A **string** ("Class::method") is refused — pass a real callable. App render output is run through `HtmlSanitizer` (scripts, forms, iframes removed); always escape the data you interpolate. |
| `prepare` | optional | `callable(array $rows): mixed`, run **once for the whole page** before any cell is rendered. Do one bulk query here and return a lookup; core hands it to every `render()` as `$prepared`. See *Performance*. |
| `sortable` | default `false` | A list of sort tokens this column enables, e.g. `['title_az','title_za']`. The tokens must also be ones the provider accepts. `false` = not sortable. |
| `filter` | optional | `['field' => 'status', 'in' => ['draft','published']]`. The field must be one the provider accepts; a request value outside `in` is ignored. |
| `default_visible` | default `true` | Whether it shows before the user chooses. |
| `can_hide` | default `true` | `false` keeps it always visible (e.g. the title). |
| `capability` | optional | A user without it never receives the column — it is removed from the response, not hidden in CSS. |
| `responsive` | default `always` | `always`, `tablet-up` or `desktop-only`. A render hint; **separate** from user visibility, so a narrow screen never overwrites desktop column choices. |
| `align` | default `left` | `left`, `center` or `right`. |

A capability a column needs to exist must be **declared**: a column is not
sortable or filterable unless it says so.

## Performance: the two-phase contract

A page of 100 rows and 12 columns is up to 1,200 cells. To keep that at
**O(columns)**, never O(rows × columns):

- **`prepare(array $rows)`** runs once per column for the whole page. Do a
  single `WHERE id IN (…)` query and return a lookup keyed by row id.
- **`render(array $row, $prepared)`** must be **pure**: read the row and
  `$prepared` only. No database, no HTTP, no filesystem.

```php
public function scores(array $rows): array           // ONE query for the page
{
    $ids = array_map(fn($r) => (int) $r['id'], $rows);
    $in = implode(',', array_map('intval', $ids));    // ints only — safe to inline
    $map = [];
    foreach ($this->db()->select("SELECT post_id, meta_value FROM {post_meta}
        WHERE meta_key = 'seo_score' AND post_id IN ($in)") as $r) {
        $map[(int) $r['post_id']] = (int) $r['meta_value'];
    }
    return $map;
}

public static function badge(array $row, $prepared): string   // pure
{
    $score = (int) ($prepared[(int) $row['id']] ?? 0);
    return '<span class="bh-dt-badge">' . htmlspecialchars((string) $score, ENT_QUOTES) . '</span>';
}
```

Core also caps `per_page` and wraps every `prepare()`/`render()` in a guard: a
column that throws costs that column, never the page. Columns whose data is
already joined by the provider's query (author, featured image) need no
`prepare`.

## The AJAX lifecycle

Reads go through **one** action, `content.table` (GET, so no CSRF; it must stay
side-effect free). The action is registered with capability `read`; the real
gate is the **table's own capability and area**, enforced in the handler
through `AdminAreaPolicy::requiredFor()` — so a site's `admin.area_policy`
overrides apply and the action is never more open than the screen.

```
Basehim.ajax.get('content.table', { table, page, per_page, sort, search,
    filters: JSON, columns: JSON })
  → gateway (auth, rate limit, input schema)
  → TableActions::query  (table capability + area)
  → TableRegistry::columnsFor  (core + app columns, capability-gated)
  → provider->query(TableQuery)  → PostService::paginate()  (bound params, sort whitelist)
  → prepare() per column, then pure render() per cell, escaped/sanitized
  → { rows, meta, columns, catalog, prefs }
```

The request is validated before a provider sees it: `sort` must be one the
provider declared, every filter key must be one it declared (values checked
against any `in` set), and `per_page` must be an allowed page size. Anything
else is dropped or `422`.

**Writes** (bulk publish/delete, row edit) are **not** a new generic endpoint.
They reuse each feature's existing CSRF-protected routes (e.g.
`/admin/posts/bulk`), so the ownership and publish rules that already live
there are never bypassed. The client collects the selected ids and submits that
form.

## Preferences

Per-user column visibility, order, page size and density are stored in the
existing `users.meta` JSON column under `datatable`, scoped by table key, and
saved with `content.table_prefs` (reset with `content.table_prefs_reset`).

Every read is reconciled with the **current** table definition and the columns
the user may see: unknown ids are dropped, a hidden non-hideable column is
forced back, visibility is intersected with permitted columns, the page size is
clamped to the allowed set, and a sort no column declares is rejected. A
preference saved before a column was removed or a permission changed can never
widen access or break the table.

## Registering a table (core)

For reference. Apps extend tables; they do not usually register their own, but
the same call works for an app's own admin list.

```php
$tables->register('content.posts', [
    'label'        => 'Posts',
    'capability'   => 'edit_posts',
    'area'         => '/admin/posts',
    'provider'     => \App\Services\Table\PostTableProvider::class,
    'default_sort' => 'newest',
    'page_sizes'   => [8, 20, 50, 100],
    'columns'      => [ /* column arrays, as above */ ],
    'row_actions'  => ['edit', 'trash'],
    'bulk_actions' => ['publish', 'draft', 'delete'],
]);
```

A provider implements three methods and owns no SQL of its own:

```php
final class PostTableProvider implements \App\Core\Table\TableProvider
{
    public function __construct(private \App\Services\PostService $posts) {}
    public function sortKeys(): array   { return ['newest','oldest','title_az','title_za']; }
    public function filterKeys(): array { return ['status','author_id','trashed']; }
    public function query(\App\Core\Table\TableQuery $q): array {
        return $this->posts->paginate([ /* map $q onto the existing filters */ ], $q->page, $q->perPage);
    }
}
```

`query()` returns the house shape unchanged:
`['data' => rows, 'meta' => ['page','per_page','total','last_page']]`.

## Security notes

- A hidden column is **not** a boundary: capability-gated columns are removed
  from the JSON, and per-row ownership is still the handler's job (the table
  lists what the screen lists; acting on a row re-checks ownership on submit).
- Sort and filter inputs are **whitelists**, so no request string reaches an
  `ORDER BY` or a column it was not declared for.
- App column HTML is untrusted: sanitized, and you must still escape values.
- `per_page` has a hard ceiling; `prepare()` is bounded to one page of rows.

## The front end

`admin/assets/css/datatable.css` and `admin/assets/js/datatable.js` render the
table with the admin's existing `bh-*` components, through `Basehim.ajax`. A
screen includes the shared partial:

```php
<?php $this->include('partials.datatable', [
    'tableKey'     => 'content.posts',
    'bulkEndpoint' => $base . '/admin/posts/bulk',
    'editBase'     => $base . '/admin/posts/{id}/edit',
]); ?>
```

The partial server-renders the first page (so the table works with no
JavaScript and paints instantly) and embeds the config the script enhances.
Posts and Pages use it by default; `?view=classic` falls back to the original
server-rendered table.

The CSS and JS are versioned by their file modification time, so a changed
asset is picked up by browsers immediately — no release bump or hard refresh.

## Reusable cell components

`App\Core\Admin\Components` renders the things that recur across admin
screens, so a column or any other view shows them the same way:

```php
use App\Core\Admin\Components;

Components::avatar($name, $url, 28);                 // image, or initials on a colour
Components::userCell($name, $url, $sub, 28);         // avatar + name (+ optional sub-line)
Components::thumb($featuredUrl, $alt, 40);           // media thumbnail, or a placeholder
Components::statusPill($status, $tones);             // a coloured status pill
```

They are also available as the globals `bh_avatar_html()`, `bh_user_cell()`
and `bh_thumb()` for use directly in a view. The Posts table's thumbnail and
author columns are built with them:

```php
['id' => 'thumb',  'label' => 'Image', 'default_visible' => true, 'can_hide' => true,
 'render' => fn (array $r) => Components::thumb($r['featured_url'] ?? null, (string) ($r['featured_alt'] ?? ''), 40)],
['id' => 'author', 'label' => 'Author',
 'render' => fn (array $r) => Components::userCell((string) ($r['author_name'] ?? '—'), $r['author_avatar_url'] ?? null)],
```
