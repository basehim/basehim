<?php

/**
 * The AJAX gateway and core's AJAX actions. See docs/APP-AJAX.md.
 *
 * One route, any method: the gateway answers unsupported methods with a 405
 * of its own, rather than letting the request fall through to the page
 * routes and come back as an HTML 404.
 *
 * Core actions are registered here, under core's reserved namespaces. Apps
 * register theirs from boot() with $this->ajax(), under their own slug.
 * Registration order does not matter: names are partitioned by namespace,
 * so nothing an app registers can collide with these.
 */

use App\Core\Ajax\AjaxGateway;
use App\Core\Ajax\AjaxRegistry;
use App\Http\Controllers\Ajax\MediaActions;
use App\Http\Controllers\Ajax\SessionActions;

$router = $app->make('router');
// Every method, OPTIONS and HEAD included: any() leaves those two out, and a
// preflight or HEAD then fell through to the page routes and got the HTML 404.
$router->add(['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD'], \App\Core\Request::AJAX_PATH, [AjaxGateway::class, 'handle']);

/** @var AjaxRegistry $ajax */
$ajax = $app->make(AjaxRegistry::class);

$ajax->register('core.csrf', [
    'handler'     => [SessionActions::class, 'csrf'],
    'methods'     => ['GET'],
    'auth'        => 'public',
    'rate_limit'  => ['max' => 30, 'per' => 60, 'by' => 'ip'],
    'description' => "The session's current CSRF token, for a page whose token went stale.",
]);

$ajax->register('media.update', [
    'handler'     => [MediaActions::class, 'update'],
    'capability'  => 'upload_media',
    'input'       => [
        'id'          => 'required|int|min:1',
        'title'       => 'string|max:255',
        'alt_text'    => 'string|max:255',
        // Characters; at up to 4 bytes each this always fits the TEXT column.
        'caption'     => 'string|max:16000',
        'description' => 'string|max:16000',
    ],
    'description' => "Edit a media item's title, alt text, caption or description.",
]);

// ---------------------------------------------------------------------------
// 1.2.47 — the post editor, and the admin screens' former JSON routes. Each
// action names the admin area it stands in for ('area'), so it is never more
// open than that screen, including rules a site changed with the
// admin.area_policy filter. The old routes remain for older callers.
// ---------------------------------------------------------------------------

use App\Http\Controllers\Ajax\AdminActions;
use App\Http\Controllers\Ajax\PostActions;
use App\Http\Controllers\Ajax\TermActions;

// The editor's Save draft. 'read' here; the handler applies the type's own
// rules (its admin area, own or others' posts) through PostEditorService.
$ajax->register('posts.save_draft', [
    'handler'     => [PostActions::class, 'saveDraft'],
    'capability'  => 'read',
    'idempotent'  => true,                 // a double click must not create two posts
    'max_body'    => 1048576,              // long posts; larger ones fall back to the form
    'rate_limit'  => ['max' => 60, 'per' => 60],
    'input'       => [
        'id'                => 'int|min:1',
        'type'              => ['type' => 'string', 'required' => true, 'regex' => '/^[a-z0-9_-]{1,40}$/'],
        'title'             => 'string|max:1000',
        'slug'              => 'string|max:200',
        'content'           => 'string',
        'content_format'    => 'string|in:blocks,html,markdown',
        'excerpt'           => 'string|max:20000',
        'status'            => 'string|in:draft,pending',
        'comment_status'    => 'string|in:open,closed',
        'featured_media_id' => 'int|nullable|min:0',
        'term_ids'          => ['type' => 'array', 'max' => 500, 'items' => 'int|min:1'],
        'new_tags'          => ['type' => 'array', 'max' => 50, 'items' => 'string|max:100'],
        'seo'               => ['type' => 'object', 'fields' => [
            'meta_title'       => 'string|max:200',
            'meta_description' => 'string|max:300',
            'focus_keyword'    => 'string|max:200',
            'robots'           => ['type' => 'string', 'in' => ['index,follow', 'noindex,follow', 'index,nofollow', 'noindex,nofollow']],
            'canonical_url'    => 'string|max:2000',
        ]],
    ],
    'description' => 'Save the post being edited as a draft (or pending review), creating it on the first save.',
]);

