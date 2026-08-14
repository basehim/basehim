<?php
/**
 * Basehim Installer
 *
 * Standalone first-run wizard. Walks the user through:
 *   1. Requirements check
 *   2. Database configuration (writes .env)
 *   3. Schema installation (runs migration SQL)
 *   4. Admin account creation
 *
 * After install, this file is rendered unreachable by a lock flag in .env.
 * For an extra layer, rename this file after install completes.
 */

declare(strict_types=1);

// The installer must define the same BASEHIM_* constants index.php does.
// Autoloader::load() builds every class path from BASEHIM_ROOT, so without it
// the very first autoload fatals with "Undefined constant
// App\Core\BASEHIM_ROOT" — PHP resolves an unknown bare constant against the
// current namespace before giving up.
define('BASEHIM_ROOT', __DIR__);
define('BASEHIM_VERSION', '1.0.2');
define('BASEHIM_INSTALLING', true);


// Detect install base path (works at root or in subdir, same logic as index.php)
$scriptName = $_SERVER['SCRIPT_NAME'] ?? '/install.php';
$basePath = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
if ($basePath === '.' || $basePath === '/') {
    $basePath = '';
}
define('BASEHIM_BASE', $basePath);

// --- Bootstrap minimal env --------------------------------------------------
require __DIR__ . '/app/Core/Autoloader.php';
\App\Core\Autoloader::register();

// Load .env if present, but don't insist
$envFile = __DIR__ . '/.env';
if (is_file($envFile)) {
    \App\Core\Env::load($envFile);
    if (\App\Core\Env::get('INSTALLED') === 'true') {
        header('Location: ' . (BASEHIM_BASE ?: '/'));
        exit;
    }
}

/*
 * Second guard: a working install locks the installer even without the flag.
 *
 * The INSTALLED flag was the only thing standing here, and .env.example never
 * shipped with it — so anyone who wrote .env by hand (a deploy script, a host
 * migration, following the README's DB section) had a live site with a fully
 * functional installer on it. Re-running it lets an attacker point the site at
 * their own database, rotate JWT_SECRET and create an administrator.
 *
 * So: if we can connect and there is already a populated users table, this is
 * an installed site. Refuse regardless of what the flag says.
 */
if (is_file($envFile) && \App\Core\Env::get('DB_DATABASE', '') !== '') {
    try {
        $checkDsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            \App\Core\Env::get('DB_HOST', '127.0.0.1'),
            \App\Core\Env::get('DB_PORT', '3306'),
            \App\Core\Env::get('DB_DATABASE', '')
        );
        $checkPdo = new PDO(
            $checkDsn,
            \App\Core\Env::get('DB_USERNAME', ''),
            \App\Core\Env::get('DB_PASSWORD', ''),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]
        );
        $prefix = (string) \App\Core\Env::get('DB_PREFIX', '');
        $userCount = (int) $checkPdo->query('SELECT COUNT(*) FROM `' . $prefix . 'users`')->fetchColumn();
        if ($userCount > 0) {
            http_response_code(403);
            echo '<!doctype html><meta charset="utf-8"><title>Already installed</title>'
               . '<div style="font-family:system-ui,sans-serif;max-width:34rem;margin:15vh auto;padding:2rem;'
               . 'border:1px solid #e2e8f0;border-radius:14px;">'
               . '<h1 style="font-size:1.15rem;margin:0 0 .6rem;">Basehim is already installed</h1>'
               . '<p style="color:#475569;font-size:.9rem;line-height:1.6;margin:0 0 1rem;">'
               . 'This site has a database with existing accounts, so the installer will not run. '
               . '<strong>Delete <code>install.php</code> from the server</strong> — leaving it in place '
               . 'is a security risk.</p>'
               . '<a href="' . htmlspecialchars(BASEHIM_BASE ?: '/', ENT_QUOTES) . '" '
               . 'style="color:#2563eb;font-size:.9rem;">Go to the site</a></div>';
            exit;
        }
    } catch (\Throwable) {
        // Cannot connect, or no users table yet — a genuinely fresh install.
        // Fall through and let the wizard run.
    }
}

