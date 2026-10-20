<?php

/**
 * Basehim test runner. See tests/README.md.
 *
 *   php tests/run.php                 unit tests; integration too when a test DB is configured
 *   php tests/run.php --filter=csrf   only tests whose name contains "csrf"
 *   php tests/run.php --keep          keep the test database and logs afterwards
 *
 * Integration tests need a MySQL-compatible server and these variables:
 *
 *   BASEHIM_TEST_DB_HOST   (required to enable integration tests)
 *   BASEHIM_TEST_DB_PORT   default 3306
 *   BASEHIM_TEST_DB_USER   default root
 *   BASEHIM_TEST_DB_PASS   default ''
 *   BASEHIM_TEST_DB_NAME   default basehim_test — must end in "_test"; it is DROPPED and recreated
 *   BASEHIM_TEST_DB_PREFIX default bht_ (exercises table prefixing)
 */

declare(strict_types=1);

// Command line only. On Apache/LiteSpeed an existing .php file is run directly,
// so without this a browser could start the test runner on a live site.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
define('BASEHIM_START', microtime(true));
define('BASEHIM_ROOT', $root);
define('BASEHIM_BASE', '');
preg_match("/define\('BASEHIM_VERSION', '([^']+)'\)/", (string) file_get_contents($root . '/index.php'), $vm);
define('BASEHIM_VERSION', $vm[1] ?? '0.0.0');

// Our own loader for App\ until bootstrap.php registers Basehim's. It never
// loads App\Core\Autoloader itself, so bootstrap.php can still declare it.
spl_autoload_register(static function (string $class) use ($root): void {
    $map = ['App\\' => '/app/', 'BasehimTests\\Support\\' => '/tests/support/'];
    foreach ($map as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $file = $root . $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) require $file;
            return;
        }
    }
});

require __DIR__ . '/support/harness.php';

$args = array_slice($argv, 1);
$filter = '';
$keep = in_array('--keep', $args, true);
foreach ($args as $a) {
    if (str_starts_with($a, '--filter=')) $filter = strtolower(substr($a, 9));
}

// ---------------------------------------------------------------------------
// Environment
// ---------------------------------------------------------------------------

$dbHost = (string) getenv('BASEHIM_TEST_DB_HOST');
$integration = $dbHost !== '';
$db = [
    'host'   => $dbHost ?: '127.0.0.1',
    'port'   => (int) (getenv('BASEHIM_TEST_DB_PORT') ?: 3306),
    'user'   => (string) (getenv('BASEHIM_TEST_DB_USER') ?: 'root'),
    'pass'   => (string) (getenv('BASEHIM_TEST_DB_PASS') ?: ''),
    'name'   => (string) (getenv('BASEHIM_TEST_DB_NAME') ?: 'basehim_test'),
    'prefix' => (string) (getenv('BASEHIM_TEST_DB_PREFIX') !== false ? getenv('BASEHIM_TEST_DB_PREFIX') : 'bht_'),
];
if ($integration && !preg_match('/^[A-Za-z0-9_]+_test$/', $db['name'])) {
    fwrite(STDERR, "BASEHIM_TEST_DB_NAME must end in _test: the database is dropped and recreated.\n");
    exit(2);
}

$tmp = sys_get_temp_dir() . '/basehim-tests-' . getmypid();
@mkdir($tmp, 0700, true);

$sock = stream_socket_server('tcp://127.0.0.1:0');
$port = (int) explode(':', stream_socket_get_name($sock, false))[1];
fclose($sock);
$baseUrl = "http://127.0.0.1:{$port}";