$ajax->register('posts.render', [
    'handler'     => [PostActions::class, 'render'],
    'capability'  => 'edit_posts',
    'area'        => '/admin/posts',
    'max_body'    => 1048576,
    'input'       => ['content' => 'string', 'from' => 'string|in:blocks,markdown', 'for' => 'string|in:blocks'],
    'description' => 'Render block JSON or Markdown to HTML (switching the editor to source view and back).',
]);

$ajax->register('posts.templates', [
    'handler'     => [PostActions::class, 'templates'],
    'methods'     => ['GET'],
    'capability'  => 'edit_posts',
    'area'        => '/admin/posts',
    'description' => 'Block templates for the inserter.',
]);

$ajax->register('posts.tag_add', [
    'handler'     => [PostActions::class, 'tagAdd'],
    'capability'  => 'read',
    'input'       => ['post_id' => 'required|int|min:1', 'term_id' => 'int|min:1', 'name' => 'string|max:100'],
    'description' => 'Add an existing tag to a post, or keep a new name to be created on publish.',
]);

$ajax->register('posts.tag_remove', [
    'handler'     => [PostActions::class, 'tagRemove'],
    'capability'  => 'read',
    'input'       => ['post_id' => 'required|int|min:1', 'term_id' => 'int|min:1', 'name' => 'string|max:100'],
    'description' => 'Take a tag (or a pending new tag) off a post.',
]);

$ajax->register('terms.tag_suggest', [
    'handler'     => [TermActions::class, 'tagSuggest'],
    'methods'     => ['GET'],
    'capability'  => 'edit_posts',
    'rate_limit'  => ['max' => 240, 'per' => 60],   // one per keystroke pause
    'input'       => [
        'q'       => 'required|string|max:100',
        'limit'   => 'int|min:1|max:20',
        'exclude' => ['type' => 'string', 'max' => 2000, 'regex' => '/^[0-9,]*$/'],
    ],
    'description' => 'Tags matching what is being typed in the tag box.',
]);

$ajax->register('terms.tag_overview', [
    'handler'     => [TermActions::class, 'tagOverview'],
    'methods'     => ['GET'],
    'capability'  => 'edit_posts',
    'input'       => ['limit' => 'int|min:1|max:30'],
    'description' => 'Most used and recently used tags for the tag box.',
]);

// Media: the library, the picker and the avatar editor.
$ajax->register('media.list', [
    'handler'     => [MediaActions::class, 'list'],
    'methods'     => ['GET'],
    'capability'  => 'upload_media',
    'area'        => '/admin/media',
    'rate_limit'  => ['max' => 240, 'per' => 60],
    'input'       => [
        'page'     => 'int|min:1|max:100000',
        'per_page' => 'int|min:1|max:100',
        'q'        => 'string|max:200',
        // Checked against the type groups in MediaActions::filtersFrom().
        'type'     => 'string|max:20',
        'types'    => 'string|max:100',
        'month'    => ['type' => 'string', 'max' => 7, 'regex' => '/^(all|\d{4}-\d{2})$/'],
        'months'   => 'bool',
        'sort'     => 'string|in:newest,oldest,name,name_desc,largest,smallest',
    ],
    'description' => 'One page of the media library; meta.has_more says whether there is another.',
]);

