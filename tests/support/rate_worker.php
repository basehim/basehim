<?php

/**
 * One competing client for the RateLimiter concurrency test.
 *
 *   php rate_worker.php <env-file> <bucket> <hits> <max>
 *
 * Opens its own database connection (as a separate PHP worker would), counts
 * <hits> requests against the shared bucket and prints how many were
 * allowed, as JSON.
 */

declare(strict_types=1);

// Command line only. On Apache/LiteSpeed an existing .php file is run directly,
// so without this a browser could start the test runner on a live site.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

[$_, $envFile, $bucket, $hits, $max] = $argv + [null, '', '', '0', '0'];
$root = dirname(__DIR__, 2);
define('BASEHIM_ROOT', $root);

spl_autoload_register(static function (string $class) use ($root): void {
    if (str_starts_with($class, 'App\\')) {
        $file = $root . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($file)) require $file;
    }
});

\App\Core\Env::load($envFile);
$e = fn(string $k) => \App\Core\Env::get($k);
$db = new \App\Core\Database([
    'driver' => 'mysql', 'host' => $e('DB_HOST'), 'port' => (int) $e('DB_PORT'), 'database' => $e('DB_DATABASE'),
    'username' => $e('DB_USERNAME'), 'password' => (string) $e('DB_PASSWORD'), 'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci', 'prefix' => (string) $e('DB_PREFIX'),
]);
$limiter = new \App\Services\RateLimiter($db, new \App\Core\Logger(sys_get_temp_dir()));

$allowed = 0;
$errors = 0;
$counts = [];
for ($i = 0; $i < (int) $hits; $i++) {
    $r = $limiter->hit($bucket, 'shared-client', (int) $max, 3600);
    if ($r['error']) $errors++;
    elseif ($r['allowed']) $allowed++;
    $counts[] = $r['count'];
}
echo json_encode(['allowed' => $allowed, 'errors' => $errors, 'counts' => $counts]);
