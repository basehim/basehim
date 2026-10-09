<?php
/**
 * Router for PHP's built-in web server — local development only.
 *
 *     php -S localhost:8000 server.php
 *
 * then open http://localhost:8000 and the installer takes it from there.
 *
 * On a real host Apache does this job with .htaccess. The built-in server reads
 * no .htaccess, so without a router /admin (a directory with no index file)
 * answered 404 and the private folders were served as plain files.
 *
 * Do not use the built-in server in production. On Apache this file does
 * nothing: it answers 404 to anything but the built-in server.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli-server') {
    http_response_code(404);
    exit;
}

$path = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));

// The same private paths .htaccess refuses, plus every dotfile (.env, .git…).
if (preg_match('#^/(app|config|database|routes)(/|$)#', $path)
    || preg_match('#^/storage(/(?!uploads/)|$)#', $path)
    || $path === '/bootstrap.php'
    // Dotfiles stay private, but /.well-known/ is public by standard: OAuth
    // and MCP discovery live there (RFC 8615). Blocking it, as before 1.2.43,
    // made AI connectors fail against a local site. The allowed form has no
    // further dots, so /.well-known/../.env and the like are still refused.
    || (preg_match('#/\.#', $path) && !preg_match('#^/\.well-known/[A-Za-z0-9_\-/]+$#', $path))) {
    http_response_code(403);
    echo 'Forbidden';
    return true;
}

// Real static files (CSS, JS, images, uploads) are served as they are.
if ($path !== '/' && !str_ends_with($path, '.php') && is_file(__DIR__ . $path)) {
    return false;
}

// The installer is a standalone script.
if ($path === '/install.php') {
    $_SERVER['SCRIPT_NAME'] = '/install.php';
    $_SERVER['SCRIPT_FILENAME'] = __DIR__ . '/install.php';
    require __DIR__ . '/install.php';
    return true;
}

// Everything else goes through the front controller, exactly as on Apache.
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = __DIR__ . '/index.php';
require __DIR__ . '/index.php';
return true;
