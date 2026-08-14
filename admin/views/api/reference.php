<?php $this->extend('layouts.app'); ?>
<?php $this->section('content'); ?>

<?php
$baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
    . '://' . ($_SERVER['HTTP_HOST'] ?? 'your-domain.com');
$apiBase = $baseUrl . '/api/v1';

/**
 * Not every documented endpoint lives under /api/v1. The MCP server, the OAuth
 * endpoints, the discovery documents and the admin routes are deliberately at
 * the site root — blindly prefixing $apiBase printed URLs that 404 (e.g.
 * /api/v1/mcp instead of /mcp).
 */
$isRootPath = static fn (string $p): bool =>
    (bool) preg_match('#^/(admin|mcp|oauth|\.well-known|feed|sitemap)\b#', $p);
$endpointUrl = static fn (string $p): string => ($isRootPath($p) ? $baseUrl : $apiBase) . $p;

$endpoints = [
    'Authentication' => [
        ['POST',   '/auth/login',    'Login — returns JWT access + refresh tokens',               false],
        ['POST',   '/auth/refresh',  'Refresh JWT access token using a refresh token',             false],
        ['POST',   '/auth/logout',   'Revoke the current refresh token family',                    true],
        ['GET',    '/me',            'Get the authenticated user\'s profile',                      true],
        ['PATCH',  '/me',            'Update the authenticated user\'s profile',                   true],
    ],
    'Posts' => [
        ['GET',    '/posts',          'List published posts (paginated). Supports ?page, ?per_page, ?search, ?category, ?tag', false],
        ['GET',    '/posts/{slug}',   'Get a single post by slug',                                 false],
        ['POST',   '/posts',          'Create a new post',                                         true],
        ['PUT',    '/posts/{id}',     'Replace a post',                                            true],
        ['PATCH',  '/posts/{id}',     'Partially update a post',                                   true],
        ['DELETE', '/posts/{id}',     'Delete a post',                                             true],
    ],
    'Auth, Password Reset & Email' => [
        ['POST',   '/admin/login',                 'Sign in with username/email + password. Suspended/inactive/archived accounts get a specific status message rather than a generic error. Honeypot-protected; after the configured wrong-attempt limit a math captcha is required, and after repeated captcha failures an email OTP is required. Supports "remember me". Admin session (full path — not under /api/v1)', false],
        ['GET',    '/admin/register',              'Registration form — only reachable when public registration is enabled in Authorization settings (full path)', false],
        ['POST',   '/admin/register',              'Create an account. Body: {username, email, password, display_name}. Honeypot-protected; new user gets the configured default role and an optional welcome email (full path)', false],
        ['GET',    '/admin/login/otp',             'Email-OTP entry screen, shown after repeated failed password + captcha attempts (full path)', false],
        ['POST',   '/admin/login/otp',             'Verify the 6-digit emailed code and finish signing in. Body: {code} (full path)', false],
        ['POST',   '/admin/logout',                'Sign out — clears the session and revokes the remember-me cookie/token (full path)', true],
        ['POST',   '/admin/forgot-password',       'Request a password-reset link by email. Rate-limited 3/hour; single-use tokens expire in 60 min (full path)', false],
        ['GET',    '/admin/reset-password/{token}','Reset form — validates the token before rendering (full path)', false],
        ['POST',   '/admin/reset-password',        'Set a new password. Body: {token, password, password_confirm} (full path)', false],
        ['GET',    '/admin/settings/authorization','Authorization settings — registration toggle + default role, remember-me, honeypot, captcha/OTP thresholds, welcome email. Requires manage_settings (full path)', true],
        ['POST',   '/admin/settings/authorization','Save Authorization settings. CSRF (full path)', true],
        ['POST',   '/admin/settings/email/test',   'Send a test email to the signed-in admin using saved Email settings. CSRF (full path)', true],
    ],
    'System & Maintenance' => [
        ['GET',    '/admin/system',              'System diagnostics page (Overview, PHP & Server, Database, Logs, Cache). Requires manage_settings (full path — not under /api/v1)', true],
        ['GET',    '/admin/system/log',          'Tail a log file as JSON. ?name=basehim-YYYY-MM-DD.log&lines=N (full path)', true],
        ['POST',   '/admin/system/log/delete',   'Delete a log file. Body: {name}. CSRF (full path)', true],
        ['POST',   '/admin/system/cache/clear',  'Clear application cache + reset OPcache. CSRF (full path)', true],
        ['POST',   '/admin/system/migrate',      'Apply pending database migrations. CSRF (full path)', true],
    ],
    'User Management & Activity' => [
        ['POST',   '/admin/roles',                    'Create a custom role. Body: label, level, capabilities[]. Level capped below your own; you can only assign caps you hold. CSRF (full path — not under /api/v1)', true],
        ['POST',   '/admin/roles/{slug}/delete',      'Delete a custom role (must be unused). CSRF (full path)', true],
        ['POST',   '/admin/users/{id}/access',        'Save role + per-user overrides (cap_mode[CAP]=default|grant|deny), including access_app:{slug} caps. Blocks self-edits & privilege escalation. CSRF (full path)', true],
        ['GET',    '/admin/users/{id}/activity.json', 'Paginated audit trail. ?filter=all|logins|content|audit&page=N (full path)', true],
        ['POST',   '/admin/users/{id}/archive',       'Archive (status=inactive) — blocks sign-in, reversible. CSRF (full path)', true],
        ['POST',   '/admin/users/{id}/suspend',       'Suspend (status=suspended) — blocks sign-in, reversible. CSRF (full path)', true],
        ['POST',   '/admin/users/{id}/reactivate',    'Reactivate an archived/suspended account. CSRF (full path)', true],
        ['POST',   '/admin/users/{id}/transfer',      'Transfer all authored content to another user. Body: {to_user_id}. CSRF (full path)', true],
    ],
    'Editor & Blocks' => [
        ['POST',   '/admin/posts/editor/render', 'Render block JSON to front-end HTML for live preview. Body: {content}. Admin session + CSRF (full path — not under /api/v1)', true],
        ['GET',    '/admin/posts/editor/templates', 'Reusable block templates for the editor inserter as JSON: [{id,title,blocks}]. Admin session (full path)', true],
        ['GET',    '/admin/media/json',          'Media list powering the editor\'s image picker. Supports ?q, ?type, ?sort, ?page. Admin session (full path — not under /api/v1)', true],
        ['GET',    '/posts/{slug}',              'Public post — blocks content is returned server-rendered as HTML via the post.content filter', false],
    ],
    'MCP Server (AI assistants)' => [
        ['POST',   '/mcp',                         'Model Context Protocol endpoint (JSON-RPC 2.0). Connect an AI assistant (e.g. Claude) with an API key via Authorization: Bearer basehim_.... Supports initialize, tools/list, tools/call, resources/list, resources/read. Tools are gated by the key\'s scopes (full path — not under /api/v1)', false],
        ['GET',    '/mcp',                         'Discovery ping — returns the server name and transport hint (full path)', false],
    ],
    'Widgets' => [
        ['GET',    '/admin/widgets',             'Widgets overview page — lists every widget registered by active apps & the theme, with their surfaces (editor/frontend/dashboard). Requires manage_apps (full path — not under /api/v1)', true],
        ['GET',    '/admin/widgets/list.json',   'Registered widgets as JSON. Optional ?surface=editor|frontend|dashboard filters to widgets available on that surface. Each item: {key,title,description,icon,surfaces[],fields[],source,dashboard}. Admin session (full path)', true],
        ['POST',   '/admin/widgets/render',      'Render one widget to HTML. Body: {widget: key, settings: {..}, surface: editor|frontend|dashboard}. Powers the live widget preview in the editor. Admin session + CSRF (full path)', true],
        ['GET',    '/admin/widgets/areas',       'Widget-areas screen — place & order frontend widgets inside the areas the active theme declares (theme.json "widget_areas"). Requires manage_apps (full path — not under /api/v1)', true],
        ['POST',   '/admin/widgets/areas/{area}/add',            'Add a frontend widget instance to an area. Body: {widget: key}. CSRF (full path)', true],
        ['POST',   '/admin/widgets/areas/{area}/reorder',        'Reorder an area from an explicit id list. Body: {order: [instanceId, ...]}. Returns JSON {ok}. CSRF (full path)', true],
        ['POST',   '/admin/widgets/areas/{area}/{itemId}',       'Save one placed widget\'s settings. Body: {settings: {..}}. CSRF (full path)', true],
        ['POST',   '/admin/widgets/areas/{area}/{itemId}/move',  'Move a placed widget one step. Body: {dir: up|down}. CSRF (full path)', true],
        ['POST',   '/admin/widgets/areas/{area}/{itemId}/remove','Remove a placed widget from an area. CSRF (full path)', true],
    ],
    'Templates' => [
        ['GET',    '/admin/templates',        'List reusable block templates. Supports ?q (search), ?status, ?sort (newest|oldest|title_az|title_za), ?page. Admin session (full path)', true],
        ['GET',    '/admin/templates/create', 'New-template block editor', true],
        ['POST',   '/admin/templates',        'Create a template. CSRF (full path)', true],
        ['POST',   '/admin/templates/bulk',   'Bulk action on templates. Body: {bulk_action: publish|draft|delete, ids[]}. CSRF (full path)', true],
        ['POST',   '/admin/templates/{id}',   'Update a template. CSRF (full path)', true],
        ['POST',   '/admin/templates/{id}/delete', 'Delete a template. CSRF (full path)', true],
    ],
    'Posts (admin)' => [
        ['GET',    '/admin/posts',       'List posts. Supports ?q (search), ?status, ?sort (newest|oldest|title_az|title_za), ?page. Admin session (full path)', true],
        ['POST',   '/admin/posts/bulk',  'Bulk action on posts. Body: {bulk_action: publish|draft|delete|restore|delete_forever, ids[]}. Per-item ownership + publish caps enforced. CSRF (full path)', true],
        ['GET',    '/admin/posts?view=trash', 'Trash view: soft-deleted posts with restore/purge actions. Same for /admin/templates?view=trash', true],
        ['POST',   '/admin/posts/{id}/restore', 'Restore a trashed post. Same pattern for templates. CSRF (full path)', true],
        ['POST',   '/admin/posts/{id}/force-delete', 'Permanently delete one trashed post. CSRF (full path)', true],        ['POST',   '/admin/themes/install', 'Upload + install a theme zip (theme.json + templates/ required; overwrite=1 to update in place). manage_themes, CSRF (full path)', true],
        ['POST',   '/admin/themes/{slug}/delete', 'Delete an inactive theme (default + active protected). CSRF (full path)', true],

        ['GET',    '/admin/updates', 'Updates page: update-service status, available releases, install. manage_settings (full path)', true],
        ['POST',   '/admin/updates/check', 'Check the Basehim update service for newer releases; refreshes the sidebar badge count. CSRF (full path)', true],
        ['POST',   '/admin/updates/apply', 'Download, SHA-256-verify, and install a release; runs migrations. Body: {version}. CSRF (full path)', true],
        ['POST',   '/admin/posts/empty-trash', 'Permanently delete ALL trashed posts of this type (requires delete capability). Same for /admin/templates/empty-trash. CSRF (full path)', true],
    ],
    'Pages' => [
        ['GET',    '/pages',          'List published pages',                                      false],
        ['GET',    '/pages/{slug}',   'Get a single page by slug',                                 false],
        ['POST',   '/pages',          'Create a new page',                                         true],
        ['PUT',    '/pages/{id}',     'Replace a page',                                            true],
        ['DELETE', '/pages/{id}',     'Delete a page',                                             true],
    ],
    'Media' => [
        ['GET',    '/media',          'List uploaded media files',                                  true],
        ['POST',   '/media',          'Upload a new media file (multipart/form-data). Honours the Media settings for allowed types & max size, and generates the configured thumbnail sizes for images', true],
        ['PATCH',  '/media/{id}',     'Update {media} metadata. Body: any of {title, alt_text, caption, description}. Path, MIME type and dimensions are intentionally not writable — they describe the file on disk', true],
        ['DELETE', '/media/{id}',     'Delete a media file (and its generated thumbnail variants)',  true],
    ],
    'Media Settings & Thumbnails' => [
        ['GET',    '/admin/settings/media',            'Media settings screen — upload limits, allowed types, image quality, and thumbnail sizes (thumbnail/medium/large). Requires manage_settings (full path — not under /api/v1)', true],
        ['POST',   '/admin/settings/media',            'Save media settings. Body: max_upload_mb, jpeg_quality, allowed_types, organize_uploads, generate_thumbnails, thumb_w/thumb_h/thumb_crop, medium_w/medium_h, large_w/large_h, convert_webp. CSRF (full path)', true],
        ['POST',   '/admin/settings/media/regenerate', 'Regenerate thumbnails for every image using the current sizes (old variants removed first). Returns to the page with a counts summary. CSRF (full path)', true],
    ],
    'Comments' => [
        ['GET',    '/posts/{slug}/comments', 'List approved comments for a post',                  false],
        ['POST',   '/posts/{slug}/comments', 'Submit a comment on a post',                         false],
        ['GET',    '/comments',              'List every comment, filterable. ?status=approved|pending|spam|trash, ?post_id, ?search, ?page, ?per_page. Requires moderate_comments', true],
        ['GET',    '/comments/counts',       'Comment totals per status — what a moderation badge needs. Requires moderate_comments', true],
        ['GET',    '/comments/{id}',         'Get a single comment regardless of status. Requires moderate_comments', true],
        ['PATCH',  '/comments/{id}/status',  'Moderate a comment. Body: {status: approved|pending|spam|trash}. Requires moderate_comments', true],
        ['DELETE', '/comments/{id}',         'Permanently delete a comment (not the trash status — this is irreversible). Requires moderate_comments', true],
    ],
    'Taxonomies' => [
        ['GET',    '/taxonomies',                   'List all registered taxonomies',               false],
        ['GET',    '/taxonomies/{taxonomy}/terms',  'List terms for a taxonomy (e.g. category)',   false],
        ['POST',   '/taxonomies/{taxonomy}/terms',  'Create a new term',                            true],
        ['PUT',    '/terms/{id}',                   'Update a term',                                true],
        ['DELETE', '/terms/{id}',                   'Delete a term',                                true],
    ],
    'Menus' => [
        ['GET',    '/menus/{slug}',   'Get a navigation menu with its items, by SLUG (public)',    false],
        ['GET',    '/menus',          'List every menu. Requires manage_menus',                     true],
        ['POST',   '/menus',          'Create a menu. Body: {name, slug?, location?}. Requires manage_menus', true],
        ['PUT',    '/menus/{id}',     'Update a menu by ID. Body: any of {name, slug, location}. Requires manage_menus', true],
        ['DELETE', '/menus/{id}',     'Delete a menu and all of its items, by ID. Requires manage_menus', true],
        ['GET',    '/menus/{id}/items', 'List a menu\'s items as a tree. Requires manage_menus',   true],
        ['POST',   '/menus/{id}/items', 'Append an item. Body: {label, url?, parent_id?, sort_order?, target?}. Requires manage_menus', true],
        ['PUT',    '/menu-items/{id}', 'Update a single menu item. Requires manage_menus',          true],
        ['DELETE', '/menu-items/{id}', 'Delete a single menu item. Requires manage_menus',          true],
        ['POST',   '/admin/menus/{id}/delete', 'Delete a menu and all of its items. Admin session + CSRF (full path — not under /api/v1)', true],
    ],
    'Widget Areas' => [
        ['GET',    '/widget-areas',          'List the widget areas (sidebars) the active theme registers. Each item: {key, name, description, source, widget_count}', false],
        ['GET',    '/widget-areas/{area}',   'Get one area with its ordered widgets. Returns {key, name, description, items:[{id, widget, title, settings, html}], html} — each widget is server-rendered to HTML for headless front ends. 404 if the area is not registered', false],
    ],
    'Users' => [
        ['GET',    '/users',          'List all users (admin)',                                     true],
        ['GET',    '/users/{id}',     'Get a user by ID (admin)',                                  true],
        ['POST',   '/users',          'Create a user (admin)',                                     true],
        ['PUT',    '/users/{id}',     'Update a user (admin)',                                     true],
        ['DELETE', '/users/{id}',     'Delete a user (admin)',                                     true],
    ],
    'Settings' => [
        ['GET',    '/settings/public','Get publicly-available site settings (no auth)',             false],
        ['GET',    '/settings',       'Get all settings (admin)',                                   true],
        ['PUT',    '/settings',       'Update {settings} (admin)',                                    true],
    ],
    'Apps' => [
        ['GET',    '/apps',           'List installed apps: slug, name, version, status, icon, declared permissions, whether files are present. ?status=active|inactive. Requires manage_apps', true],
        ['GET',    '/apps/{slug}',    'Get one app with its full record. Requires manage_apps',    true],
    ],
    'Scheduled Tasks' => [
        ['GET',    '/schedule/run',   'Run every due task. Token-guarded rather than session-authed, because a crontab sends no cookies: ?token=… (find the full URL in GET /schedule). Bypasses the pseudo-cron throttle. Returns 404 on a bad token so the endpoint stays undiscoverable', false],
        ['GET',    '/schedule',       'List every scheduled task with its interval, next/last run, last status and failure count. Meta includes last_sweep and the ready-made cron_url. Requires manage_settings', true],
        ['POST',   '/schedule/{app}/{key}/run', 'Run one task immediately, ignoring its schedule. Only works while the owning app is active, since handlers are registered during boot(). Requires manage_settings', true],
    ],
    'Cache' => [
        ['POST',   '/cache/flush',    'Clear the application cache and reset OPcache. Body: {tag} to clear a single namespace instead of everything. There is deliberately no read/write-by-key endpoint — that would be a cross-app read primitive. Requires manage_settings', true],
    ],
    'Utility' => [
        ['GET',    '/search',         'Full-text search across posts and pages. ?q=term',          false],
    ],
];

