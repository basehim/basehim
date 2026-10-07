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
define('BASEHIM_VERSION', '1.2.35');

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

/*
 * Not installed yet: send the visitor to the installer.
 *
 * A fresh copy has no .env until install.php writes one. Without this the very
 * first page anyone opened went straight on to the database, which is not
 * configured yet, and answered with a bare "500 Internal Server Error" — while
 * the README promised a redirect to the installer.
 *
 * Keyed to the file being ABSENT, never to its contents, so an installed site
 * can't be sent here by a hand-edited .env. A host that configures Basehim
 * through real environment variables instead of a .env is left alone too.
 */
if (!is_file(__DIR__ . '/.env') && is_file(__DIR__ . '/install.php') && getenv('DB_DATABASE') === false) {
    $bhPath = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $bhPath = substr($bhPath, strlen($basePath)) ?: '/';
    if (preg_match('#^/(api|mcp|oauth|\.well-known)(/|$)#', $bhPath)) {
        // Machines get an answer they can read, not an HTML redirect.
        http_response_code(503);
        header('Content-Type: application/json');
        header('Retry-After: 3600');
        echo json_encode(['ok' => false, 'error' => 'Basehim is not installed yet. Open /install.php to set it up.']);
        exit;
    }
    header('Location: ' . $basePath . '/install.php', true, 302);
    exit;
}


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