/**
 * Expand {table} tokens to prefixed, backticked identifiers.
 *
 * Every step needs this, and each one reads $cfg from the session separately,
 * so the prefix is passed in rather than captured. Declaring it inside one step
 * — as an earlier build did — left the admin-creation step sending "{users}" to
 * MySQL verbatim, and every install failed at the last hurdle.
 *
 * install.php runs before the container exists, so it cannot ask Database to do
 * this.
 */
function pxSql(string $sql, ?array $cfg): string
{
    if (!str_contains($sql, '{')) return $sql;
    $prefix = (string) (($cfg['DB_PREFIX'] ?? '') ?: '');

    // Two forms, matching Database::applyPrefix() exactly. A table name appears
    // in SQL in two roles: {posts} is an identifier and gets backticks, while
    // {@posts} is the name used as a VALUE — information_schema lookups compare
    // against a string — and expands bare, ready to sit inside existing quotes.
    //
    // The bare form was missing here, so the eight information_schema guards in
    // migrations 007 and 009 sent a literal '{@apps}' to the server.
    $sql = preg_replace_callback(
        '/\{@([a-z][a-z0-9_]*)\}/',
        static fn(array $m): string => $prefix . $m[1],
        $sql
    ) ?? $sql;

    return preg_replace_callback(
        '/\{([a-z][a-z0-9_]*)\}/',
        static fn(array $m): string => '`' . $prefix . $m[1] . '`',
        $sql
    ) ?? $sql;
}

session_start();
$step = (int) ($_GET['step'] ?? 1);
$errors = [];
$success = '';

