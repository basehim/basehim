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
        'type'     => 'string|max:20',
        'sort'     => 'string|in:newest,oldest,name,name_desc,largest,smallest',
    ],
    'description' => 'One page of the media library.',
]);

$ajax->register('media.delete', [
    'handler'     => [MediaActions::class, 'delete'],
    'capability'  => 'upload_media',
    'area'        => '/admin/media',
    'input'       => ['ids' => ['type' => 'array', 'required' => true, 'min' => 1, 'max' => 500, 'items' => 'int|min:1']],
    'description' => "Delete files; someone else's need delete_media.",
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

// ---------------------------------------------------------------------------
// 1.2.54 — the Core Data Table system. One read action serves every table in
// the registry (routes/tables.php); the table's own capability and area are
// enforced in the handler, so 'read' here is only the floor. Writes stay on
// each feature's existing CSRF-protected routes. See docs/DATA-TABLE.md.
// ---------------------------------------------------------------------------
use App\Http\Controllers\Ajax\TableActions;

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