// 1.2.87 — uploads through the gateway. The old POST /admin/media/upload
// stays for older callers; media.js uses this when the gateway takes files.
$ajax->register('media.upload', [
    'handler'     => [MediaActions::class, 'upload'],
    'capability'  => 'upload_media',
    'area'        => '/admin/media',
    // Dropping a folder of photos: media.js sends three at a time.
    'rate_limit'  => ['max' => 300, 'per' => 60],
    'files'       => ['file' => ['types' => 'media', 'max_size' => 'media']],
    'input'       => [
        // Longer titles are cut to 255 characters, as the old route stored them.
        'title'     => 'string|max:2000',
        'alt_text'  => 'string|max:255',
        'caption'   => 'string|max:16000',
        'name_hint' => 'string|max:200',
        // Where the upload starts (library, editor, picker, …): a hint for
        // the media.upload.* filters. Contexts with a colon (core:import,
        // core:api, app:slug) are set by the server and cannot be sent.
        'context'   => ['type' => 'string', 'max' => 40, 'regex' => '/^[a-z][a-z0-9_-]{0,39}$/'],
    ],
    'description' => 'Upload one file to the media library (multipart/form-data, field "file").',
]);

$ajax->register('media.get', [
    'handler'     => [MediaActions::class, 'get'],
    'methods'     => ['GET'],
    'capability'  => 'upload_media',
    'area'        => '/admin/media',
    'rate_limit'  => ['max' => 240, 'per' => 60],
    'input'       => ['id' => 'required|int|min:1'],
    'description' => 'One media item, with whether you may edit or delete it.',
]);

$ajax->register('media.delete', [
    'handler'     => [MediaActions::class, 'delete'],
    'capability'  => 'upload_media',
    'area'        => '/admin/media',
    'rate_limit'  => ['max' => 60, 'per' => 60],
    'input'       => ['ids' => ['type' => 'array', 'required' => true, 'min' => 1, 'max' => 500, 'items' => 'int|min:1']],
    'description' => "Delete files, with a result for each; someone else's need delete_media.",
]);

$ajax->register('media.regenerate_batch', [
    'handler'     => [MediaActions::class, 'regenerateBatch'],
    'capability'  => 'manage_settings',
    'area'        => '/admin/settings/media',
    'rate_limit'  => ['max' => 600, 'per' => 60],
    'input'       => ['after' => 'int|min:0', 'scope' => 'string|in:missing,all'],
    'description' => 'One batch of Settings → Media → Regenerate thumbnails.',
]);

$ajax->register('widgets.render', [
    'handler'     => [AdminActions::class, 'widgetRender'],
    'capability'  => 'edit_posts',
    'input'       => [
        'widget'   => 'required|string|max:120',
        'surface'  => 'string|in:editor,frontend,dashboard',
        'settings' => 'string|max:60000',
    ],
    'description' => 'Preview a widget block in the editor.',
]);

$ajax->register('comments.pending_count', [
    'handler'     => [AdminActions::class, 'pendingComments'],
    'methods'     => ['GET'],
    'capability'  => 'moderate_comments',
    'area'        => '/admin/comments',
    'description' => 'Comments awaiting moderation (the sidebar badge).',
]);

$ajax->register('customizer.save', [
    'handler'     => [AdminActions::class, 'customizerSave'],
    'capability'  => 'manage_themes',
    'area'        => '/admin/customize',
    'input'       => ['values' => 'required|string|max:200000'],
    'max_body'    => 262144,
    'description' => "Save the Customizer's changed values (a JSON object of path => value).",
]);

$ajax->register('customizer.draft', [
    'handler'     => [AdminActions::class, 'customizerDraft'],
    'capability'  => 'manage_themes',
    'area'        => '/admin/customize',
    'input'       => ['values' => 'required|string|max:200000'],
    'max_body'    => 262144,
    'rate_limit'  => ['max' => 240, 'per' => 60],
    'description' => 'Values for the Customizer preview, kept in the session.',
]);

