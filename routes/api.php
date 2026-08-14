<?php
/**
 * REST API v1 routes
 * All under /api/v1 prefix
 */

/** @var \App\Core\Router $router */
$router = $app->make('router');

$router->group(['prefix' => '/api/v1', 'middleware' => ['App\\Http\\Middleware\\Cors']], function ($router) {

    // ---- Auth ----
    $router->post('/auth/login', ['App\\Http\\Controllers\\Api\\AuthController', 'login']);
    $router->post('/auth/refresh', ['App\\Http\\Controllers\\Api\\AuthController', 'refresh']);
    $router->post('/auth/logout', ['App\\Http\\Controllers\\Api\\AuthController', 'logout']);
    $router->post('/auth/register', ['App\\Http\\Controllers\\Api\\AuthController', 'register']);

    // ---- Public reads (no auth) ----
    $router->get('/posts', ['App\\Http\\Controllers\\Api\\PostController', 'index']);
    $router->get('/posts/{slug}', ['App\\Http\\Controllers\\Api\\PostController', 'show']);
    $router->get('/pages', ['App\\Http\\Controllers\\Api\\PageController', 'index']);
    $router->get('/pages/{slug}', ['App\\Http\\Controllers\\Api\\PageController', 'show']);
    $router->get('/taxonomies', ['App\\Http\\Controllers\\Api\\TaxonomyController', 'taxonomies']);
    $router->get('/taxonomies/{taxonomy}/terms', ['App\\Http\\Controllers\\Api\\TaxonomyController', 'terms']);
    $router->get('/menus/{slug}', ['App\\Http\\Controllers\\Api\\MenuController', 'show']);
    $router->get('/widget-areas', ['App\\Http\\Controllers\\Api\\WidgetController', 'areas']);
    $router->get('/widget-areas/{area}', ['App\\Http\\Controllers\\Api\\WidgetController', 'area']);
    $router->get('/settings/public', ['App\\Http\\Controllers\\Api\\SettingController', 'publicSettings']);
    $router->get('/search', ['App\\Http\\Controllers\\Api\\SearchController', 'index']);

    // ---- Comments (public can read & submit) ----
    $router->get('/posts/{slug}/comments', ['App\\Http\\Controllers\\Api\\CommentController', 'index']);
    $router->post('/posts/{slug}/comments', ['App\\Http\\Controllers\\Api\\CommentController', 'store']);

    // ---- Scheduler cron entry point --------------------------------------
    // Outside the authenticated group on purpose: a crontab sends no cookies
    // and holds no JWT. Guarded by an unguessable token instead.
    $router->get('/schedule/run', ['App\\Http\\Controllers\\Api\\ScheduleController', 'run']);

    // ---- Authenticated routes ----
    $router->group(['middleware' => ['App\\Http\\Middleware\\Authenticate:api']], function ($router) {

        $router->get('/me', ['App\\Http\\Controllers\\Api\\AuthController', 'me']);
        $router->patch('/me', ['App\\Http\\Controllers\\Api\\AuthController', 'updateProfile']);

        // Posts write
        $router->post('/posts', ['App\\Http\\Controllers\\Api\\PostController', 'store']);
        $router->put('/posts/{id}', ['App\\Http\\Controllers\\Api\\PostController', 'update']);
        $router->patch('/posts/{id}', ['App\\Http\\Controllers\\Api\\PostController', 'update']);
        $router->delete('/posts/{id}', ['App\\Http\\Controllers\\Api\\PostController', 'destroy']);

        // Pages write
        $router->post('/pages', ['App\\Http\\Controllers\\Api\\PageController', 'store']);
        $router->put('/pages/{id}', ['App\\Http\\Controllers\\Api\\PageController', 'update']);
        $router->delete('/pages/{id}', ['App\\Http\\Controllers\\Api\\PageController', 'destroy']);

        // Media
        $router->get('/media', ['App\\Http\\Controllers\\Api\\MediaController', 'index']);
        $router->post('/media', ['App\\Http\\Controllers\\Api\\MediaController', 'upload']);
        $router->delete('/media/{id}', ['App\\Http\\Controllers\\Api\\MediaController', 'destroy']);

        // Users (admin)
        $router->get('/users', ['App\\Http\\Controllers\\Api\\UserController', 'index']);
        $router->get('/users/{id}', ['App\\Http\\Controllers\\Api\\UserController', 'show']);
        $router->post('/users', ['App\\Http\\Controllers\\Api\\UserController', 'store']);
        $router->put('/users/{id}', ['App\\Http\\Controllers\\Api\\UserController', 'update']);
        $router->delete('/users/{id}', ['App\\Http\\Controllers\\Api\\UserController', 'destroy']);

        // Taxonomies write
        $router->post('/taxonomies/{taxonomy}/terms', ['App\\Http\\Controllers\\Api\\TaxonomyController', 'storeTerm']);
        $router->put('/terms/{id}', ['App\\Http\\Controllers\\Api\\TaxonomyController', 'updateTerm']);
        $router->delete('/terms/{id}', ['App\\Http\\Controllers\\Api\\TaxonomyController', 'destroyTerm']);

        // Settings
        $router->get('/settings', ['App\\Http\\Controllers\\Api\\SettingController', 'index']);
        $router->put('/settings', ['App\\Http\\Controllers\\Api\\SettingController', 'update']);

        // ---- Media metadata ----
        $router->patch('/media/{id}', ['App\\Http\\Controllers\\Api\\MediaController', 'update']);

        // ---- Comment moderation ----
        // Registered before /comments/{id} so the literal path wins the match.
        $router->get('/comments/counts', ['App\\Http\\Controllers\\Api\\CommentController', 'counts']);
        $router->get('/comments', ['App\\Http\\Controllers\\Api\\CommentController', 'all']);
        $router->get('/comments/{id}', ['App\\Http\\Controllers\\Api\\CommentController', 'find']);
        $router->patch('/comments/{id}/status', ['App\\Http\\Controllers\\Api\\CommentController', 'setStatus']);
        $router->delete('/comments/{id}', ['App\\Http\\Controllers\\Api\\CommentController', 'destroy']);

        // ---- Menus ----
        // /menus/{slug} (public, above) reads by SLUG; these manage by ID.
        $router->get('/menus', ['App\\Http\\Controllers\\Api\\MenuController', 'index']);
        $router->post('/menus', ['App\\Http\\Controllers\\Api\\MenuController', 'store']);
        $router->put('/menus/{id}', ['App\\Http\\Controllers\\Api\\MenuController', 'update']);
        $router->delete('/menus/{id}', ['App\\Http\\Controllers\\Api\\MenuController', 'destroy']);
        $router->get('/menus/{id}/items', ['App\\Http\\Controllers\\Api\\MenuController', 'items']);
        $router->post('/menus/{id}/items', ['App\\Http\\Controllers\\Api\\MenuController', 'addItem']);
        $router->put('/menu-items/{id}', ['App\\Http\\Controllers\\Api\\MenuController', 'updateItem']);
        $router->delete('/menu-items/{id}', ['App\\Http\\Controllers\\Api\\MenuController', 'destroyItem']);

        // ---- Apps (read-only; lifecycle actions stay in /admin/apps) ----
        $router->get('/apps', ['App\\Http\\Controllers\\Api\\AppController', 'index']);
        $router->get('/apps/{slug}', ['App\\Http\\Controllers\\Api\\AppController', 'show']);

        // ---- Cache ----
        $router->post('/cache/flush', ['App\\Http\\Controllers\\Api\\CacheController', 'flush']);

        // ---- Scheduled tasks ----
        $router->get('/schedule', ['App\\Http\\Controllers\\Api\\ScheduleController', 'index']);
        $router->post('/schedule/{app}/{key}/run', ['App\\Http\\Controllers\\Api\\ScheduleController', 'runTask']);
    });

});

