<?php
/**
 * Public-facing web routes
 * Renders content via active theme
 */

/** @var \App\Core\Router $router */
$router = $app->make('router');

// Homepage
$router->get('/', ['App\\Http\\Controllers\\Web\\HomeController', 'index']);

// Single post
$router->get('/posts/{slug}', ['App\\Http\\Controllers\\Web\\PostController', 'show']);

// Static page
$router->get('/page/{slug}', ['App\\Http\\Controllers\\Web\\PageController', 'show']);

// Taxonomy archives
$router->get('/category/{slug}', ['App\\Http\\Controllers\\Web\\TaxonomyController', 'category']);
$router->get('/tag/{slug}', ['App\\Http\\Controllers\\Web\\TaxonomyController', 'tag']);

// Author archive
$router->get('/author/{username}', ['App\\Http\\Controllers\\Web\\AuthorController', 'show']);

// Search
$router->get('/search', ['App\\Http\\Controllers\\Web\\SearchController', 'index']);

// Content-Security-Policy violation reports from browsers (1.2.90, Settings → Security)
$router->post('/csp-report', ['App\\Http\\Controllers\\Web\\CspReportController', 'store']);

// Submit comment (CSRF protected)
$router->post('/comments', ['App\\Http\\Controllers\\Web\\CommentController', 'store']);

// Sitemap & RSS feed
$router->get('/sitemap.xml', ['App\\Http\\Controllers\\Web\\SitemapController', 'index']);
$router->get('/sitemap-{name}.xml', ['App\\Http\\Controllers\\Web\\SitemapController', 'part']);
$router->get('/feed', ['App\\Http\\Controllers\\Web\\FeedController', 'rss']);

// AI agents and crawlers (Settings → AI Agents)
$router->get('/robots.txt', ['App\\Http\\Controllers\\Web\\AiAccessController', 'robots']);
$router->get('/llms.txt', ['App\\Http\\Controllers\\Web\\AiAccessController', 'llms']);
$router->get('/llms-full.txt', ['App\\Http\\Controllers\\Web\\AiAccessController', 'llmsFull']);
$router->get('/.well-known/ard.json', ['App\\Http\\Controllers\\Web\\AiAccessController', 'catalog']);
$router->get('/.well-known/ai-catalog.json', ['App\\Http\\Controllers\\Web\\AiAccessController', 'catalog']);
$router->get('/ai/recent-articles.json', ['App\\Http\\Controllers\\Web\\AiAccessController', 'recent']);

// Serve uploaded media via PHP (fallback for hosts that block /storage/uploads)
// URL: /uploads/{path}  →  storage/uploads/{path}
$router->get('/uploads/{path:*}', ['App\\Http\\Controllers\\Web\\UploadController', 'serve']);

// Serve app-bundled assets (CSS/JS/images/fonts shipped inside an app).
// URL: /content/apps/{slug}/assets/{path}  →  content/apps/{slug}/assets/{path}
$router->get('/content/apps/{slug}/assets/{path:*}', ['App\\Http\\Controllers\\Web\\AppAssetController', 'serve']);

// App-registered content types (1.2.94): /events/summer-meetup and the
// archive /events. Registered here, after apps have booted, so every public
// type with a URL prefix gets its two routes. They are content routes: tried
// after apps' own routes, so an app that already served /events itself keeps
// it (or registers its type with 'rewrite' => false).
foreach ($app->make(\App\Services\PostTypeRegistry::class)->custom() as $__type) {
    if (empty($__type['public']) || ($__type['rewrite'] ?? '') === '') continue;
    if (!empty($__type['has_archive'])) {
        $router->content('/' . $__type['rewrite'], ['App\\Http\\Controllers\\Web\\ContentTypeController', 'archive']);
    }
    $router->content('/' . $__type['rewrite'] . '/{slug}', ['App\\Http\\Controllers\\Web\\ContentTypeController', 'show']);
}
// Taxonomies apps register with a public URL prefix (1.2.94): /event-types/workshops.
foreach ($app->make(\App\Services\TaxonomyRegistry::class)->custom() as $__tax) {
    if (empty($__tax['public']) || ($__tax['rewrite'] ?? '') === '') continue;
    $router->content('/' . $__tax['rewrite'] . '/{slug}', ['App\\Http\\Controllers\\Web\\TaxonomyController', 'custom']);
}
unset($__type, $__tax);

// Category/post-name permalink: /{category-slug}/{post-slug}
// Only active when permalinks.structure = 'category', but we register the route
// always so the Router doesn't 404 before ResolveController can decide.
$router->get('/{category}/{slug}', ['App\\Http\\Controllers\\Web\\ResolveController', 'showCategoryPost']);

// Generic catch-all for pages by slug (must be last) — resolves to page or post
// depending on /permalinks/structure setting.
$router->get('/{slug}', ['App\\Http\\Controllers\\Web\\ResolveController', 'show']);