$menuItem = ['type' => 'object', 'fields' => [
    'title'     => 'required|string|max:200',
    'url'       => 'required|string|max:500',
    'type'      => 'string|in:post,page,taxonomy,custom,archive',
    'object_id' => 'int|nullable|min:0',
    'target'    => 'string|in:_self,_blank',
]];
$ajax->register('menus.items_add', [
    'handler'     => [AdminActions::class, 'menuItemsAdd'],
    'capability'  => 'manage_menus',
    'area'        => '/admin/menus',
    'input'       => ['menu_id' => 'required|int|min:1', 'items' => ['type' => 'array', 'required' => true, 'min' => 1, 'max' => 200, 'items' => $menuItem]],
    'description' => 'Add items to a menu.',
]);
$ajax->register('menus.item_update', [
    'handler'     => [AdminActions::class, 'menuItemUpdate'],
    'capability'  => 'manage_menus',
    'area'        => '/admin/menus',
    'input'       => [
        'menu_id' => 'required|int|min:1', 'item_id' => 'required|int|min:1',
        'title'   => 'required|string|max:200', 'url' => 'required|string|max:500',
        'target'  => 'string|in:_self,_blank', 'classes' => 'string|max:200',
    ],
    'description' => 'Change one menu item.',
]);
$ajax->register('menus.item_remove', [
    'handler'     => [AdminActions::class, 'menuItemRemove'],
    'capability'  => 'manage_menus',
    'area'        => '/admin/menus',
    'input'       => ['menu_id' => 'required|int|min:1', 'item_id' => 'required|int|min:1'],
    'description' => 'Remove a menu item and its sub-items.',
]);
$ajax->register('menus.reorder', [
    'handler'     => [AdminActions::class, 'menuReorder'],
    'capability'  => 'manage_menus',
    'area'        => '/admin/menus',
    'input'       => ['menu_id' => 'required|int|min:1', 'tree' => ['type' => 'array', 'required' => true, 'max' => 2000, 'items' => [
        'type' => 'object', 'fields' => ['id' => 'required|int|min:1', 'parent_id' => 'int|nullable|min:1'],
    ]]],
    'description' => "Save a menu's order and nesting.",
]);

$ajax->register('users.activity', [
    'handler'     => [AdminActions::class, 'userActivity'],
    'methods'     => ['GET'],
    'capability'  => 'manage_users',
    'area'        => '/admin/users',
    'input'       => ['user_id' => 'required|int|min:1', 'filter' => 'string|max:40', 'page' => 'int|min:1|max:100000'],
    'description' => "One page of a user's activity.",
]);

$ajax->register('system.log', [
    'handler'     => [AdminActions::class, 'systemLog'],
    'methods'     => ['GET'],
    'capability'  => 'manage_settings',
    'area'        => '/admin/system',
    'input'       => ['name' => ['type' => 'string', 'required' => true, 'max' => 120, 'regex' => '/^[\w.\-]+\.log$/'], 'lines' => 'int|min:50|max:1000'],
    'description' => 'The end of a log file (System → Logs).',
]);

// 1.2.50 — Settings screens save without a page load. The form is sent as it
// would have been posted (`form`, URL-encoded) and saved by SettingsSaver,
// the same code as the POST /admin/settings/{tab} routes, which remain the
// fallback when script does not run.
$ajax->register('settings.save', [
    'handler'     => [\App\Http\Controllers\Ajax\SettingsActions::class, 'save'],
    'capability'  => 'manage_settings',
    'area'        => '/admin/settings',
    'max_body'    => 262144,
    'rate_limit'  => ['max' => 60, 'per' => 60],
    'input'       => [
        'tab'  => ['type' => 'string', 'required' => true, 'in' => array_keys(\App\Services\SettingsSaver::TABS)],
        'form' => 'required|string|max:200000',
    ],
    'description' => 'Save one Settings screen (General, Reading, Email, Permalinks…).',
]);

