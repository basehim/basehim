<?php
/**
 * Basehim — Front Controller
 *
 * Single entry point for the application. cPanel-friendly: no public/ directory.
 * All requests are routed here via .htaccess and dispatched by the Router.
 */

declare(strict_types=1);

define('BASEHIM_START', microtime(true));
define('BASEHIM_ROOT', __DIR__);
define('BASEHIM_VERSION', '1.2.1');

/*
 * Detect the URL prefix where Basehim is installed.
 * - Document root install  → ''
 * - Subdirectory install   → '/basehim' (no trailing slash)
 *
 * Apache rewrites everything to index.php, so SCRIPT_NAME tells us where
 * index.php lives relative to the domain root. We strip the trailing
 * '/index.php' to get the base path.
 */
$scriptName = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
$basePath = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
if ($basePath === '.' || $basePath === '/') {
    $basePath = '';
}
define('BASEHIM_BASE', $basePath); // '' for root install, '/basehim' otherwise


// Bootstrap the application
require __DIR__ . '/bootstrap.php';

// Resolve container & router
$app = \App\Core\Application::getInstance();
$router = $app->make(\App\Core\Router::class);

// Load route files — specific prefixes first, web catch-all last
require __DIR__ . '/routes/api.php';
require __DIR__ . '/routes/admin.php';
require __DIR__ . '/routes/web.php';

// Dispatch
try {
    $router->dispatch();
} catch (\Throwable $e) {
    \App\Core\ErrorHandler::handle($e);
}
