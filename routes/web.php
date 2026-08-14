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

// Submit comment (CSRF protected)
$router->post('/comments', ['App\\Http\\Controllers\\Web\\CommentController', 'store']);

// Sitemap & RSS feed
$router->get('/sitemap.xml', ['App\\Http\\Controllers\\Web\\SitemapController', 'index']);
$router->get('/feed', ['App\\Http\\Controllers\\Web\\FeedController', 'rss']);

// Serve uploaded media via PHP (fallback for hosts that block /storage/uploads)
// URL: /uploads/{path}  →  storage/uploads/{path}
$router->get('/uploads/{path:*}', ['App\\Http\\Controllers\\Web\\UploadController', 'serve']);

// Serve app-bundled assets (CSS/JS/images/fonts shipped inside an app).
// URL: /content/apps/{slug}/assets/{path}  →  content/apps/{slug}/assets/{path}
$router->get('/content/apps/{slug}/assets/{path:*}', ['App\\Http\\Controllers\\Web\\AppAssetController', 'serve']);

// Category/post-name permalink: /{category-slug}/{post-slug}
// Only active when permalinks.structure = 'category', but we register the route
// always so the Router doesn't 404 before ResolveController can decide.
$router->get('/{category}/{slug}', ['App\\Http\\Controllers\\Web\\ResolveController', 'showCategoryPost']);

// Generic catch-all for pages by slug (must be last) — resolves to page or post
// depending on /permalinks/structure setting.
$router->get('/{slug}', ['App\\Http\\Controllers\\Web\\ResolveController', 'show']);