// ---- OAuth 2.1 for the MCP server ---------------------------------------
// Claude's remote-connector UI only supports OAuth (there is no bearer-token
// field), and the MCP auth spec requires it. Dynamic Client Registration means
// a user only has to paste the /mcp URL — no client id/secret to configure.
$router->group(['middleware' => ['App\\Http\\Middleware\\Cors']], function ($router) {
    // Discovery (RFC 9728 / RFC 8414). The path-suffixed variant is what clients
    // derive from a resource URL of {base}/mcp.
    $router->get('/.well-known/oauth-protected-resource',     ['App\\Http\\Controllers\\Api\\OAuthController', 'protectedResource']);
    $router->get('/.well-known/oauth-protected-resource/mcp', ['App\\Http\\Controllers\\Api\\OAuthController', 'protectedResource']);
    $router->get('/.well-known/oauth-authorization-server',   ['App\\Http\\Controllers\\Api\\OAuthController', 'authorizationServer']);
    $router->get('/.well-known/openid-configuration',         ['App\\Http\\Controllers\\Api\\OAuthController', 'authorizationServer']);

    $router->post('/oauth/register',  ['App\\Http\\Controllers\\Api\\OAuthController', 'register']);
    $router->post('/oauth/token',     ['App\\Http\\Controllers\\Api\\OAuthController', 'token']);
});
// Consent screen — browser flow, so no CORS middleware.
$router->get('/oauth/authorize',  ['App\\Http\\Controllers\\Api\\OAuthController', 'showAuthorize']);
$router->post('/oauth/authorize', ['App\\Http\\Controllers\\Api\\OAuthController', 'authorize']);

// ---- MCP server (Model Context Protocol over JSON-RPC 2.0) ---------------
// A single endpoint AI assistants (e.g. Claude) connect to. It authenticates
// with the site's own API keys (Authorization: Bearer basehim_...), so it lives
// outside /api/v1 at a clean /mcp path. CORS is applied; auth is per-request
// inside the controller (the protocol needs to answer `initialize` before a
// client proves scopes).
$router->group(['middleware' => ['App\\Http\\Middleware\\Cors']], function ($router) {
    $router->post('/mcp', ['App\\Http\\Controllers\\Api\\McpController', 'handle']);
    $router->get('/mcp',  ['App\\Http\\Controllers\\Api\\McpController', 'handle']);
});
