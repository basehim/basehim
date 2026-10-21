<?php

declare(strict_types=1);

/**
 * Integration environment: a throwaway database migrated by Basehim's own
 * MigrationService, a few users and media rows, the fixture app installed and
 * active, the application booted in this process (for tests that call
 * services directly), and the real index.php served by PHP's built-in server.
 */

const BH_TEST_PASSWORD = 'Test-password-123';
const BH_FIXTURE_MARKER = '.basehim-test-fixture';

/** @return resource|null the server process */
function bh_test_setup(array &$env)
{
    $root = $env['root'];
    $db = $env['db'];

    // 1. Fresh database ------------------------------------------------------
    $pdo = new PDO("mysql:host={$db['host']};port={$db['port']};charset=utf8mb4", $db['user'], $db['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $pdo->exec("DROP DATABASE IF EXISTS `{$db['name']}`");
    $pdo->exec("CREATE DATABASE `{$db['name']}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo = null;

    $database = new \App\Core\Database([
        'driver' => 'mysql', 'host' => $db['host'], 'port' => $db['port'], 'database' => $db['name'],
        'username' => $db['user'], 'password' => $db['pass'], 'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci', 'prefix' => $db['prefix'],
    ]);
    if ((string) getenv('BASEHIM_TEST_DB_COMPAT') === 'dolt') {
        $env['migrations'] = bh_migrate_compat($db);
    } else {
        $migrated = (new \App\Services\MigrationService($database))->run();
        if ($migrated['error'] !== null) {
            throw new \RuntimeException('Migrations failed: ' . $migrated['error']);
        }
        $env['migrations'] = $migrated['applied'];
    }

    // 2. Seed -----------------------------------------------------------------
    $hash = password_hash(BH_TEST_PASSWORD, PASSWORD_BCRYPT, ['cost' => 4]);
    $users = [
        'admin'      => 'admin',
        'author'     => 'author',
        'author2'    => 'author',
        'editor'     => 'editor',
        'contributor' => 'contributor',
        'subscriber' => 'subscriber',
    ];
    $env['users'] = [];
    foreach ($users as $name => $role) {
        $env['users'][$name] = (int) $database->insert('users', [
            'uuid' => bh_uuid(), 'username' => $name, 'email' => "{$name}@example.test",
            'password_hash' => $hash, 'display_name' => ucfirst($name), 'role' => $role, 'status' => 'active',
        ]);
    }
    $env['media'] = [];
    foreach (['admin', 'author'] as $owner) {
        $env['media'][$owner] = (int) $database->insert('media', [
            'uuid' => bh_uuid(), 'author_id' => $env['users'][$owner], 'title' => "{$owner} file",
            'alt_text' => 'original', 'mime_type' => 'image/png', 'file_name' => "{$owner}.png",
            'original_name' => "{$owner}.png", 'file_size' => 10,
            'storage_path' => "tests/{$owner}.png", 'url' => "/uploads/tests/{$owner}.png",
        ]);
    }
    $env['database'] = $database;

    // 3. Fixture app ------------------------------------------------------------
    $dest = $root . '/content/apps/ajax-fixture';
    if (is_dir($dest) && !is_file($dest . '/' . BH_FIXTURE_MARKER)) {
        throw new \RuntimeException("{$dest} exists and is not the test fixture; refusing to overwrite it.");
    }
    bh_rrmdir($dest);
    bh_copy_dir($root . '/tests/fixtures/apps/ajax-fixture', $dest);
    file_put_contents($dest . '/' . BH_FIXTURE_MARKER, "Installed by tests/run.php; removed afterwards.\n");
    $database->insert('apps', [
        'slug' => 'ajax-fixture', 'name' => 'AJAX Fixture', 'version' => '1.0.0', 'status' => 'active',
    ]);

    // 4. Boot the application in this process --------------------------------
    // No cookies or cache headers from a CLI session.
    ini_set('session.use_cookies', '0');
    ini_set('session.cache_limiter', '');
    require $root . '/bootstrap.php';
    // Core's AJAX route and actions, for tests that run the gateway in-process.
    (static function (string $root): void {
        $app = \App\Core\Application::getInstance();
        require $root . '/routes/ajax.php';
    })($root);

    // 5. Serve index.php ---------------------------------------------------------
    $log = $env['tmp'] . '/server.log';
    $proc = proc_open(
        [PHP_BINARY, '-S', parse_url($env['base_url'], PHP_URL_HOST) . ':' . parse_url($env['base_url'], PHP_URL_PORT), $root . '/tests/support/router.php'],
        [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
        $pipes,
        $root,
        array_merge(getenv(), ['BASEHIM_TEST_ENV' => $env['env_file'], 'PHP_CLI_SERVER_WORKERS' => '8'])
    );
    if (!is_resource($proc)) {
        throw new \RuntimeException('Could not start the test server.');
    }
    $deadline = microtime(true) + 15;
    while (true) {
        $fp = @fsockopen('127.0.0.1', (int) parse_url($env['base_url'], PHP_URL_PORT), $errno, $errstr, 0.2);
        if ($fp) { fclose($fp); break; }
        if (microtime(true) > $deadline) throw new \RuntimeException("Test server did not start; see {$log}");
        usleep(100000);
    }
    bh_out("  \033[90m(server %s, database %s, %d migrations)\033[0m\n", $env['base_url'], $db['name'], count($env['migrations']));
    return $proc;
}

/**
 * Test-only migration path for MySQL-compatible servers that lack two things
 * Basehim's migrations rely on (BASEHIM_TEST_DB_COMPAT=dolt). Real MySQL and
 * MariaDB never take this path; they run MigrationService unchanged.
 *
 *  - Conditional DDL written as  SET @v = (SELECT …);  SET @sql = IF(cond,
 *    'ALTER …', 'DO 0');  PREPARE/EXECUTE  — evaluated here in PHP instead.
 *  - ADD COLUMN IF NOT EXISTS (MariaDB syntax) — the column is checked first.
 *
 * Statements are split with MigrationService::statements(), as the real
 * runner does, and applied migrations are recorded the same way ($record =
 * false runs them again without recording, for the run-twice test).
 *
 * @return string[] applied migration keys
 */
function bh_migrate_compat(array $db, bool $record = true): array
{
    $connect = function () use ($db): PDO {
        return new PDO("mysql:host={$db['host']};port={$db['port']};dbname={$db['name']};charset=utf8mb4", $db['user'], $db['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
        ]);
    };
    $pdo = $connect();
    $px = fn(string $sql) => \App\Core\Database::applyPrefix($sql, $db['prefix']);
    $pdo->exec($px('CREATE TABLE IF NOT EXISTS {migrations} (`id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, `migration` VARCHAR(255) NOT NULL, `applied_at` DATETIME NOT NULL)'));

    $files = glob(BASEHIM_ROOT . '/database/migrations/*.sql') ?: [];
    sort($files);
    $applied = [];
    foreach ($files as $file) {
        $vars = [];
        foreach (\App\Services\MigrationService::statements((string) file_get_contents($file)) as $i => $st) {
            $sql = trim($px($st));
            try {
                if (preg_match('/^SET\s+@(\w+)\s*:?=\s*\((.*)\)$/si', $sql, $m)) {
                    $vars[$m[1]] = (int) $pdo->query($m[2])->fetchColumn();
                } elseif (preg_match("/^SET\s+@(\w+)\s*:?=\s*IF\((.*?),\s*'((?:[^']|'')*)'\s*,\s*'((?:[^']|'')*)'\s*\)$/si", $sql, $m)) {
                    $cond = preg_replace_callback('/@(\w+)/', fn($v) => (string) ($vars[$v[1]] ?? 0), $m[2]);
                    $true = (int) $pdo->query('SELECT IF(' . $cond . ', 1, 0)')->fetchColumn();
                    $vars[$m[1]] = str_replace("''", "'", $true ? $m[3] : $m[4]);
                } elseif (preg_match('/^PREPARE\s+\w+\s+FROM\s+@(\w+)/i', $sql, $m)) {
                    $dyn = trim((string) ($vars[$m[1]] ?? ''));
                    if ($dyn !== '' && strcasecmp($dyn, 'DO 0') !== 0) $pdo->exec($dyn);
                } elseif (preg_match('/^(EXECUTE|DEALLOCATE)\b/i', $sql)) {
                    // handled with PREPARE
                } elseif (preg_match('/^ALTER\s+TABLE\s+(`?)(\w+)\1\s+ADD\s+COLUMN\s+IF\s+NOT\s+EXISTS\s+`?(\w+)`?/i', $sql, $m)) {
                    $has = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = " . $pdo->quote($m[2]) . " AND COLUMN_NAME = " . $pdo->quote($m[3]))->fetchColumn();
                    if ($has === 0) $pdo->exec(preg_replace('/\s+IF\s+NOT\s+EXISTS\s+/i', ' ', $sql, 1));
                } else {
                    try {
                        $pdo->exec($sql);
                    } catch (\PDOException $e) {
                        // Dolt cannot resolve a correlated subquery in an aliased
                        // single-table DELETE; the un-aliased form is equivalent.
                        if (!preg_match('/^DELETE\s+(\w+)\s+FROM\s+(`?\w+`?)\s+\1\s+(WHERE\s.*)$/si', $sql, $d)) throw $e;
                        $pdo = $connect();
                        $pdo->exec('DELETE FROM ' . $d[2] . ' ' . preg_replace('/\b' . $d[1] . '\./', $d[2] . '.', $d[3]));
                    }
                }
            } catch (\Throwable $e) {
                throw new \RuntimeException(basename($file) . ' #' . ($i + 1) . ' (compat): ' . $e->getMessage());
            }
        }
        $key = \App\Services\MigrationService::key($file);
        if ($record) {
            $pdo->prepare($px('INSERT INTO {migrations} (migration, applied_at) VALUES (?, ?)'))->execute([$key, gmdate('Y-m-d H:i:s')]);
        }
        $applied[] = $key;
    }
    return $applied;
}

function bh_test_teardown(array $env, $proc, bool $keep): void
{
    if (is_resource($proc)) {
        $status = proc_get_status($proc);
        // The server forks workers; signal the whole group it leads.
        if (!empty($status['pid']) && function_exists('posix_kill')) {
            @posix_kill($status['pid'], 15);
        }
        proc_terminate($proc);
        proc_close($proc);
    }
    // The fixture app, and any app a test installed (bh_install_app()).
    foreach (glob($env['root'] . '/content/apps/*', GLOB_ONLYDIR) ?: [] as $dir) {
        if (is_file($dir . '/' . BH_FIXTURE_MARKER)) bh_rrmdir($dir);
    }
    // The test site shares storage/cache with a local install in the same
    // checkout. Settings cached from the test database ("Title Test Site")
    // would otherwise show on that site until they expired (issues.md BH-002).
    // Only cache entries are removed; the folder stays.
    foreach (glob($env['root'] . '/storage/cache/*') ?: [] as $entry) {
        is_dir($entry) ? bh_rrmdir($entry) : @unlink($entry);
    }
    if ($keep) {
        bh_out("\n  Kept: database {$env['db']['name']}, files in {$env['tmp']}\n");
        return;
    }
    try {
        $db = $env['db'];
        $pdo = new PDO("mysql:host={$db['host']};port={$db['port']}", $db['user'], $db['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec("DROP DATABASE IF EXISTS `{$db['name']}`");
    } catch (\Throwable) {}
}

/**
 * Skip a test that needs InnoDB's row locking. Dolt (the compat target)
 * merges concurrent transactions optimistically, so two simultaneous
 * "hits = hits + 1" can both write the same value and one increment is lost.
 * MySQL and MariaDB lock the row for INSERT … ON DUPLICATE KEY UPDATE, which
 * is what RateLimiter relies on; these tests must pass there.
 */
function bh_require_innodb(): void
{
    if ((string) getenv('BASEHIM_TEST_DB_COMPAT') === 'dolt' && getenv('BASEHIM_TEST_FORCE_INNODB_TESTS') !== '1') {
        skip('needs InnoDB row locking (MySQL/MariaDB); Dolt merges concurrent writes optimistically');
    }
}

/** A fresh signed-in browser. */
function bh_browser(?string $user = null): Browser
{
    $b = new Browser($GLOBALS['__bh_env']['base_url']);
    if ($user !== null) $b->login($user, BH_TEST_PASSWORD);
    return $b;
}

function bh_env(): array
{
    return $GLOBALS['__bh_env'];
}

function bh_db(): \App\Core\Database
{
    return $GLOBALS['__bh_env']['database'];
}

/** Lines of today's core log and of the fixture app's log, for assertions. */
function bh_core_log(): string
{
    $f = $GLOBALS['__bh_env']['root'] . '/storage/logs/basehim-' . date('Y-m-d') . '.log';
    return is_file($f) ? (string) file_get_contents($f) : '';
}

function bh_app_log(string $slug = 'ajax-fixture'): string
{
    $out = '';
    foreach (glob($GLOBALS['__bh_env']['root'] . "/storage/logs/apps/{$slug}-*.log") ?: [] as $f) {
        $out .= (string) file_get_contents($f);
    }
    return $out;
}

function bh_uuid(): string
{
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}

function bh_copy_dir(string $src, string $dst): void
{
    @mkdir($dst, 0755, true);
    foreach (scandir($src) ?: [] as $e) {
        if ($e === '.' || $e === '..') continue;
        is_dir("$src/$e") ? bh_copy_dir("$src/$e", "$dst/$e") : copy("$src/$e", "$dst/$e");
    }
}

function bh_rrmdir(string $dir): void
{
    if (!is_dir($dir)) return;
    foreach (scandir($dir) ?: [] as $e) {
        if ($e === '.' || $e === '..') continue;
        is_dir("$dir/$e") ? bh_rrmdir("$dir/$e") : @unlink("$dir/$e");
    }
    @rmdir($dir);
}

/**
 * Install a throw-away app for a test: writes content/apps/{slug} (marked, so
 * teardown removes it) and its apps row. $files maps paths inside the app to
 * contents; app.json is written from $manifest unless given.
 */
function bh_install_app(string $slug, array $files, array $manifest = [], string $status = 'active'): string
{
    $dir = $GLOBALS['__bh_env']['root'] . '/content/apps/' . $slug;
    if (is_dir($dir) && !is_file($dir . '/' . BH_FIXTURE_MARKER)) {
        throw new \RuntimeException("{$dir} exists and is not a test app; refusing to overwrite it.");
    }
    bh_rrmdir($dir);
    @mkdir($dir, 0755, true);
    $manifest += ['name' => $slug, 'slug' => $slug, 'version' => '1.0.0', 'namespace' => 'BhTest\\' . str_replace(' ', '', ucwords(str_replace('-', ' ', $slug))), 'entry' => 'App'];
    $files += ['app.json' => json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)];
    foreach ($files as $path => $content) {
        @mkdir(dirname($dir . '/' . $path), 0755, true);
        file_put_contents($dir . '/' . $path, $content);
    }
    file_put_contents($dir . '/' . BH_FIXTURE_MARKER, "Installed by tests/run.php; removed afterwards.\n");
    $db = bh_db();
    $db->execute('DELETE FROM {apps} WHERE slug = :s', ['s' => $slug]);
    $db->insert('apps', ['slug' => $slug, 'name' => $manifest['name'], 'version' => $manifest['version'], 'status' => $status, 'vendor' => 'tests']);
    return $dir;
}

/** Remove an app installed with bh_install_app(), and its report. */
function bh_remove_app(string $slug): void
{
    $env = $GLOBALS['__bh_env'];
    $dir = $env['root'] . '/content/apps/' . $slug;
    if (is_file($dir . '/' . BH_FIXTURE_MARKER)) bh_rrmdir($dir);
    bh_db()->execute('DELETE FROM {apps} WHERE slug = :s', ['s' => $slug]);
    @unlink($env['tmp'] . '/app-health/' . $slug . '.json');
}

/** An app's health record as the server wrote it, or null. */
function bh_app_health(string $slug): ?array
{
    $f = $GLOBALS['__bh_env']['tmp'] . '/app-health/' . $slug . '.json';
    $d = is_file($f) ? json_decode((string) file_get_contents($f), true) : null;
    return is_array($d) ? $d : null;
}

function bh_app_status(string $slug): ?string
{
    $row = bh_db()->selectOne('SELECT status FROM {apps} WHERE slug = :s', ['s' => $slug]);
    return $row['status'] ?? null;
}