// 1.2.82 — moving between Settings sections without a page load (the tabs,
// and the phone's list of sections). Returns the section's page content as a
// page load renders it; admin/assets/js/settings-nav.js swaps it in.
$ajax->register('settings.section', [
    'handler'     => [\App\Http\Controllers\Ajax\SettingsActions::class, 'section'],
    'methods'     => ['GET'],
    'capability'  => 'manage_settings',
    'area'        => '/admin/settings',
    'rate_limit'  => ['max' => 120, 'per' => 60],
    'input'       => [
        'tab' => ['type' => 'string', 'required' => true, 'in' => array_merge(['home'], array_values(array_diff(array_keys(\App\Services\SettingsSaver::TABS), ['appearance'])))],
    ],
    'description' => 'One Settings section, to show without a page load.',
]);

// ---------------------------------------------------------------------------
// 1.2.54 — the Core Data Table system. One read action serves every table in
// the registry (routes/tables.php); the table's own capability and area are
// enforced in the handler, so 'read' here is only the floor. Writes stay on
// each feature's existing CSRF-protected routes. See docs/DATA-TABLE.md.
// ---------------------------------------------------------------------------
use App\Http\Controllers\Ajax\TableActions;

// BH Search (1.2.92): Settings → Search.
$ajax->register('search.status', [
    'handler'     => [\App\Http\Controllers\Ajax\SearchActions::class, 'status'],
    'methods'     => ['GET'],
    'capability'  => 'manage_options',
    'rate_limit'  => ['max' => 120, 'per' => 60],
    'description' => 'How far each search source has been indexed.',
]);
$ajax->register('search.build', [
    'handler'     => [\App\Http\Controllers\Ajax\SearchActions::class, 'build'],
    'methods'     => ['POST'],
    'capability'  => 'manage_options',
    'rate_limit'  => ['max' => 120, 'per' => 60],
    'description' => 'Index for a few seconds and report progress; call again until nothing is building.',
]);
$ajax->register('search.rebuild', [
    'handler'     => [\App\Http\Controllers\Ajax\SearchActions::class, 'rebuild'],
    'methods'     => ['POST'],
    'capability'  => 'manage_options',
    'rate_limit'  => ['max' => 30, 'per' => 60],
    'input'       => ['source' => ['type' => 'string', 'required' => true, 'max' => 100, 'regex' => '/^[a-z0-9][a-z0-9_.:-]*$/']],
    'description' => 'Start indexing one source again from the beginning.',
]);

$ajax->register('content.table', [
    'handler'     => [TableActions::class, 'query'],
    'methods'     => ['GET'],
    'capability'  => 'read',
    'rate_limit'  => ['max' => 240, 'per' => 60],
    'input'       => [
        'table'    => ['type' => 'string', 'required' => true, 'max' => 64, 'regex' => '/^[a-z0-9_.]+$/'],
        'page'     => 'int|min:1|max:100000',
        'per_page' => 'int|min:1|max:100',
        'sort'     => 'string|max:40',
        'search'   => 'string|max:200',
        'filters'  => 'string|max:2000',   // JSON object, decoded + checked in the handler
        'columns'  => 'string|max:2000',   // JSON array of column ids
    ],
    'description' => 'One page of a registered admin data table.',
]);

$ajax->register('content.table_terms', [
    'handler'     => [TableActions::class, 'terms'],
    'methods'     => ['GET'],
    'capability'  => 'read',
    'rate_limit'  => ['max' => 240, 'per' => 60],   // one per keystroke pause
    'input'       => [
        'taxonomy' => ['type' => 'string', 'required' => true, 'max' => 20, 'regex' => '/^[a-z0-9_]+$/'],
        'q'        => 'string|max:100',
        'limit'    => 'int|min:1|max:200',
        'selected' => ['type' => 'string', 'max' => 2000, 'regex' => '/^[0-9,]*$/'],
    ],
    'description' => 'Taxonomy terms for a data-table multi-select filter.',
]);