$envFile = $tmp . '/test.env';
file_put_contents($envFile, implode("\n", [
    'APP_ENV=testing',
    'APP_DEBUG=false',
    'APP_URL=' . $baseUrl,
    'APP_KEY=' . bin2hex(random_bytes(32)),
    'JWT_SECRET=' . bin2hex(random_bytes(32)),
    'DB_DRIVER=mysql',
    'DB_HOST=' . $db['host'],
    'DB_PORT=' . $db['port'],
    'DB_DATABASE=' . $db['name'],
    'DB_USERNAME=' . $db['user'],
    'DB_PASSWORD="' . $db['pass'] . '"',
    'DB_CHARSET=utf8mb4',
    'DB_COLLATION=utf8mb4_unicode_ci',
    'DB_PREFIX=' . $db['prefix'],
    // High enough not to interfere; the global limit has its own test.
    'AJAX_RATE_LIMIT=5000',
    // App fault-isolation reports, kept apart from a local site's (1.2.71).
    'APP_HEALTH_DIR=' . $tmp . '/app-health',
    '',
]));
\App\Core\Env::load($envFile);

$GLOBALS['__bh_env'] = ['root' => $root, 'tmp' => $tmp, 'base_url' => $baseUrl, 'db' => $db, 'env_file' => $envFile];

// ---------------------------------------------------------------------------
// Collect tests
// ---------------------------------------------------------------------------

foreach (['Unit', 'Integration'] as $group) {
    $GLOBALS['__bh_group'] = $group;
    foreach (glob(__DIR__ . "/{$group}/*Test.php") ?: [] as $file) {
        require $file;
    }
}

$tests = array_values(array_filter($GLOBALS['__bh_tests'], fn($t) => $filter === '' || str_contains(strtolower($t['name']), $filter)));

// ---------------------------------------------------------------------------
// Run
// ---------------------------------------------------------------------------

$results = ['pass' => 0, 'fail' => 0, 'skip' => 0];
$failures = [];
$server = null;

$runGroup = function (string $group) use (&$tests, &$results, &$failures): void {
    foreach ($tests as $t) {
        if ($t['group'] !== $group) continue;
        $start = microtime(true);
        try {
            ($t['fn'])();
            $results['pass']++;
            bh_out("  \033[32m✓\033[0m %s \033[90m(%dms)\033[0m\n", $t['name'], (int) ((microtime(true) - $start) * 1000));
        } catch (TestSkipped $e) {
            $results['skip']++;
            bh_out("  \033[33m-\033[0m %s \033[90m(skipped: %s)\033[0m\n", $t['name'], $e->getMessage());
        } catch (\Throwable $e) {
            $results['fail']++;
            $where = $e instanceof AssertionFailed ? '' : ' [' . $e::class . ' at ' . basename($e->getFile()) . ':' . $e->getLine() . ']';
            $failures[] = $t['name'];
            bh_out("  \033[31m✗ %s\033[0m\n      %s%s\n", $t['name'], $e->getMessage(), $where);
        }
    }
};

bh_out("\nUnit\n");
$runGroup('Unit');

bh_out("\nIntegration\n");
if (!$integration) {
    $n = count(array_filter($tests, fn($t) => $t['group'] === 'Integration'));
    $results['skip'] += $n;
    bh_out("  \033[33m-\033[0m {$n} integration tests skipped: set BASEHIM_TEST_DB_HOST (see tests/README.md)\n");
} else {
    require __DIR__ . '/support/environment.php';
    try {
        $server = bh_test_setup($GLOBALS['__bh_env']);
        $runGroup('Integration');
    } catch (\Throwable $e) {
        $results['fail']++;
        $failures[] = 'integration setup';
        bh_out("  \033[31m✗ setup failed: " . $e->getMessage() . "\033[0m\n");
    } finally {
        bh_test_teardown($GLOBALS['__bh_env'], $server, $keep);
    }
}

bh_out(
    "\n%d passed, %d failed, %d skipped\n",
    $results['pass'], $results['fail'], $results['skip']
);
if ($failures !== [] && is_file($tmp . '/server.log')) {
    bh_out("\nLast lines of the test server's output ({$tmp}/server.log):\n");
    $lines = file($tmp . '/server.log') ?: [];
    bh_out(implode('', array_slice($lines, -15)));
}
exit($results['fail'] > 0 ? 1 : 0);
