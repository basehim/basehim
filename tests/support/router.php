<?php

/**
 * Router for the built-in server during integration tests.
 *
 *     BASEHIM_TEST_ENV=/tmp/…/test.env php -S 127.0.0.1:8099 tests/support/router.php
 *
 * Loads the test environment file before index.php does anything, so the
 * site under test talks to the throwaway test database and never reads the
 * developer's own .env (Env::load() is a no-op once something is loaded).
 * Then it hands the request to the real front controller.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli-server') {
    http_response_code(404);
    exit;
}

$envFile = (string) getenv('BASEHIM_TEST_ENV');
if ($envFile === '' || !is_file($envFile)) {
    http_response_code(500);
    echo 'BASEHIM_TEST_ENV is not set';
    return true;
}

$root = dirname(__DIR__, 2);
require_once $root . '/app/Core/Env.php';
\App\Core\Env::load($envFile);
// index.php sends a site with no .env to the installer unless DB_DATABASE is
// in the real environment.
putenv('DB_DATABASE=' . (string) \App\Core\Env::get('DB_DATABASE'));

// Static assets, as server.php does.
$path = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
if ($path !== '/' && !str_ends_with($path, '.php') && is_file($root . $path)
    && preg_match('#^/(admin/assets|content/themes/[^/]+/assets)/#', $path)) {
    return false;
}

$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $root . '/index.php';
require $root . '/index.php';