$methodColors = [
    'GET'    => 'text-blue-700 bg-blue-50 border-blue-200',
    'POST'   => 'text-green-700 bg-green-50 border-green-200',
    'PUT'    => 'text-amber-700 bg-amber-50 border-amber-200',
    'PATCH'  => 'text-purple-700 bg-purple-50 border-purple-200',
    'DELETE' => 'text-red-700 bg-red-50 border-red-200',
];
?>

<?php $this->include('api._nav', ['subtab' => $subtab]); ?>
<div class="space-y-5 sm:space-y-6">

        <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3 sm:gap-4">
            <div class="min-w-0">
                <h2 class="text-xl font-semibold text-slate-900">API Reference</h2>
                <p class="text-sm text-slate-500 mt-1 break-words">All available REST endpoints. Base URL: <code class="bg-slate-100 px-1.5 py-0.5 rounded text-xs font-mono break-all"><?= htmlspecialchars($apiBase) ?></code></p>
            </div>
            <!-- Filter input: full width on phones, fixed on larger screens. -->
            <input type="text" id="refSearch" placeholder="Search endpoints…"
                   class="w-full sm:w-56 sm:shrink-0 border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                   oninput="filterEndpoints(this.value)">
        </div>

        <!-- Legend -->
        <div class="flex items-center gap-4 text-xs text-slate-500 flex-wrap">
            <span class="flex items-center gap-1.5"><span class="w-2 h-2 rounded-full bg-green-400 inline-block"></span> Public — no auth required</span>
            <span class="flex items-center gap-1.5"><span class="w-2 h-2 rounded-full bg-amber-400 inline-block"></span> Protected — API key or JWT required</span>
        </div>

        <!-- Endpoint groups -->
        <?php foreach ($endpoints as $group => $rows): ?>
        <div class="bg-white border border-slate-200 rounded-xl overflow-hidden endpoint-group"
             data-group="<?= strtolower($group) ?>">
            <div class="bg-slate-50 border-b border-slate-200 px-4 py-2.5 flex items-center justify-between">
                <h3 class="text-sm font-semibold text-slate-800"><?= htmlspecialchars($group) ?></h3>
                <span class="text-xs text-slate-400"><?= count($rows) ?> endpoint<?= count($rows) !== 1 ? 's' : '' ?></span>
            </div>
            <div class="divide-y divide-slate-100">
                <?php foreach ($rows as [$method, $path, $desc, $auth]): ?>
                <div class="endpoint-row flex items-start gap-3 px-4 py-3 hover:bg-slate-50 transition"
                     data-text="<?= strtolower($method . ' ' . $path . ' ' . $desc) ?>">
                    <span class="<?= $methodColors[$method] ?? '' ?> border text-[11px] font-mono font-bold px-1.5 py-0.5 rounded shrink-0 mt-0.5 w-14 text-center">
                        <?= $method ?>
                    </span>
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-2 flex-wrap">
                            <code class="text-xs font-mono text-slate-800 break-all"><?= htmlspecialchars($endpointUrl($path)) ?></code>
                            <?php if ($auth): ?>
                                <span class="inline-flex items-center gap-1 text-[10px] text-amber-600 bg-amber-50 border border-amber-200 px-1.5 py-0.5 rounded-full font-medium">
                                    <?= icon('lock-closed', 'w-4 h-4 text-[8px]') ?> Auth
                                </span>
                            <?php else: ?>
                                <span class="inline-flex items-center gap-1 text-[10px] text-green-600 bg-green-50 border border-green-200 px-1.5 py-0.5 rounded-full font-medium">
                                    <?= icon('globe-alt', 'w-4 h-4 text-[8px]') ?> Public
                                </span>
                            <?php endif; ?>
                        </div>
                        <p class="text-xs text-slate-500 mt-0.5"><?= htmlspecialchars($desc) ?></p>
                    </div>
                    <button onclick="copyEndpoint('<?= htmlspecialchars($method . ' ' . $endpointUrl($path), ENT_QUOTES) ?>')"
                            title="Copy"
                            class="text-slate-300 hover:text-slate-600 transition mt-0.5 shrink-0">
                        <?= icon('document-duplicate', 'w-3.5 h-3.5') ?>
                    </button>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>

        <!-- Response format -->
        <div class="bg-white border border-slate-200 rounded-xl p-5 space-y-3">
            <h3 class="text-sm font-semibold text-slate-800">Response Format</h3>
            <p class="text-xs text-slate-500">All responses are JSON. Successful responses return the resource or a paginated collection. Errors follow RFC 7807 Problem Details.</p>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <div>
                    <p class="text-xs font-semibold text-slate-600 mb-1.5">Success (200)</p>
                    <pre class="bg-slate-900 text-green-300 rounded-lg p-3 text-xs overflow-x-auto">{
  "data": { ... },
  "meta": { "total": 42, "page": 1 }
}</pre>
                </div>
                <div>
                    <p class="text-xs font-semibold text-slate-600 mb-1.5">Error (4xx / 5xx)</p>
                    <pre class="bg-slate-900 text-red-300 rounded-lg p-3 text-xs overflow-x-auto">{
  "type": "https://basehim.io/errors/...",
  "title": "Unauthorized",
  "status": 401,
  "detail": "Authentication required."
}</pre>
                </div>
            </div>
        </div>

        <!-- Block editor extensibility -->
        <div class="bg-white border border-slate-200 rounded-xl p-5 space-y-3">
            <h3 class="text-sm font-semibold text-slate-800"><?= icon('square-3-stack-3d', 'w-4 h-4 text-blue-500 mr-2') ?>Block Editor — App APIs</h3>
            <p class="text-xs text-slate-500">The post editor is fully extensible. Apps register custom blocks, toolbar buttons, and sidebar panels in JS, and server-side renderers in PHP. Full guide: <code class="bg-slate-100 px-1.5 py-0.5 rounded">BLOCK-EDITOR.md</code> in the project root.</p>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <div>
                    <p class="text-xs font-semibold text-slate-600 mb-1.5">JavaScript (window.BasehimEditor)</p>
                    <pre class="bg-slate-900 text-blue-200 rounded-lg p-3 text-xs overflow-x-auto">BasehimEditor.registerBlock(type, {
  title, icon, category, defaults,
  edit(el, block, api),
  inspector(el, block, api),  // sidebar
  save(block)                 // html fallback
});
BasehimEditor.addToolbarButton({...});
BasehimEditor.addBlockAction({...});
BasehimEditor.addSidebarPanel({...});
BasehimEditor.on('change'|'save'|..., cb);
BasehimEditor.addFilter('save.data', fn);
BasehimEditor.getBlocks() / setBlocks()
  / insertBlock() / updateBlock()</pre>
                </div>
                <div>
                    <p class="text-xs font-semibold text-slate-600 mb-1.5">PHP hooks (from an app's boot())</p>
                    <pre class="bg-slate-900 text-amber-200 rounded-lg p-3 text-xs overflow-x-auto">$this-&gt;addEditorScript($url);
$this-&gt;addEditorStyle($url);
$this-&gt;addEditorConfig([...]);
$this-&gt;registerBlockRenderer('type',
  fn(array $data, array $block)
    =&gt; '&lt;div&gt;…&lt;/div&gt;');

// Widgets (editor / frontend / dashboard):
$this-&gt;registerWidget('stats', [
  'title'    =&gt; 'Site Stats',
  'icon'     =&gt; 'fa-chart-simple',
  'surfaces' =&gt; ['dashboard','frontend'],
  'fields'   =&gt; [['key'=&gt;'limit','label'=&gt;'Limit','type'=&gt;'number']],
  'render'   =&gt; fn(array $s, string $surface)
    =&gt; '&lt;div&gt;…&lt;/div&gt;',
]);
// Theme: return [...] from the theme's widgets.php

// Filters:
//  editor.scripts / styles / config
//  blocks.pre_render
//  blocks.render.{type}   (incl. blocks.render.widget)
//  blocks.rendered</pre>
                </div>
            </div>
        </div>

        <!-- MCP server -->
        <div class="bg-white border border-slate-200 rounded-xl p-5 space-y-3">
            <h3 class="text-sm font-semibold text-slate-800"><?= icon('cpu-chip', 'w-4 h-4 text-violet-500 mr-2') ?>MCP Server — connect an AI assistant</h3>
            <p class="text-xs text-slate-500">Basehim exposes a <strong>Model Context Protocol</strong> endpoint so AI assistants (like Claude) can search, read, and author content on this site. It speaks JSON-RPC 2.0 over a single HTTP endpoint and authenticates with your existing API keys — no extra server to run.</p>
            <div class="grid md:grid-cols-2 gap-3">
                <div>
                    <p class="text-xs font-semibold text-slate-600 mb-1.5">Endpoint</p>
                    <pre class="bg-slate-900 text-emerald-200 rounded-lg p-3 text-xs overflow-x-auto"><?= htmlspecialchars($base) ?>/mcp

Authorization: Bearer basehim_your_key
Content-Type: application/json</pre>
                </div>
                <div>
                    <p class="text-xs font-semibold text-slate-600 mb-1.5">Tools (gated by key scopes)</p>
                    <pre class="bg-slate-900 text-sky-200 rounded-lg p-3 text-xs overflow-x-auto">get_site_info                       (always)
search_content, list_posts,
list_pages, get_post, get_page      posts:read
create_post, create_page,
update_post, update_page,
trash_post, set_post_terms          posts:write
list_taxonomies                     taxonomies:read
create_term                         taxonomies:write
list_media, get_media               media:read
list_comments                       comments:read
moderate_comment                    comments:write
get_settings                        settings:read
list_users                          users:read</pre>
                </div>
            </div>
            <div class="rounded-lg bg-violet-50 border border-violet-200 p-3 flex flex-col sm:flex-row sm:items-center gap-3">
                <p class="text-xs text-violet-800 leading-relaxed min-w-0 flex-1">
                    <strong class="text-violet-900">Connecting Claude?</strong>
                    Leave the OAuth Client ID and Secret blank — this server registers clients automatically.
                    The full walkthrough, tools and troubleshooting live on the MCP page.
                </p>
                <a href="<?= $base ?>/admin/api/mcp"
                   class="shrink-0 inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-violet-600 hover:bg-violet-700 text-white text-xs font-semibold">
                    Setup guide <?= icon('arrow-right', 'w-3.5 h-3.5') ?>
                </a>
            </div>
            <p class="text-xs text-slate-500">API keys are for <strong>direct</strong> clients (Claude Code, curl, scripts), which send them as a bearer token. Create one under <strong>API → API Keys</strong> with the scopes you want — read-only is safest to start; <code class="bg-slate-100 px-1.5 py-0.5 rounded">posts:write</code> lets an assistant draft and edit posts. The web connector has no field for API keys, which is what OAuth is for.</p>
        </div>

        <!-- Email service + role-based access -->
        <div class="bg-white border border-slate-200 rounded-xl p-5 space-y-3">
            <h3 class="text-sm font-semibold text-slate-800"><?= icon('envelope', 'w-4 h-4 text-blue-500 mr-2') ?>Email Service &amp; Role-Based Access — App APIs</h3>
            <p class="text-xs text-slate-500">Core mail is configured under Settings → Email (PHP mail() or SMTP). Roles map to capabilities in <code class="bg-slate-100 px-1.5 py-0.5 rounded">config/capabilities.php</code>; the <code class="bg-slate-100 px-1.5 py-0.5 rounded">AdminAreaPolicy</code> middleware enforces them per admin area, and controllers add per-record rules (own posts only, publish gating).</p>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <div>
                    <p class="text-xs font-semibold text-slate-600 mb-1.5">Sending mail from an app</p>
                    <pre class="bg-slate-900 text-emerald-200 rounded-lg p-3 text-xs overflow-x-auto">$mailer = $this-&gt;app-&gt;make(
    \App\Services\Mailer::class);
$mailer-&gt;sendTemplate($to, $subject,
    $heading, $bodyHtml);

// Hooks:
//  mail.before_send (filter — modify
//    the mail array or return false)
//  mail.sent / mail.failed (actions)</pre>
                </div>
                <div>
                    <p class="text-xs font-semibold text-slate-600 mb-1.5">Roles &amp; capabilities</p>
                    <pre class="bg-slate-900 text-sky-200 rounded-lg p-3 text-xs overflow-x-auto">use App\Http\Middleware\CheckCapability;
CheckCapability::userCan($user,
    'edit_others_posts');

// Roles: super_admin, admin, editor,
//        author, contributor, subscriber
// Route middleware:
//   'CheckCapability:manage_apps'
// Filter the area map:
//   admin.area_policy
// Sidebar auto-hides denied areas.</pre>
                </div>
            </div>
        </div>

    </div><!-- /content -->
<?php $this->endSection(); ?>
<?php $this->section('scripts'); ?>
<script>
function filterEndpoints(q) {
    q = q.toLowerCase();
    document.querySelectorAll('.endpoint-group').forEach(group => {
        let vis = 0;
        group.querySelectorAll('.endpoint-row').forEach(row => {
            const match = !q || row.dataset.text.includes(q);
            row.style.display = match ? '' : 'none';
            if (match) vis++;
        });
        group.style.display = (vis === 0 && q) ? 'none' : '';
    });
}
function copyEndpoint(text) {
    navigator.clipboard.writeText(text).then(() => {
        const el = event.currentTarget;
        el.innerHTML = '<?= icon('check', 'w-3.5 h-3.5 text-green-500') ?>';
        setTimeout(() => el.innerHTML = '<?= icon('document-duplicate', 'w-3.5 h-3.5') ?>', 1500);
    });
}
</script>
<?php $this->endSection(); ?>