// Quick Edit (1.2.61): one form read and one save for every table that
// declares a QuickEditor. The table's capability and area are checked in the
// handler, the record and each field by the editor.
$ajax->register('content.quick_edit', [
    'handler'     => [TableActions::class, 'quickEditForm'],
    'methods'     => ['GET'],
    'capability'  => 'read',
    'rate_limit'  => ['max' => 120, 'per' => 60],
    'input'       => [
        'table' => ['type' => 'string', 'required' => true, 'max' => 64, 'regex' => '/^[a-z0-9_.]+$/'],
        'id'    => 'required|int|min:1',
    ],
    'description' => 'The Quick Edit form for one row of a data table.',
]);

$ajax->register('content.quick_edit_save', [
    'handler'     => [TableActions::class, 'quickEditSave'],
    'capability'  => 'read',
    'rate_limit'  => ['max' => 60, 'per' => 60],
    'input'       => [
        'table'   => ['type' => 'string', 'required' => true, 'max' => 64, 'regex' => '/^[a-z0-9_.]+$/'],
        'id'      => 'required|int|min:1',
        'version' => ['type' => 'string', 'required' => true, 'max' => 64, 'regex' => '/^[a-f0-9]+$/'],
        'values'  => 'required|string|max:20000',   // JSON object of changed fields, checked by the table's QuickEditor
        'confirm' => 'bool',
        'columns' => 'string|max:2000',             // JSON array: the columns the client shows
    ],
    'description' => 'Save the changed Quick Edit fields of one row and return the row re-rendered.',
]);

$ajax->register('content.table_prefs', [
    'handler'     => [TableActions::class, 'savePrefs'],
    'capability'  => 'read',
    'rate_limit'  => ['max' => 60, 'per' => 60],
    'input'       => [
        'table' => ['type' => 'string', 'required' => true, 'max' => 64, 'regex' => '/^[a-z0-9_.]+$/'],
        'prefs' => 'required|string|max:8000',
    ],
    'description' => "Save this user's column, page-size and density preferences for a table.",
]);

$ajax->register('content.table_prefs_reset', [
    'handler'     => [TableActions::class, 'resetPrefs'],
    'capability'  => 'read',
    'rate_limit'  => ['max' => 30, 'per' => 60],
    'input'       => [
        'table' => ['type' => 'string', 'required' => true, 'max' => 64, 'regex' => '/^[a-z0-9_.]+$/'],
    ],
    'description' => "Reset a table's preferences to the defaults.",
]);

// ---------------------------------------------------------------------------
// 1.2.68 — the dashboard. 'read' on every action: the dashboard is open to
// every signed-in role. Each widget's own capability, visibility rule and
// action capabilities are enforced inside DashboardService, against the
// user's stored layout — see app/Http/Controllers/Ajax/DashboardActions.php.
// ---------------------------------------------------------------------------

use App\Http\Controllers\Ajax\DashboardActions;

$ajax->register('widgets.dashboard_state', [
    'handler'     => [DashboardActions::class, 'state'],
    'methods'     => ['GET'],
    'capability'  => 'read',
    'rate_limit'  => ['max' => 60, 'per' => 60],
    'description' => "This user's dashboard layout and the widgets they may add.",
]);

$ajax->register('widgets.dashboard_data', [
    'handler'     => [DashboardActions::class, 'data'],
    'methods'     => ['GET'],
    'capability'  => 'read',
    'rate_limit'  => ['max' => 120, 'per' => 60],
    'input'       => [
        'ids'   => ['type' => 'string', 'required' => true, 'max' => 1400, 'regex' => '/^[a-z0-9,-]+$/'],
        'force' => 'bool',
    ],
    'description' => 'Data for some of this user\'s dashboard cards; each succeeds or fails on its own.',
]);

$ajax->register('widgets.dashboard_save', [
    'handler'     => [DashboardActions::class, 'save'],
    'capability'  => 'read',
    'rate_limit'  => ['max' => 30, 'per' => 60],
    'input'       => ['layout' => 'required|string|max:20000'],
    'description' => 'Save the order, size and visibility of the cards on this user\'s dashboard.',
]);

