<?php
/**
 * Basehim — Run pending migrations
 * Usage (CLI): php database/migrate.php
 * Usage (browser): https://your-site.com/database/migrate.php?key=YOUR_MIGRATE_KEY
 *
 * For browser use, set MIGRATE_KEY in your .env to a secret string.
 */

define('BASEHIM_ROOT', dirname(__DIR__));

// ---- Security: require key when accessed via web ----
if (PHP_SAPI !== 'cli') {
    $envFile = BASEHIM_ROOT . '/.env';
    $migrateKey = '';
    if (is_file($envFile)) {
        foreach (file($envFile) as $line) {
            if (str_starts_with(trim($line), 'MIGRATE_KEY=')) {
                $migrateKey = trim(explode('=', $line, 2)[1]);
            }
        }
    }
    if (!$migrateKey || ($_GET['key'] ?? '') !== $migrateKey) {
        http_response_code(403);
        die('Forbidden. Set MIGRATE_KEY in .env and pass ?key=YOUR_KEY');
    }
}

// ---- Load DB config ----
$envFile = BASEHIM_ROOT . '/.env';
$env = [];
if (is_file($envFile)) {
    foreach (file($envFile) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        [$k, $v] = array_pad(explode('=', $line, 2), 2, '');
        $env[trim($k)] = trim($v, "\"' \t");
    }
}

$host   = $env['DB_HOST']     ?? '127.0.0.1';
$port   = $env['DB_PORT']     ?? '3306';
$dbname = $env['DB_DATABASE'] ?? '';
$user   = $env['DB_USERNAME'] ?? '';
$pass   = $env['DB_PASSWORD'] ?? '';

try {
    $pdo = new PDO("mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
} catch (PDOException $e) {
    die("DB connection failed: " . $e->getMessage() . "\n");
}

/**
 * Expand {table} tokens, exactly as App\Core\Database::applyPrefix() does.
 *
 * This script talks to PDO directly and never loads the container, so nothing
 * else is going to do it. Without this, MySQL receives the literal string
 * "{migrations}" and rejects it with a syntax error — which is what the System
 * page's Run migrations button did until 1.0.1.
 *
 * Two forms, because a table name appears in SQL in two roles: {posts} is an
 * identifier and gets backticks, while {@posts} is the name used as a value —
 * information_schema lookups compare against a string — and expands bare.
 */
$dbPrefix = (string) ($env['DB_PREFIX'] ?? '');
$px = static function (string $sql) use ($dbPrefix): string {
    if (!str_contains($sql, '{')) return $sql;
    $sql = preg_replace_callback('/\{@(\w+)\}/', fn($m) => $dbPrefix . $m[1], $sql);
    return preg_replace_callback('/\{(\w+)\}/', fn($m) => '`' . $dbPrefix . $m[1] . '`', $sql);
};

// ---- Create migrations tracking table if needed ----
$pdo->exec($px("CREATE TABLE IF NOT EXISTS {migrations} (
    `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `migration`  VARCHAR(255) NOT NULL UNIQUE,
    `ran_at`     TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"));

// ---- Find migration files ----
$migrationsDir = __DIR__ . '/migrations';
$files = glob($migrationsDir . '/*.sql');
sort($files);

$ran = $pdo->query($px("SELECT migration FROM {migrations}"))->fetchAll(PDO::FETCH_COLUMN);

$pending = 0;
foreach ($files as $file) {
    /* The key must match what UpdateService and the System page record, or a
       migration applied by one runner looks pending to the other and every file
       ends up recorded twice under two spellings. Both use the basename with
       the .sql extension stripped. */
    $name = preg_replace('/\.sql$/', '', basename($file));
    if (in_array($name, $ran, true)) {
        echo "  [skip] {$name}\n";
        continue;
    }

    $sql = file_get_contents($file);

    // Strip SQL line-comments first. Without this, chunks that begin with
    // `-- header` comments get rejected wholesale by the str_starts_with('--')
    // filter below — taking the CREATE/ALTER/etc. statement that follows down
    // with them. Mirrors the cleanup install.php does.
    $cleanLines = [];
    foreach (explode("\n", $sql) as $line) {
        if (preg_match('/^\s*--/', $line)) continue;
        $cleanLines[] = $line;
    }
    $cleanSql = implode("\n", $cleanLines);

    // Split on semicolons at end of line (handles multi-line statements).
    $statements = array_filter(
        array_map('trim', preg_split('/;\s*[\r\n]+/', $cleanSql)),
        fn($s) => $s !== ''
    );

    echo "  [run ] {$name} ... ";
    try {
        foreach ($statements as $stmt) {
            $pdo->exec($px($stmt));
        }
        $pdo->prepare($px("INSERT INTO {migrations} (migration) VALUES (?)"))->execute([$name]);
        echo "OK\n";
        $pending++;
    } catch (PDOException $e) {
        echo "FAILED: " . $e->getMessage() . "\n";
        exit(1);
    }
}

if ($pending === 0) {
    echo "Nothing to migrate — all up to date.\n";
} else {
    echo "{$pending} migration(s) applied.\n";
}
