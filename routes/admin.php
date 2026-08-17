<?php
/**
 * Admin panel routes
 * All under /admin prefix, session-based auth
 */

/** @var \App\Core\Router $router */
$router = $app->make('router');

$router->group(['prefix' => '/admin'], function ($router) {

    // ---- Guest (login) ----
    $router->get('/login', ['App\\Http\\Controllers\\Admin\\AuthController', 'showLogin']);
    $router->post('/login', ['App\\Http\\Controllers\\Admin\\AuthController', 'login']);
    $router->get('/forgot-password', ['App\\Http\\Controllers\\Admin\\AuthController', 'showForgot']);
    $router->post('/forgot-password', ['App\\Http\\Controllers\\Admin\\AuthController', 'sendReset']);
    $router->get('/reset-password/{token}', ['App\\Http\\Controllers\\Admin\\AuthController', 'showReset']);
    $router->post('/reset-password', ['App\\Http\\Controllers\\Admin\\AuthController', 'reset']);
    $router->get('/register', ['App\\Http\\Controllers\\Admin\\AuthController', 'showRegister']);
    $router->post('/register', ['App\\Http\\Controllers\\Admin\\AuthController', 'register']);
    $router->get('/login/otp', ['App\\Http\\Controllers\\Admin\\AuthController', 'showOtp']);
    $router->post('/login/otp', ['App\\Http\\Controllers\\Admin\\AuthController', 'verifyOtp']);

    // ---- Authenticated admin area ----
    // AdminAreaPolicy enforces role capabilities per admin section
    // (config/capabilities.php); it runs after authentication on every route.
    $router->group(['middleware' => ['App\\Http\\Middleware\\Authenticate', 'App\\Http\\Middleware\\AdminAreaPolicy']], function ($router) {

        $router->post('/logout', ['App\\Http\\Controllers\\Admin\\AuthController', 'logout']);
        $router->get('/logout', ['App\\Http\\Controllers\\Admin\\AuthController', 'logout']);

        // Dashboard
        $router->get('', ['App\\Http\\Controllers\\Admin\\DashboardController', 'index']);
        $router->get('/', ['App\\Http\\Controllers\\Admin\\DashboardController', 'index']);
        $router->get('/dashboard', ['App\\Http\\Controllers\\Admin\\DashboardController', 'index']);

        // System (diagnostics + maintenance)
        // Updates (CloudHim remote updates)
        $router->get('/updates', ['App\\Http\\Controllers\\Admin\\UpdateController', 'index']);
        $router->post('/updates/check', ['App\\Http\\Controllers\\Admin\\UpdateController', 'check']);
        $router->post('/updates/sync.json', ['App\\Http\\Controllers\\Admin\\UpdateController', 'sync']);
        $router->post('/updates/check.json', ['App\\Http\\Controllers\\Admin\\UpdateController', 'checkJson']);
        $router->post('/updates/install-step.json', ['App\\Http\\Controllers\\Admin\\UpdateController', 'installStep']);
        $router->post('/updates/apply', ['App\\Http\\Controllers\\Admin\\UpdateController', 'apply']);

        $router->get('/system', ['App\\Http\\Controllers\\Admin\\SystemController', 'index']);
        $router->get('/system/log', ['App\\Http\\Controllers\\Admin\\SystemController', 'viewLog']);
        $router->post('/system/log/delete', ['App\\Http\\Controllers\\Admin\\SystemController', 'deleteLog']);
        $router->post('/system/cache/clear', ['App\\Http\\Controllers\\Admin\\SystemController', 'clearCache']);
        $router->post('/system/migrate', ['App\\Http\\Controllers\\Admin\\SystemController', 'runMigrations']);

        // Profile
        $router->get('/profile', ['App\\Http\\Controllers\\Admin\\ProfileController', 'show']);
        $router->post('/profile', ['App\\Http\\Controllers\\Admin\\ProfileController', 'update']);

        // Posts
        $router->post('/posts/editor/render', ['App\\Http\\Controllers\\Admin\\PostController', 'editorRender']);
        $router->get('/posts/editor/templates', ['App\\Http\\Controllers\\Admin\\PostController', 'editorTemplates']);
        $router->get('/posts', ['App\\Http\\Controllers\\Admin\\PostController', 'index']);
        $router->get('/posts/create', ['App\\Http\\Controllers\\Admin\\PostController', 'create']);
        $router->post('/posts', ['App\\Http\\Controllers\\Admin\\PostController', 'store']);
        $router->post('/posts/empty-trash', ['App\\Http\\Controllers\\Admin\\PostController', 'emptyTrash']);
        $router->post('/posts/bulk', ['App\\Http\\Controllers\\Admin\\PostController', 'bulk']);
        $router->get('/posts/{id}/edit', ['App\\Http\\Controllers\\Admin\\PostController', 'edit']);
        $router->post('/posts/{id}', ['App\\Http\\Controllers\\Admin\\PostController', 'update']);
        $router->post('/posts/{id}/delete', ['App\\Http\\Controllers\\Admin\\PostController', 'destroy']);
        $router->post('/posts/{id}/restore', ['App\\Http\\Controllers\\Admin\\PostController', 'restore']);
        $router->post('/posts/{id}/force-delete', ['App\\Http\\Controllers\\Admin\\PostController', 'forceDelete']);

        // Templates — reusable block patterns (posts of type 'template').
        $router->get('/templates', ['App\\Http\\Controllers\\Admin\\TemplateController', 'index']);
        $router->get('/templates/create', ['App\\Http\\Controllers\\Admin\\TemplateController', 'create']);
        $router->post('/templates', ['App\\Http\\Controllers\\Admin\\TemplateController', 'store']);
        $router->post('/templates/bulk', ['App\\Http\\Controllers\\Admin\\TemplateController', 'bulk']);
        $router->post('/templates/empty-trash', ['App\\Http\\Controllers\\Admin\\TemplateController', 'emptyTrash']);
        $router->get('/templates/{id}/edit', ['App\\Http\\Controllers\\Admin\\TemplateController', 'edit']);
        $router->post('/templates/{id}', ['App\\Http\\Controllers\\Admin\\TemplateController', 'update']);
        $router->post('/templates/{id}/delete', ['App\\Http\\Controllers\\Admin\\TemplateController', 'destroy']);
        $router->post('/templates/{id}/restore', ['App\\Http\\Controllers\\Admin\\TemplateController', 'restore']);
        $router->post('/templates/{id}/force-delete', ['App\\Http\\Controllers\\Admin\\TemplateController', 'forceDelete']);

        // Pages
        // ---- App-registered content types ----
        // One set of routes serves every custom type; ContentController reads
        // {type} from the path and refuses anything not currently registered.
        // Registered before /pages so the literal prefix cannot be shadowed.
        $router->get('/content/{type}', ['App\\Http\\Controllers\\Admin\\ContentController', 'index']);
        $router->get('/content/{type}/create', ['App\\Http\\Controllers\\Admin\\ContentController', 'create']);
        $router->post('/content/{type}', ['App\\Http\\Controllers\\Admin\\ContentController', 'store']);
        $router->post('/content/{type}/bulk', ['App\\Http\\Controllers\\Admin\\ContentController', 'bulk']);
        $router->post('/content/{type}/empty-trash', ['App\\Http\\Controllers\\Admin\\ContentController', 'emptyTrash']);
        $router->get('/content/{type}/{id}/edit', ['App\\Http\\Controllers\\Admin\\ContentController', 'edit']);
        $router->post('/content/{type}/{id}', ['App\\Http\\Controllers\\Admin\\ContentController', 'update']);
        $router->post('/content/{type}/{id}/delete', ['App\\Http\\Controllers\\Admin\\ContentController', 'destroy']);
        $router->post('/content/{type}/{id}/restore', ['App\\Http\\Controllers\\Admin\\ContentController', 'restore']);
        $router->post('/content/{type}/{id}/force-delete', ['App\\Http\\Controllers\\Admin\\ContentController', 'forceDelete']);

        $router->get('/pages', ['App\\Http\\Controllers\\Admin\\PageController', 'index']);
        $router->get('/pages/create', ['App\\Http\\Controllers\\Admin\\PageController', 'create']);
        $router->post('/pages', ['App\\Http\\Controllers\\Admin\\PageController', 'store']);
        $router->get('/pages/{id}/edit', ['App\\Http\\Controllers\\Admin\\PageController', 'edit']);
        $router->post('/pages/{id}', ['App\\Http\\Controllers\\Admin\\PageController', 'update']);
        $router->post('/pages/{id}/delete', ['App\\Http\\Controllers\\Admin\\PageController', 'destroy']);

        // Media
        $router->get('/media', ['App\\Http\\Controllers\\Admin\\MediaController', 'index']);
        $router->get('/media/json', ['App\\Http\\Controllers\\Admin\\MediaController', 'listJson']);
        $router->post('/media/upload', ['App\\Http\\Controllers\\Admin\\MediaController', 'upload']);
        $router->post('/media/bulk-delete', ['App\\Http\\Controllers\\Admin\\MediaController', 'bulkDestroy']);
        $router->post('/media/{id}/delete', ['App\\Http\\Controllers\\Admin\\MediaController', 'destroy']);
        $router->post('/media/{id}/update', ['App\\Http\\Controllers\\Admin\\MediaController', 'updateMeta']);

        // Users
        $router->get('/users', ['App\\Http\\Controllers\\Admin\\UserController', 'index']);
        $router->get('/users/create', ['App\\Http\\Controllers\\Admin\\UserController', 'create']);
        $router->post('/users', ['App\\Http\\Controllers\\Admin\\UserController', 'store']);
        $router->get('/users/{id}/edit', ['App\\Http\\Controllers\\Admin\\UserController', 'edit']);
        $router->post('/users/{id}', ['App\\Http\\Controllers\\Admin\\UserController', 'update']);
        $router->post('/users/{id}/delete', ['App\\Http\\Controllers\\Admin\\UserController', 'destroy']);
        $router->post('/users/{id}/access', ['App\\Http\\Controllers\\Admin\\UserController', 'saveAccess']);
        $router->get('/users/{id}/activity.json', ['App\\Http\\Controllers\\Admin\\UserController', 'activityJson']);
        $router->post('/users/{id}/archive', ['App\\Http\\Controllers\\Admin\\UserController', 'archive']);
        $router->post('/users/{id}/suspend', ['App\\Http\\Controllers\\Admin\\UserController', 'suspend']);
        $router->post('/users/{id}/reactivate', ['App\\Http\\Controllers\\Admin\\UserController', 'reactivate']);
        $router->post('/users/{id}/transfer', ['App\\Http\\Controllers\\Admin\\UserController', 'transferOwnership']);

        // Roles (custom role management)
        $router->get('/roles', ['App\\Http\\Controllers\\Admin\\RoleController', 'index']);
        $router->post('/roles', ['App\\Http\\Controllers\\Admin\\RoleController', 'store']);
        $router->post('/roles/{slug}/delete', ['App\\Http\\Controllers\\Admin\\RoleController', 'destroy']);

        // Taxonomies
        $router->get('/taxonomies/{taxonomy}', ['App\\Http\\Controllers\\Admin\\TaxonomyController', 'index']);
        $router->post('/taxonomies/{taxonomy}/terms', ['App\\Http\\Controllers\\Admin\\TaxonomyController', 'storeTerm']);
        $router->post('/terms/{id}', ['App\\Http\\Controllers\\Admin\\TaxonomyController', 'updateTerm']);
        $router->post('/terms/{id}/delete', ['App\\Http\\Controllers\\Admin\\TaxonomyController', 'destroyTerm']);

        // Comments
        $router->get('/comments', ['App\\Http\\Controllers\\Admin\\CommentController', 'index']);
        $router->post('/comments/{id}/approve', ['App\\Http\\Controllers\\Admin\\CommentController', 'approve']);
        $router->post('/comments/{id}/spam', ['App\\Http\\Controllers\\Admin\\CommentController', 'spam']);
        $router->post('/comments/{id}/delete', ['App\\Http\\Controllers\\Admin\\CommentController', 'destroy']);

        // Menus
        $router->get('/menus', ['App\\Http\\Controllers\\Admin\\MenuController', 'index']);
        $router->get('/menus/{id}/edit', ['App\\Http\\Controllers\\Admin\\MenuController', 'edit']);
        $router->post('/menus', ['App\\Http\\Controllers\\Admin\\MenuController', 'store']);
        $router->post('/menus/{id}', ['App\\Http\\Controllers\\Admin\\MenuController', 'update']);
        $router->post('/menus/{id}/delete', ['App\\Http\\Controllers\\Admin\\MenuController', 'destroy']);
        $router->post('/menus/{id}/items', ['App\\Http\\Controllers\\Admin\\MenuController', 'addItem']);
        $router->post('/menus/{id}/items/bulk', ['App\\Http\\Controllers\\Admin\\MenuController', 'addItems']);
        $router->post('/menus/{id}/items/reorder', ['App\\Http\\Controllers\\Admin\\MenuController', 'reorderItems']);
        $router->post('/menus/{id}/items/{itemId}/delete', ['App\\Http\\Controllers\\Admin\\MenuController', 'removeItem']);
        $router->post('/menus/{id}/items/{itemId}', ['App\\Http\\Controllers\\Admin\\MenuController', 'updateItem']);
        $router->post('/menu-items/{id}/delete', ['App\\Http\\Controllers\\Admin\\MenuController', 'destroyItem']);

        // Customizer
        $router->get ('/customize',       ['App\\Http\\Controllers\\Admin\\CustomizerController', 'index']);
        $router->post('/customize/save',  ['App\\Http\\Controllers\\Admin\\CustomizerController', 'save']);
        $router->post('/customize/draft', ['App\\Http\\Controllers\\Admin\\CustomizerController', 'draft']);

        // Settings
        $router->get('/settings', ['App\\Http\\Controllers\\Admin\\SettingController', 'general']);
        $router->get('/settings/general', ['App\\Http\\Controllers\\Admin\\SettingController', 'general']);
        $router->post('/settings/general', ['App\\Http\\Controllers\\Admin\\SettingController', 'saveGeneral']);
        $router->get('/settings/reading', ['App\\Http\\Controllers\\Admin\\SettingController', 'reading']);
        $router->post('/settings/reading', ['App\\Http\\Controllers\\Admin\\SettingController', 'saveReading']);
        $router->get('/settings/writing', ['App\\Http\\Controllers\\Admin\\SettingController', 'writing']);
        $router->post('/settings/writing', ['App\\Http\\Controllers\\Admin\\SettingController', 'saveWriting']);
        $router->get('/settings/discussion', ['App\\Http\\Controllers\\Admin\\SettingController', 'discussion']);
        $router->post('/settings/discussion', ['App\\Http\\Controllers\\Admin\\SettingController', 'saveDiscussion']);
        $router->get('/settings/seo', ['App\\Http\\Controllers\\Admin\\SettingController', 'seo']);
        $router->post('/settings/seo', ['App\\Http\\Controllers\\Admin\\SettingController', 'saveSeo']);
        // Appearance moved into the Customizer. The route stays as a redirect
        // rather than a 404, because it is the sort of URL people bookmark.
        $router->get('/settings/appearance', function () {
            $base = defined('BASEHIM_BASE') ? BASEHIM_BASE : '';
            $r = new \App\Core\Response('', 302);
            $r->header('Location', $base . '/admin/customize');
            return $r;
        });
        $router->get('/settings/email', ['App\\Http\\Controllers\\Admin\\SettingController', 'email']);
        $router->get('/settings/authorization', ['App\\Http\\Controllers\\Admin\\SettingController', 'authorization']);
        $router->post('/settings/authorization', ['App\\Http\\Controllers\\Admin\\SettingController', 'saveAuthorization']);
        $router->post('/settings/email', ['App\\Http\\Controllers\\Admin\\SettingController', 'saveEmail']);
        $router->post('/settings/email/test', ['App\\Http\\Controllers\\Admin\\SettingController', 'testEmail']);
        $router->post('/settings/appearance', ['App\\Http\\Controllers\\Admin\\SettingController', 'saveAppearance']);
        $router->get('/settings/permalinks', ['App\\Http\\Controllers\\Admin\\SettingController', 'permalinks']);
        $router->post('/settings/permalinks', ['App\\Http\\Controllers\\Admin\\SettingController', 'savePermalinks']);
        $router->get('/settings/media', ['App\\Http\\Controllers\\Admin\\SettingController', 'media']);
        $router->post('/settings/media/regenerate', ['App\\Http\\Controllers\\Admin\\SettingController', 'regenerateThumbnails']);
        $router->post('/settings/media', ['App\\Http\\Controllers\\Admin\\SettingController', 'saveMedia']);

        // API Management
        $router->get('/api', ['App\\Http\\Controllers\\Admin\\ApiController', 'overview']);
        $router->get('/api/keys', ['App\\Http\\Controllers\\Admin\\ApiController', 'keys']);
        $router->post('/api/keys', ['App\\Http\\Controllers\\Admin\\ApiController', 'createKey']);
        $router->post('/api/keys/{id}/revoke', ['App\\Http\\Controllers\\Admin\\ApiController', 'revokeKey']);
        $router->post('/api/keys/{id}/delete', ['App\\Http\\Controllers\\Admin\\ApiController', 'deleteKey']);
        $router->get('/api/reference', ['App\\Http\\Controllers\\Admin\\ApiController', 'reference']);
        $router->get('/api/mcp', ['App\\Http\\Controllers\\Admin\\ApiController', 'mcp']);

        // Widgets
        $router->get('/widgets', ['App\\Http\\Controllers\\Admin\\WidgetController', 'index']);
        $router->get('/widgets/list.json', ['App\\Http\\Controllers\\Admin\\WidgetController', 'list']);
        $router->post('/widgets/render', ['App\\Http\\Controllers\\Admin\\WidgetController', 'render']);
        // Widget areas (sidebars). Literal sub-paths are registered before the
        // {itemId} catch so 'add'/'reorder' are never read as an item id.
        $router->get('/widgets/areas', ['App\\Http\\Controllers\\Admin\\WidgetController', 'areas']);
        $router->post('/widgets/areas/{area}/add', ['App\\Http\\Controllers\\Admin\\WidgetController', 'areaAdd']);
        $router->post('/widgets/areas/{area}/reorder', ['App\\Http\\Controllers\\Admin\\WidgetController', 'areaReorder']);
        $router->post('/widgets/areas/{area}/{itemId}/remove', ['App\\Http\\Controllers\\Admin\\WidgetController', 'areaRemove']);
        $router->post('/widgets/areas/{area}/{itemId}/move', ['App\\Http\\Controllers\\Admin\\WidgetController', 'areaMove']);
        $router->post('/widgets/areas/{area}/{itemId}', ['App\\Http\\Controllers\\Admin\\WidgetController', 'areaUpdate']);
        // ---- Apps ----
        $router->get('/apps', ['App\\Http\\Controllers\\Admin\\AppController', 'index']);
        $router->post('/apps/install', ['App\\Http\\Controllers\\Admin\\AppController', 'install']);
        $router->get('/apps/marketplace', ['App\\Http\\Controllers\\Admin\\AppController', 'marketplace']);
        $router->get('/apps/marketplace/browse.json', ['App\\Http\\Controllers\\Admin\\AppController', 'marketplaceBrowse']);
        $router->get('/apps/marketplace/facets.json', ['App\\Http\\Controllers\\Admin\\AppController', 'marketplaceFacets']);
        $router->post('/apps/marketplace/install', ['App\\Http\\Controllers\\Admin\\AppController', 'marketplaceInstall']);
        $router->post('/apps/upgrade/apply', ['App\\Http\\Controllers\\Admin\\AppController', 'upgradeApply']);
        $router->post('/apps/upgrade/cancel', ['App\\Http\\Controllers\\Admin\\AppController', 'upgradeCancel']);
        $router->post('/apps/{slug}/activate', ['App\\Http\\Controllers\\Admin\\AppController', 'activate']);
        $router->post('/apps/{slug}/deactivate', ['App\\Http\\Controllers\\Admin\\AppController', 'deactivate']);
        $router->post('/apps/{slug}/uninstall', ['App\\Http\\Controllers\\Admin\\AppController', 'uninstall']);
        $router->post('/apps/{slug}/delete', ['App\\Http\\Controllers\\Admin\\AppController', 'delete']);
        $router->get('/apps/{slug}/consent', ['App\\Http\\Controllers\\Admin\\AppController', 'consent']);
        $router->post('/apps/{slug}/consent', ['App\\Http\\Controllers\\Admin\\AppController', 'saveConsent']);
        $router->get('/apps/{slug}/logs', ['App\\Http\\Controllers\\Admin\\AppController', 'logs']);
        $router->post('/apps/{slug}/rescan', ['App\\Http\\Controllers\\Admin\\AppController', 'rescan']);


        // Themes
        $router->get('/themes', ['App\\Http\\Controllers\\Admin\\ThemeController', 'index']);
        $router->post('/themes/install', ['App\\Http\\Controllers\\Admin\\ThemeController', 'install']);
        $router->get('/themes/marketplace', ['App\\Http\\Controllers\\Admin\\ThemeController', 'marketplace']);
        $router->get('/themes/marketplace/browse.json', ['App\\Http\\Controllers\\Admin\\ThemeController', 'marketplaceBrowse']);
        $router->get('/themes/marketplace/facets.json', ['App\\Http\\Controllers\\Admin\\ThemeController', 'marketplaceFacets']);
        $router->post('/themes/marketplace/install', ['App\\Http\\Controllers\\Admin\\ThemeController', 'marketplaceInstall']);
        $router->post('/themes/{slug}/activate', ['App\\Http\\Controllers\\Admin\\ThemeController', 'activate']);
        $router->post('/themes/{slug}/delete', ['App\\Http\\Controllers\\Admin\\ThemeController', 'delete']);

    });
});