$ajax->register('widgets.dashboard_update', [
    'handler'     => [DashboardActions::class, 'update'],
    'capability'  => 'read',
    'rate_limit'  => ['max' => 60, 'per' => 60],
    'input'       => [
        'id'        => ['type' => 'string', 'required' => true, 'max' => 41, 'regex' => '/^[a-z][a-z0-9-]{2,40}$/'],
        'collapsed' => 'bool',
        'visible'   => 'bool',
        'settings'  => 'string|max:8000',
    ],
    'description' => 'Collapse, remove, restore or configure one dashboard card.',
]);

$ajax->register('widgets.dashboard_reset', [
    'handler'     => [DashboardActions::class, 'reset'],
    'capability'  => 'read',
    'rate_limit'  => ['max' => 10, 'per' => 60],
    'description' => 'Reset this user\'s dashboard to the site default.',
]);

$ajax->register('widgets.dashboard_action', [
    'handler'     => [DashboardActions::class, 'action'],
    'capability'  => 'read',
    'rate_limit'  => ['max' => 30, 'per' => 60],
    'input'       => [
        'id'     => ['type' => 'string', 'required' => true, 'max' => 41, 'regex' => '/^[a-z][a-z0-9-]{2,40}$/'],
        'action' => ['type' => 'string', 'required' => true, 'max' => 40, 'regex' => '/^[a-z0-9][a-z0-9_-]{0,39}$/'],
    ],
    'description' => 'Run one of a dashboard card\'s own actions.',
]);

$ajax->register('widgets.dashboard_default', [
    'handler'     => [DashboardActions::class, 'siteDefault'],
    'capability'  => 'manage_settings',
    'rate_limit'  => ['max' => 10, 'per' => 60],
    'input'       => ['clear' => 'bool'],
    'description' => 'Make the current dashboard the site default for people who have not customised theirs, or clear it.',
]);

// ---------------------------------------------------------------------------
// 1.2.84 — the Marketplace (admin/assets/js/marketplace.js). Browse, the
// category list, install and activate, for apps and for themes, each gated
// like the screen it stands in for. They replace the page's former JSON
// routes (/admin/{apps,themes}/marketplace/*.json, /admin/apps/{slug}/
// consent.json …), which remain for older callers.
// ---------------------------------------------------------------------------
use App\Http\Controllers\Ajax\MarketplaceActions;

$marketQuery = [
    'q'        => 'string|max:100',
    'category' => 'string|max:80',
    'tag'      => 'string|max:80',
    'sort'     => ['type' => 'string', 'in' => ['', 'featured', 'popular', 'newest', 'name']],
    'page'     => 'int|min:1|max:500',
];
$marketSlug = ['slug' => ['type' => 'string', 'required' => true, 'regex' => '/^[a-z0-9][a-z0-9_-]{0,79}$/']];

foreach ([
    'apps'   => ['cap' => 'manage_apps',   'area' => '/admin/apps',   'noun' => 'app'],
    'themes' => ['cap' => 'manage_themes', 'area' => '/admin/themes', 'noun' => 'theme'],
] as $ns => $m) {
    $ajax->register("{$ns}.market_browse", [
        'handler'     => [MarketplaceActions::class, "{$ns}Browse"],
        'methods'     => ['GET'],
        'capability'  => $m['cap'],
        'area'        => $m['area'],
        'rate_limit'  => ['max' => 120, 'per' => 60],
        'input'       => $marketQuery,
        'description' => "Browse the marketplace's {$m['noun']}s: search, category, tag, sort and page.",
    ]);
    $ajax->register("{$ns}.market_facets", [
        'handler'     => [MarketplaceActions::class, "{$ns}Facets"],
        'methods'     => ['GET'],
        'capability'  => $m['cap'],
        'area'        => $m['area'],
        'rate_limit'  => ['max' => 60, 'per' => 60],
        'description' => "The marketplace's {$m['noun']} categories, with counts.",
    ]);
    $ajax->register("{$ns}.market_install", [
        'handler'     => [MarketplaceActions::class, "{$ns}Install"],
        'capability'  => $m['cap'],
        'area'        => $m['area'],
        'rate_limit'  => ['max' => 20, 'per' => 60],
        'input'       => $marketSlug,
        'description' => "Download a {$m['noun']} from the marketplace (checksum-verified) and install or update it.",
    ]);
}

