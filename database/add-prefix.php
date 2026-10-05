<?php
/**
 * Basehim — give an existing install a table prefix.
 *
 * Command line only:
 *   php database/add-prefix.php bh_           dry run: lists what would change
 *   php database/add-prefix.php bh_ --apply   renames the tables, sets DB_PREFIX in .env
 *
 * Installs made with 1.2.29 or earlier have no table prefix, whatever DB_PREFIX
 * was set to (see CHANGELOG-1.2.30). This renames this site's tables — core,
 * migrations, and every app table (app_*) — in one atomic RENAME TABLE, then
 * writes DB_PREFIX to .env. The old .env is copied to storage/backups/ first
 * (not beside .env, where the web server could serve the copy).
 *
 * Back up the database first. In a database shared with other software, read
 * the dry-run list: unprefixed tables are matched by name.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Run this from the command line.\n");
}

define('BASEHIM_ROOT', dirname(__DIR__));

$args  = array_slice($argv, 1);
$apply = in_array('--apply', $args, true);
$new   = '';
foreach ($args as $a) {
    if ($a !== '--apply') { $new = $a; break; }
}
if (!preg_match('/^[A-Za-z0-9_]{1,32}$/', $new)) {
    fwrite(STDERR, "Usage: php database/add-prefix.php <prefix> [--apply]\n"
        . "  <prefix>  letters, numbers and _ only, e.g. bh_\n");
    exit(2);
}

$envFile = BASEHIM_ROOT . '/.env';
if (!is_file($envFile) || !is_readable($envFile)) {
    fwrite(STDERR, ".env not found or not readable.\n");
    exit(1);
}
$env = [];
foreach (file($envFile, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
    $t = trim($line);
    if ($t === '' || $t[0] === '#' || !str_contains($t, '=')) continue;
    [$k, $v] = explode('=', $t, 2);
    $env[trim($k)] = trim(trim($v), "\"'");
}
$old = (string) ($env['DB_PREFIX'] ?? '');
if ($old === $new) {
    echo "DB_PREFIX is already '{$new}'. Nothing to do.\n";
    exit(0);
}

try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $env['DB_HOST'] ?? '127.0.0.1', $env['DB_PORT'] ?? '3306', $env['DB_DATABASE'] ?? ''),
        $env['DB_USERNAME'] ?? '', $env['DB_PASSWORD'] ?? '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    fwrite(STDERR, 'Database connection failed: ' . $e->getMessage() . "\n");
    exit(1);
}

// Every table core creates (migrations and the services that create their own).
$core = [
    'users', 'media', 'posts', 'post_meta', 'post_revisions', 'taxonomies', 'terms', 'post_term',
    'comments', 'settings', 'seo_meta', 'menus', 'menu_items', 'apps', 'refresh_tokens',
    'notifications', 'activity_log', 'api_keys', 'password_resets', 'user_activity_log',
    'auth_login_attempts', 'auth_remember_tokens', 'scheduled_tasks', 'migrations',
    'mcp_oauth_clients', 'mcp_oauth_codes', 'mcp_oauth_tokens',
];

$all  = $pdo->query('SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()')
            ->fetchAll(PDO::FETCH_COLUMN);
$have = array_flip($all);
$plan = [];
foreach ($all as $t) {
    if ($old !== '' && !str_starts_with($t, $old)) continue;
    $bare = $old !== '' ? substr($t, strlen($old)) : $t;
    if (!in_array($bare, $core, true) && !str_starts_with($bare, 'app_')) continue;
    $plan[$t] = $new . $bare;
}

if (!isset($plan[$old . 'users'], $plan[$old . 'posts'])) {
    fwrite(STDERR, "No Basehim tables found with the current prefix '{$old}'. Nothing changed.\n");
    exit(1);
}
$clash = array_values(array_filter($plan, fn($to) => isset($have[$to])));
if ($clash) {
    fwrite(STDERR, "These tables already exist, so the prefix '{$new}' is taken:\n  " . implode("\n  ", $clash) . "\nNothing changed.\n");
    exit(1);
}

echo "Rename " . count($plan) . " tables ('{$old}' -> '{$new}'):\n";
foreach ($plan as $from => $to) echo "  {$from}  ->  {$to}\n";

if (!$apply) {
    echo "\nDry run — nothing changed. Back up the database, then run again with --apply.\n";
    exit(0);
}

// One statement: MySQL renames all of them or none.
$pairs = [];
foreach ($plan as $from => $to) $pairs[] = "`{$from}` TO `{$to}`";
try {
    $pdo->exec('RENAME TABLE ' . implode(', ', $pairs));
} catch (PDOException $e) {
    fwrite(STDERR, 'Rename failed, nothing changed: ' . $e->getMessage() . "\n");
    exit(1);
}

$backupDir = BASEHIM_ROOT . '/storage/backups';
if (!is_dir($backupDir)) @mkdir($backupDir, 0750, true);
$backup = $backupDir . '/env-' . date('Ymd-His') . '.bak';
$src = (string) file_get_contents($envFile);
$okBackup = @file_put_contents($backup, $src) !== false;
@chmod($backup, 0600);

if (preg_match('/^DB_PREFIX=.*$/m', $src)) {
    $src = (string) preg_replace('/^DB_PREFIX=.*$/m', 'DB_PREFIX=' . $new, $src);
} else {
    $src = (string) preg_replace('/^(DB_COLLATION=.*)$/m', '${1}' . "\n" . 'DB_PREFIX=' . $new, $src, 1, $n);
    if ($n === 0) $src = rtrim($src, "\n") . "\nDB_PREFIX={$new}\n";
}
if (@file_put_contents($envFile, $src) === false) {
    $undo = [];
    foreach ($plan as $from => $to) $undo[] = "`{$to}` TO `{$from}`";
    fwrite(STDERR, "Tables renamed, but .env could not be written. Either add this line to .env:\n"
        . "  DB_PREFIX={$new}\nor undo the rename with:\n  RENAME TABLE " . implode(', ', $undo) . ";\n");
    exit(1);
}

foreach (glob(BASEHIM_ROOT . '/storage/cache/*') ?: [] as $f) if (is_file($f)) @unlink($f);

echo "\nDone. DB_PREFIX={$new} written to .env"
    . ($okBackup ? " (previous .env saved as storage/backups/" . basename($backup) . ")" : '') . ".\n";