// --- Step handlers ----------------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_db') {
        $cfg = [
            'DB_HOST'     => trim($_POST['db_host'] ?? '127.0.0.1'),
            'DB_PORT'     => trim($_POST['db_port'] ?? '3306'),
            'DB_DATABASE' => trim($_POST['db_database'] ?? ''),
            'DB_USERNAME' => trim($_POST['db_username'] ?? ''),
            'DB_PASSWORD' => $_POST['db_password'] ?? '',
            'APP_URL'     => trim($_POST['app_url'] ?? ''),
            'SITE_TITLE'  => trim($_POST['site_title'] ?? 'Basehim'),
        ];

        // Test connection
        try {
            $dsn = "mysql:host={$cfg['DB_HOST']};port={$cfg['DB_PORT']};dbname={$cfg['DB_DATABASE']};charset=utf8mb4";
            $pdo = new PDO($dsn, $cfg['DB_USERNAME'], $cfg['DB_PASSWORD'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
            $_SESSION['install_db_config'] = $cfg;
            header('Location: install.php?step=3');
            exit;
        } catch (PDOException $e) {
            $errors[] = 'Database connection failed: ' . $e->getMessage();
            $step = 2;
        }
    }

    if ($action === 'install_schema') {
        $cfg = $_SESSION['install_db_config'] ?? null;
        if (!$cfg) {
            $errors[] = 'Database configuration missing. Please start over.';
            $step = 2;
        } else {
            try {
                $dsn = "mysql:host={$cfg['DB_HOST']};port={$cfg['DB_PORT']};dbname={$cfg['DB_DATABASE']};charset=utf8mb4";
                $pdo = new PDO($dsn, $cfg['DB_USERNAME'], $cfg['DB_PASSWORD'], [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                ]);

                // Clean any partial install: drop Basehim tables in reverse-dependency order.
                // This makes retries safe — if a previous attempt failed half-way, we don't
                // get stuck with "table exists but with wrong FKs".
                //
                // Every table any migration creates must be listed. The list previously
                // named the pre-1.43 apps table and omitted everything added
                // after 001, so a retried install tripped over the leftovers from its own
                // first attempt. Names go through pxSql() so DB_PREFIX is honoured.
                $basehimTables = [
                    'activity_log', 'user_activity_log', 'notifications', 'refresh_tokens',
                    'api_keys', 'password_resets', 'auth_login_attempts', 'scheduled_tasks',
                    'apps', 'menu_items', 'menus', 'seo_meta', 'settings', 'comments',
                    'post_term', 'terms', 'taxonomies', 'post_revisions', 'post_meta',
                    'posts', 'media', 'users',
                ];
                $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
                foreach ($basehimTables as $t) {
                    $pdo->exec(pxSql("DROP TABLE IF EXISTS {{$t}}", $cfg));
                }
                $pdo->exec('SET FOREIGN_KEY_CHECKS=1');

                // Run EVERY migration, in filename order — not just 001.
                //
                // This used to install 001_initial_schema.sql alone, which left a
                // fresh site without `apps`, `api_keys`, `password_resets`,
                // `otp_attempts`, `agents` or `scheduled_tasks`: no app system, no
                // API keys, no MCP, no password reset. Nothing runs pending
                // migrations on boot either, so the gap was permanent. Upgrades
                // were fine — UpdateService applies them — so only fresh installs
                // were affected, which is why it survived this long.
                $pdo->exec(pxSql(
                    'CREATE TABLE IF NOT EXISTS {migrations} (
                        `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                        `migration` VARCHAR(255) NOT NULL,
                        `applied_at` DATETIME NOT NULL
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
                    $cfg
                ));

                $files = glob(__DIR__ . '/database/migrations/*.sql') ?: [];
                // Sort by full filename, matching UpdateService exactly. Some
                // numbers repeat (two 002_, two 003_), so the whole name orders them.
                sort($files);

                $record = $pdo->prepare(pxSql('INSERT INTO {migrations} (migration, applied_at) VALUES (?, ?)', $cfg));
                $applied = [];

                foreach ($files as $file) {
                    $sql = (string) file_get_contents($file);

                    // Strip SQL line-comments first. Without this, chunks that begin
                    // with `-- header` comments get rejected wholesale, taking the
                    // CREATE TABLE that follows down with them.
                    $cleanLines = [];
                    foreach (explode("\n", $sql) as $line) {
                        if (preg_match('/^\s*--/', $line)) continue;
                        $cleanLines[] = $line;
                    }
                    $cleanSql = implode("\n", $cleanLines);

                    // Split on semicolons at end of line (handles multi-line
                    // statements). PREPARE/EXECUTE blocks in the later migrations
                    // split into individually valid statements, and the session
                    // variables they use persist across them on one connection.
                    $statements = array_filter(array_map('trim', preg_split('/;\s*[\r\n]+/', $cleanSql)));
                    foreach ($statements as $stmt) {
                        if ($stmt === '') continue;
                        $pdo->exec(pxSql($stmt, $cfg));
                    }

                    $record->execute([basename($file), date('Y-m-d H:i:s')]);
                    $applied[] = basename($file);
                }

                $_SESSION['install_migrations'] = $applied;
                $_SESSION['install_schema_done'] = true;
                header('Location: install.php?step=4');
                exit;
            } catch (PDOException $e) {
                $errors[] = 'Schema install failed: ' . $e->getMessage();
                $step = 3;
            }
        }
    }

    if ($action === 'create_admin') {
        $cfg = $_SESSION['install_db_config'] ?? null;
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $displayName = trim($_POST['display_name'] ?? '') ?: $username;

        if (strlen($username) < 3) $errors[] = 'Username must be at least 3 characters.';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Invalid email address.';
        if (strlen($password) < 8) $errors[] = 'Password must be at least 8 characters.';
        if (!$cfg) $errors[] = 'Database configuration missing.';

        if (!$errors) {
            try {
                $dsn = "mysql:host={$cfg['DB_HOST']};port={$cfg['DB_PORT']};dbname={$cfg['DB_DATABASE']};charset=utf8mb4";
                $pdo = new PDO($dsn, $cfg['DB_USERNAME'], $cfg['DB_PASSWORD'], [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                ]);

                $uuid = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
                    mt_rand(0, 0xffff), mt_rand(0, 0xffff),
                    mt_rand(0, 0xffff),
                    mt_rand(0, 0x0fff) | 0x4000,
                    mt_rand(0, 0x3fff) | 0x8000,
                    mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
                );

                $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
                $stmt = $pdo->prepare(pxSql("INSERT INTO {users} (uuid, username, email, password_hash, display_name, role, status, email_verified_at) VALUES (?, ?, ?, ?, ?, 'super_admin', 'active', NOW())", $cfg));
                $stmt->execute([$uuid, $username, $email, $hash, $displayName]);
                $userId = (int) $pdo->lastInsertId();

                // Seed a welcome post
                $welcomeUuid = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
                    mt_rand(0, 0xffff), mt_rand(0, 0xffff),
                    mt_rand(0, 0xffff),
                    mt_rand(0, 0x0fff) | 0x4000,
                    mt_rand(0, 0x3fff) | 0x8000,
                    mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
                );

                $welcomeContent = "<h2>Welcome to Basehim!</h2><p>This is your first post. Edit or delete it from the admin dashboard, then start writing.</p><p>Basehim is a modern, API-first content management platform designed for developers and content creators alike.</p>";
                $stmt = $pdo->prepare(pxSql("INSERT INTO {posts} (uuid, author_id, type, status, slug, title, content, excerpt, published_at) VALUES (?, ?, 'post', 'published', 'hello-world', 'Hello, world!', ?, ?, NOW())", $cfg));
                $stmt->execute([$welcomeUuid, $userId, $welcomeContent, 'Welcome to Basehim — your first post.']);
                $welcomePostId = (int) $pdo->lastInsertId();

                // Seed a sample page
                $pageUuid = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
                    mt_rand(0, 0xffff), mt_rand(0, 0xffff),
                    mt_rand(0, 0xffff),
                    mt_rand(0, 0x0fff) | 0x4000,
                    mt_rand(0, 0x3fff) | 0x8000,
                    mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
                );
                $stmt = $pdo->prepare(pxSql("INSERT INTO {posts} (uuid, author_id, type, status, slug, title, content, published_at) VALUES (?, ?, 'page', 'published', 'about', 'About', '<h1>About this site</h1><p>This is a sample page. You can edit it in the admin dashboard.</p>', NOW())", $cfg));
                $stmt->execute([$pageUuid, $userId]);

                // Seed the default taxonomies (Categories + Tags). Without these the
                // Categories/Tags admin screens 404 and posts can't be classified.
                $pdo->exec(pxSql("INSERT INTO {taxonomies} (slug, label, singular, hierarchical, show_in_api, post_types) VALUES
                    ('category', 'Categories', 'Category', 1, 1, '[\"post\"]'),
                    ('post_tag', 'Tags', 'Tag', 0, 1, '[\"post\"]')
                    ON DUPLICATE KEY UPDATE label = VALUES(label)", $cfg));
                $catTaxId = (int) $pdo->query(pxSql("SELECT id FROM {taxonomies} WHERE slug='category'", $cfg))->fetchColumn();

                // A default "Uncategorized" term, with the welcome post filed under it
                // so the category archive isn't empty on first run.
                if ($catTaxId > 0) {
                    $pdo->prepare(pxSql("INSERT INTO {terms} (taxonomy_id, name, slug, count) VALUES (?, 'Uncategorized', 'uncategorized', 0)
                        ON DUPLICATE KEY UPDATE name = VALUES(name)", $cfg))->execute([$catTaxId]);
                    $uncatId = (int) $pdo->query(pxSql("SELECT id FROM {terms} WHERE taxonomy_id={$catTaxId} AND slug='uncategorized'", $cfg))->fetchColumn();
                    if ($uncatId > 0 && !empty($welcomePostId)) {
                        $pdo->prepare(pxSql("INSERT IGNORE INTO {post_term} (post_id, term_id, term_order) VALUES (?, ?, 0)", $cfg))
                            ->execute([$welcomePostId, $uncatId]);
                        $pdo->prepare(pxSql("UPDATE {terms} SET count = (SELECT COUNT(*) FROM {post_term} WHERE term_id = ?) WHERE id = ?", $cfg))
                            ->execute([$uncatId, $uncatId]);
                    }
                }

                // Write .env
                $jwtSecret = bin2hex(random_bytes(32));
                $appKey = bin2hex(random_bytes(16));
                // Carry the prefix through: omitting it pointed a prefixed
                // install at tables that do not exist on the next boot.
                $dbPrefix = (string) ($cfg['DB_PREFIX'] ?? '');

                $envContent = <<<ENV
APP_NAME="{$cfg['SITE_TITLE']}"
APP_ENV=production
APP_DEBUG=false
APP_URL={$cfg['APP_URL']}
APP_TIMEZONE=UTC
APP_LOCALE=en
APP_KEY={$appKey}

DB_DRIVER=mysql
DB_HOST={$cfg['DB_HOST']}
DB_PORT={$cfg['DB_PORT']}
DB_DATABASE={$cfg['DB_DATABASE']}
DB_USERNAME={$cfg['DB_USERNAME']}
DB_PASSWORD="{$cfg['DB_PASSWORD']}"
DB_CHARSET=utf8mb4
DB_COLLATION=utf8mb4_unicode_ci
DB_PREFIX={$dbPrefix}

JWT_SECRET={$jwtSecret}

SITE_TITLE="{$cfg['SITE_TITLE']}"
ADMIN_EMAIL={$email}

INSTALLED=true
ENV;
                file_put_contents(__DIR__ . '/.env', $envContent);

                // Seed the baseline settings. The migrations don't create these
                // rows, so a plain UPDATE would match nothing — upsert instead so
                // the site title/email actually persist for the whole app.
                $seedSettings = [
                    ['general',    'site_title',   $cfg['SITE_TITLE'] ?? 'Basehim'],
                    ['general',    'tagline',      $cfg['SITE_TAGLINE'] ?? 'A Modern API-First CMS'],
                    ['general',    'admin_email',  $email],
                    ['appearance', 'active_theme', 'default'],
                ];
                $setStmt = $pdo->prepare(pxSql(
                    "INSERT INTO {settings} (setting_group, setting_key, setting_value, is_json, autoload)
                     VALUES (?, ?, ?, 0, 1)
                     ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)",
                    $cfg
                ));
                foreach ($seedSettings as [$g, $k, $v]) {
                    $setStmt->execute([$g, $k, (string) $v]);
                }

                unset($_SESSION['install_db_config']);
                header('Location: install.php?step=5');
                exit;
            } catch (PDOException $e) {
                $errors[] = 'Admin creation failed: ' . $e->getMessage();
                $step = 4;
            }
        } else {
            $step = 4;
        }
    }
}

// --- Requirements check -----------------------------------------------------

function checkRequirements(): array
{
    return [
        ['name' => 'PHP 8.1+',          'ok' => PHP_VERSION_ID >= 80100,             'value' => PHP_VERSION],
        ['name' => 'PDO MySQL',         'ok' => extension_loaded('pdo_mysql'),      'value' => extension_loaded('pdo_mysql') ? 'enabled' : 'missing'],
        ['name' => 'JSON',              'ok' => extension_loaded('json'),           'value' => extension_loaded('json') ? 'enabled' : 'missing'],
        ['name' => 'mbstring',          'ok' => extension_loaded('mbstring'),       'value' => extension_loaded('mbstring') ? 'enabled' : 'missing'],
        ['name' => 'fileinfo',          'ok' => extension_loaded('fileinfo'),       'value' => extension_loaded('fileinfo') ? 'enabled' : 'missing'],
        ['name' => 'openssl',           'ok' => extension_loaded('openssl'),        'value' => extension_loaded('openssl') ? 'enabled' : 'missing'],
        ['name' => 'GD (image processing)', 'ok' => extension_loaded('gd'),         'value' => extension_loaded('gd') ? 'enabled' : 'recommended'],
        ['name' => 'storage/ writable', 'ok' => is_writable(__DIR__ . '/storage'),  'value' => is_writable(__DIR__ . '/storage') ? 'writable' : 'NOT writable'],
        ['name' => 'root writable (for .env)', 'ok' => is_writable(__DIR__),        'value' => is_writable(__DIR__) ? 'writable' : 'NOT writable'],
    ];
}

$requirements = $step === 1 ? checkRequirements() : [];
$allOk = $step === 1 ? !in_array(false, array_column($requirements, 'ok'), true) : true;

?><!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Basehim — Installer</title>
    <link rel="stylesheet" href="<?= BASEHIM_BASE ?>/admin/assets/css/tailwind.min.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
</head>
<body class="bg-gradient-to-br from-blue-50 via-white to-blue-100 min-h-screen font-sans antialiased text-slate-800">

<div class="max-w-3xl mx-auto py-12 px-6">
    <!-- Logo / Header -->
    <div class="text-center mb-10">
        <div class="inline-flex items-center justify-center w-16 h-16 rounded-2xl bg-blue-600 shadow-lg shadow-blue-200 mb-4">
            <i class="fa-solid fa-rocket text-white text-2xl"></i>
        </div>
        <h1 class="text-3xl font-bold text-slate-900">Basehim Installer</h1>
        <p class="text-slate-500 mt-2">A modern, API-first content platform</p>
    </div>

    <!-- Step indicator -->
    <div class="flex items-center justify-between mb-8 max-w-2xl mx-auto">
        <?php $steps = [1 => 'Requirements', 2 => 'Database', 3 => 'Schema', 4 => 'Admin', 5 => 'Done']; ?>
        <?php foreach ($steps as $n => $label): ?>
            <div class="flex items-center <?= $n !== 5 ? 'flex-1' : '' ?>">
                <div class="flex items-center gap-2">
                    <div class="w-8 h-8 rounded-full flex items-center justify-center text-sm font-semibold <?= $step >= $n ? 'bg-blue-600 text-white' : 'bg-slate-200 text-slate-500' ?>">
                        <?php if ($step > $n): ?><i class="fa-solid fa-check text-xs"></i><?php else: ?><?= $n ?><?php endif; ?>
                    </div>
                    <span class="text-xs font-medium hidden sm:block <?= $step >= $n ? 'text-slate-900' : 'text-slate-400' ?>"><?= $label ?></span>
                </div>
                <?php if ($n !== 5): ?>
                    <div class="flex-1 h-px mx-2 <?= $step > $n ? 'bg-blue-600' : 'bg-slate-200' ?>"></div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="bg-white rounded-2xl shadow-xl shadow-blue-100/40 border border-slate-100 overflow-hidden">

        <?php if ($errors): ?>
            <div class="bg-red-50 border-b border-red-200 px-6 py-4">
                <?php foreach ($errors as $err): ?>
                    <div class="flex items-start gap-2 text-red-700 text-sm">
                        <i class="fa-solid fa-circle-exclamation mt-0.5"></i>
                        <span><?= htmlspecialchars($err) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($step === 1): ?>
            <!-- STEP 1: REQUIREMENTS -->
            <div class="p-8">
                <h2 class="text-xl font-semibold mb-1">System Requirements</h2>
                <p class="text-slate-500 text-sm mb-6">Make sure your server meets these requirements before continuing.</p>

                <div class="space-y-2">
                    <?php foreach ($requirements as $req): ?>
                        <div class="flex items-center justify-between py-3 px-4 rounded-lg <?= $req['ok'] ? 'bg-green-50' : 'bg-red-50' ?>">
                            <div class="flex items-center gap-3">
                                <?php if ($req['ok']): ?>
                                    <i class="fa-solid fa-circle-check text-green-600"></i>
                                <?php else: ?>
                                    <i class="fa-solid fa-circle-xmark text-red-600"></i>
                                <?php endif; ?>
                                <span class="font-medium text-slate-800"><?= htmlspecialchars($req['name']) ?></span>
                            </div>
                            <span class="text-sm <?= $req['ok'] ? 'text-green-700' : 'text-red-700' ?>"><?= htmlspecialchars($req['value']) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="mt-8 flex justify-end">
                    <?php if ($allOk): ?>
                        <a href="?step=2" class="inline-flex items-center gap-2 px-5 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg shadow-sm transition">
                            Continue <i class="fa-solid fa-arrow-right text-xs"></i>
                        </a>
                    <?php else: ?>
                        <button disabled class="px-5 py-2.5 bg-slate-200 text-slate-500 rounded-lg cursor-not-allowed">Fix the issues above to continue</button>
                    <?php endif; ?>
                </div>
            </div>

        <?php elseif ($step === 2): ?>
            <!-- STEP 2: DATABASE -->
            <form method="post" class="p-8">
                <input type="hidden" name="action" value="save_db">
                <h2 class="text-xl font-semibold mb-1">Database Configuration</h2>
                <p class="text-slate-500 text-sm mb-6">Enter your cPanel MySQL database credentials below.</p>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1.5">Site Title</label>
                        <input name="site_title" required class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500 outline-none" value="<?= htmlspecialchars($_POST['site_title'] ?? 'My Basehim Site') ?>">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1.5">Site URL</label>
                        <input name="app_url" type="url" required class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500 outline-none" value="<?= htmlspecialchars($_POST['app_url'] ?? ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'))) ?>">
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1.5">Database Host</label>
                        <input name="db_host" required class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500 outline-none" value="<?= htmlspecialchars($_POST['db_host'] ?? 'localhost') ?>">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1.5">Port</label>
                        <input name="db_port" required class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500 outline-none" value="<?= htmlspecialchars($_POST['db_port'] ?? '3306') ?>">
                    </div>

                    <div class="md:col-span-2">
                        <label class="block text-sm font-medium text-slate-700 mb-1.5">Database Name</label>
                        <input name="db_database" required class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500 outline-none" value="<?= htmlspecialchars($_POST['db_database'] ?? '') ?>" placeholder="cpaneluser_basehim">
                        <p class="text-xs text-slate-500 mt-1">On cPanel, create this database first via "MySQL Databases"</p>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1.5">Database User</label>
                        <input name="db_username" required class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500 outline-none" value="<?= htmlspecialchars($_POST['db_username'] ?? '') ?>">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1.5">Database Password</label>
                        <input name="db_password" type="password" class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500 outline-none">
                    </div>
                </div>

                <div class="mt-8 flex justify-between">
                    <a href="?step=1" class="inline-flex items-center gap-2 px-5 py-2.5 text-slate-600 hover:text-slate-900 font-medium">
                        <i class="fa-solid fa-arrow-left text-xs"></i> Back
                    </a>
                    <button type="submit" class="inline-flex items-center gap-2 px-5 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg shadow-sm transition">
                        Test Connection <i class="fa-solid fa-arrow-right text-xs"></i>
                    </button>
                </div>
            </form>

        <?php elseif ($step === 3): ?>
            <!-- STEP 3: SCHEMA -->
            <form method="post" class="p-8">
                <input type="hidden" name="action" value="install_schema">
                <h2 class="text-xl font-semibold mb-1">Install Database Schema</h2>
                <p class="text-slate-500 text-sm mb-6">We'll create all the necessary tables in your database.</p>

                <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-6">
                    <div class="flex items-start gap-3">
                        <i class="fa-solid fa-circle-info text-blue-600 mt-0.5"></i>
                        <div class="text-sm text-blue-900">
                            <strong>Ready to install:</strong> 17 tables will be created and seeded with default values (categories, settings, taxonomies, etc.).
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-2 md:grid-cols-3 gap-2 text-sm">
                    <?php foreach (['users','posts','post_meta','post_revisions','media','taxonomies','terms','post_term','comments','settings','seo_meta','menus','menu_items','apps','refresh_tokens','notifications','activity_log'] as $t): ?>
                        <div class="flex items-center gap-2 text-slate-600">
                            <i class="fa-solid fa-table text-blue-500 text-xs"></i>
                            <code class="text-xs"><?= $t ?></code>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="mt-8 flex justify-between">
                    <a href="?step=2" class="inline-flex items-center gap-2 px-5 py-2.5 text-slate-600 hover:text-slate-900 font-medium">
                        <i class="fa-solid fa-arrow-left text-xs"></i> Back
                    </a>
                    <button type="submit" class="inline-flex items-center gap-2 px-5 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg shadow-sm transition">
                        <i class="fa-solid fa-database"></i> Run Installation
                    </button>
                </div>
            </form>

        <?php elseif ($step === 4): ?>
            <!-- STEP 4: ADMIN ACCOUNT -->
            <form method="post" class="p-8">
                <input type="hidden" name="action" value="create_admin">
                <h2 class="text-xl font-semibold mb-1">Create Administrator Account</h2>
                <p class="text-slate-500 text-sm mb-6">This account will have full access to the CMS.</p>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1.5">Username</label>
                        <input name="username" required minlength="3" class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500 outline-none" value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1.5">Display Name</label>
                        <input name="display_name" class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500 outline-none" value="<?= htmlspecialchars($_POST['display_name'] ?? '') ?>">
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-sm font-medium text-slate-700 mb-1.5">Email</label>
                        <input name="email" type="email" required class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500 outline-none" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-sm font-medium text-slate-700 mb-1.5">Password</label>
                        <input name="password" type="password" required minlength="8" class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500 outline-none">
                        <p class="text-xs text-slate-500 mt-1">Minimum 8 characters. Use a mix of letters, numbers, and symbols.</p>
                    </div>
                </div>

                <div class="mt-8 flex justify-end">
                    <button type="submit" class="inline-flex items-center gap-2 px-5 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg shadow-sm transition">
                        <i class="fa-solid fa-user-plus"></i> Create Account & Finish
                    </button>
                </div>
            </form>

        <?php elseif ($step === 5): ?>
            <!-- STEP 5: DONE -->
            <div class="p-8 text-center">
                <div class="inline-flex items-center justify-center w-20 h-20 rounded-full bg-green-100 mb-4">
                    <i class="fa-solid fa-check text-green-600 text-3xl"></i>
                </div>
                <h2 class="text-2xl font-semibold mb-2">Installation Complete!</h2>
                <p class="text-slate-500 mb-8">Basehim is ready to go. Your admin account is created and your site is live.</p>

                <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4 mb-8 text-left">
                    <div class="flex items-start gap-3">
                        <i class="fa-solid fa-triangle-exclamation text-yellow-600 mt-0.5"></i>
                        <div class="text-sm text-yellow-900">
                            <strong>Security recommendation:</strong> Delete <code class="bg-yellow-100 px-1 rounded">install.php</code> from your server now. You won't need it again.
                        </div>
                    </div>
                </div>

                <div class="flex flex-col sm:flex-row gap-3 justify-center">
                    <a href="<?= BASEHIM_BASE ?>/admin" class="inline-flex items-center justify-center gap-2 px-6 py-3 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg shadow-sm transition">
                        <i class="fa-solid fa-arrow-right-to-bracket"></i> Go to Admin Dashboard
                    </a>
                    <a href="<?= BASEHIM_BASE ?>/" class="inline-flex items-center justify-center gap-2 px-6 py-3 bg-white border border-slate-300 hover:bg-slate-50 text-slate-700 font-medium rounded-lg transition">
                        <i class="fa-solid fa-house"></i> View Site
                    </a>
                </div>
            </div>
        <?php endif; ?>

    </div>

    <div class="text-center mt-8 text-xs text-slate-400">
        Basehim v<?= BASEHIM_VERSION ?> · Built with PHP <?= PHP_VERSION ?>
    </div>
</div>

</body>
</html>