$ajax->register('apps.market_consent', [
    'handler'     => [MarketplaceActions::class, 'appsConsent'],
    'methods'     => ['GET'],
    'capability'  => 'manage_apps',
    'area'        => '/admin/apps',
    'rate_limit'  => ['max' => 60, 'per' => 60],
    'input'       => $marketSlug,
    'description' => 'The permissions an installed app asks for, before it is turned on.',
]);
$ajax->register('apps.market_activate', [
    'handler'     => [MarketplaceActions::class, 'appsActivate'],
    'capability'  => 'manage_apps',
    'area'        => '/admin/apps',
    'rate_limit'  => ['max' => 30, 'per' => 60],
    'input'       => $marketSlug + [
        'consent'     => 'bool',
        'permissions' => ['type' => 'array', 'max' => 50, 'items' => 'string|max:64'],
    ],
    'description' => 'Turn on an installed app, recording its permissions first when consent is given.',
]);
$ajax->register('themes.market_activate', [
    'handler'     => [MarketplaceActions::class, 'themesActivate'],
    'capability'  => 'manage_themes',
    'area'        => '/admin/themes',
    'rate_limit'  => ['max' => 30, 'per' => 60],
    'input'       => $marketSlug,
    'description' => 'Make an installed theme the one the site uses.',
]);
unset($marketQuery, $marketSlug, $ns, $m);

// ---------------------------------------------------------------------------
// 1.2.85 — the Apps screen (admin/assets/js/apps.js): the permission window
// and the table's actions. The screen's forms remain for no-JS browsers.
// ---------------------------------------------------------------------------
use App\Http\Controllers\Ajax\AppActions;

$appSlug = ['slug' => ['type' => 'string', 'required' => true, 'regex' => '/^[a-z0-9][a-z0-9_-]{0,79}$/']];
$ajax->register('apps.consent', [
    'handler'     => [AppActions::class, 'consent'],
    'methods'     => ['GET'],
    'capability'  => 'manage_apps',
    'area'        => '/admin/apps',
    'rate_limit'  => ['max' => 60, 'per' => 60],
    'input'       => $appSlug,
    'description' => 'The permissions an installed app asks for and what it has been granted.',
]);
$ajax->register('apps.consent_save', [
    'handler'     => [AppActions::class, 'consentSave'],
    'capability'  => 'manage_apps',
    'area'        => '/admin/apps',
    'rate_limit'  => ['max' => 30, 'per' => 60],
    'input'       => $appSlug + [
        'permissions' => ['type' => 'array', 'max' => 50, 'items' => 'string|max:64'],
        'activate'    => 'bool',
    ],
    'description' => "Record which of an app's permissions are granted; with activate, turn it on too.",
]);
$ajax->register('apps.bulk', [
    'handler'     => [AppActions::class, 'bulk'],
    'capability'  => 'manage_apps',
    'area'        => '/admin/apps',
    'rate_limit'  => ['max' => 30, 'per' => 60],
    'input'       => [
        'action' => ['type' => 'string', 'required' => true, 'in' => ['activate', 'deactivate', 'retry', 'delete']],
        'ids'    => ['type' => 'array', 'required' => true, 'min' => 1, 'max' => 100, 'items' => 'int|min:1'],
    ],
    'description' => 'Activate, deactivate, check again or delete installed apps, each reported on its own.',
]);
unset($appSlug);
