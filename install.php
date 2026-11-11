<?php
/**
 * Basehim Installer
 *
 * Standalone first-run wizard (rebuilt in 1.2.98). Four screens:
 *   1. check     — what the server has; required items block, recommended ones don't
 *   2. database  — connection details, tested (version, privileges) before going on
 *   3. site      — site title, tagline, address, timezone and the first account;
 *                  submitting it creates the tables, the account and starter
 *                  content, then writes .env (which locks the installer)
 *   4. done      — shown once, from the session that just installed
 *
 * After install every request here is sent to the home page, except the done
 * screen for the browser that finished the install.
 */

declare(strict_types=1);

// The installer must define the same BASEHIM_* constants index.php does.
// Autoloader::load() builds every class path from BASEHIM_ROOT, so without it
// the very first autoload fatals with "Undefined constant
// App\Core\BASEHIM_ROOT" — PHP resolves an unknown bare constant against the
// current namespace before giving up.
define('BASEHIM_ROOT', __DIR__);
// Read from index.php, the single source of truth. A literal here went stale
// (it said 1.2.59 on 1.2.69), and the installer showed the wrong version.
define('BASEHIM_VERSION', preg_match("/define\\('BASEHIM_VERSION',\\s*'([^']+)'\\)/", (string) @file_get_contents(__DIR__ . '/index.php'), $__vm) ? $__vm[1] : '0.0.0');
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

// Nothing here may be cached: the pages carry form tokens and, after a failed
// attempt, the details just typed.
header('Cache-Control: no-store, max-age=0');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

$secure = \App\Core\Request::serverIsSecure();
session_name('BASEHIM_INSTALL');
session_set_cookie_params(['lifetime' => 0, 'path' => (BASEHIM_BASE ?: '') . '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => $secure]);
session_start();

// Load .env if present, but don't insist
$envFile = __DIR__ . '/.env';
if (is_file($envFile)) {
    \App\Core\Env::load($envFile);
    // Env::get() casts an unquoted `true` to boolean true, so the old
    // `=== 'true'` comparison never matched and the installer stayed open on
    // every installed site: anyone could re-run setup and point the site at
    // a database of their own. Fixed in 1.2.43. The raw-file check also
    // covers a hand-edited .env whose INSTALLED line isn't parsed as expected.
    $installedFlag = \App\Core\Env::get('INSTALLED');
    $rawEnv = (string) @file_get_contents($envFile);
    if ($installedFlag === true || in_array(strtolower(trim((string) $installedFlag)), ['true', '1', 'yes', 'on'], true)
        || preg_match('/^\s*INSTALLED\s*=\s*["\']?(true|1|yes|on)/mi', $rawEnv)) {
        // BH-086: the lock used to catch the installer's own "done" screen too,
        // so nobody ever saw it. The browser that has just finished may see it
        // for half an hour; everyone else goes to the home page as before.
        $done = $_SESSION['install_done'] ?? null;
        if (!(($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && ($_GET['step'] ?? '') === 'done'
            && is_array($done) && ($done['at'] ?? 0) > time() - 1800)) {
            header('Location: ' . (BASEHIM_BASE ?: '/'));
            exit;
        }
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

/** HTML-escape. */
function h(mixed $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

/** A Heroicons SVG (core's set, so no icon font or CDN is needed). */
function ico(string $name, string $class = 'ico'): string
{
    return \App\Core\Icon::svg($name, $class);
}

function installerConnect(array $cfg): PDO
{
    $dsn = "mysql:host={$cfg['DB_HOST']};port={$cfg['DB_PORT']};dbname={$cfg['DB_DATABASE']};charset=utf8mb4";
    return new PDO($dsn, $cfg['DB_USERNAME'], $cfg['DB_PASSWORD'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 10,
        // Pdo\Mysql::ATTR_INIT_COMMAND on PHP 8.4+; the old name is deprecated in 8.5 (BH-106).
        (defined('Pdo\\Mysql::ATTR_INIT_COMMAND') ? (int) constant('Pdo\\Mysql::ATTR_INIT_COMMAND') : (int) PDO::MYSQL_ATTR_INIT_COMMAND) => "SET time_zone = '+00:00'",
    ]);
}

function uuid4(): string
{
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}

/**
 * A database error in words a site owner can act on. The server's own message
 * is kept and shown under "Technical details".
 *
 * @return array{title: string, hint: string, detail: string}
 */
function explainDbError(PDOException $e, array $cfg): array
{
    $msg = $e->getMessage();
    $code = (int) (preg_match('/\[(\d{4})\]/', $msg, $m) ? $m[1] : (int) ($e->errorInfo[1] ?? 0));
    $db = $cfg['DB_DATABASE'] ?? '';
    $where = ($cfg['DB_HOST'] ?? '') . ':' . ($cfg['DB_PORT'] ?? '');
    return match (true) {
        $code === 1045 => ['title' => 'The database server refused this user name or password.',
            'hint' => 'Check both for typos. On cPanel the user name usually starts with your account name, like <code>cpaneluser_bh</code>.', 'detail' => $msg],
        $code === 1044 || $code === 1142 || $code === 1049 => ['title' => 'This user can\'t open a database called ' . h($db) . '.',
            'hint' => 'Check the name, including any prefix your control panel adds (like <code>cpaneluser_</code>). If it\'s right, give the user all privileges on it; on cPanel: <strong>MySQL Databases → Add User To Database</strong>, then tick <strong>All Privileges</strong>.', 'detail' => $msg],
        in_array($code, [2002, 2003, 2005, 2006], true) || str_contains($msg, 'getaddrinfo') || str_contains($msg, 'Connection refused') =>
            ['title' => 'No database server answered at ' . h($where) . '.',
            'hint' => 'On shared hosting the host is usually <code>localhost</code> and the port <code>3306</code>. If <code>localhost</code> doesn\'t work, try <code>127.0.0.1</code>, or ask your host for the database host name.', 'detail' => $msg],
        default => ['title' => 'The database server returned an error.', 'hint' => 'The details below say what it objected to.', 'detail' => $msg],
    };
}

// --- Requirements -----------------------------------------------------------

/**
 * What the server has. 'required' rows block the installer; the others are
 * shown in amber and don't (BH-087: GD said "recommended" but blocked).
 */
function checkRequirements(): array
{
    // PHP and the extensions come from App\Core\Requirements, the list
    // System → Health reads too (1.2.124). The folders are the installer's own.
    $procOpen = function_exists('proc_open') && !in_array('proc_open', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true);
    $min = \App\Core\Requirements::phpMinLabel();
    $rows = [
        ['PHP ' . $min . ' or newer', \App\Core\Requirements::phpOk(), true, PHP_VERSION, 'Choose PHP ' . \App\Core\Requirements::PHP_RECOMMENDED . ' in your control panel (often <strong>Select PHP Version</strong> or <strong>MultiPHP Manager</strong>).'],
    ];
    $required = $recommended = [];
    foreach (\App\Core\Requirements::extensions() as $e) {
        $code = '<code>' . htmlspecialchars($e['extension'], ENT_QUOTES, 'UTF-8') . '</code>';
        $row = [$e['label'], $e['loaded'], $e['required'], null,
            htmlspecialchars($e['purpose'], ENT_QUOTES, 'UTF-8') . ' Enable the ' . $code . ' extension in your control panel, or ask your host.'];
        if ($e['required']) $required[] = $row; else $recommended[] = $row;
    }
    // Required first, so what blocks the install is at the top.
    $rows = array_merge($rows, $required, [
        ['storage/ is writable', is_writable(__DIR__ . '/storage'), true, null, 'Let the web server write to the <code>storage</code> folder (permissions 755 or 775; never 777).'],
        ['Site folder is writable', is_writable(__DIR__), true, null, 'The installer saves its settings file, <code>.env</code>, here. Make the folder writable while you install, or create <code>.env</code> from <code>.env.example</code> yourself.'],
    ], $recommended, [
        ['content/ is writable', is_writable(__DIR__ . '/content'), false, null, 'Needed to install themes and apps from the admin. Uploading them by FTP still works without it.'],
        ['proc_open', $procOpen, false, null, 'Lets Basehim test an app in a separate process before switching it on. Without it, apps are checked in the same process.'],
    ]);
    return array_map(static fn(array $r): array => [
        'name' => $r[0], 'ok' => (bool) $r[1], 'required' => $r[2],
        'value' => $r[3] ?? ($r[1] ? 'OK' : ($r[2] ? 'Missing' : 'Recommended')), 'fix' => $r[4],
    ], $rows);
}

// --- Table prefix -----------------------------------------------------------

/**
 * The table prefix for this install: three random characters and an
 * underscore, e.g. "k7q_". The first is a letter so the name never starts with
 * a digit. A prefix is used only if no table in the database starts with it,
 * so the install step (which drops this install's tables before creating them,
 * to make a retry safe) can never touch another site's tables.
 *
 * Kept for the rest of the wizard: going back to the database step and on
 * again, or retrying the install, reuses the same prefix.
 *
 * DB_PREFIX already set in .env (by a host or a deploy script, as the README
 * describes) wins over a random one.
 */
function installerPrefix(PDO $pdo, ?array $previous, array $cfg): string
{
    $fromEnv = (string) (\App\Core\Env::get('DB_PREFIX', '') ?? '');
    if ($fromEnv !== '' && preg_match('/^[A-Za-z0-9_]{1,32}$/', $fromEnv)) {
        return $fromEnv;
    }
    $taken = static function (string $prefix) use ($pdo): bool {
        $q = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE ?');
        $q->execute([str_replace('_', '\\_', $prefix) . '%']);
        return (int) $q->fetchColumn() > 0;
    };
    // Same wizard, same database: keep the prefix it already chose, even if a
    // first attempt created some of its tables.
    $prev = (string) ($previous['DB_PREFIX'] ?? '');
    if ($prev !== '' && preg_match('/^[a-z][a-z0-9]{2}_$/', $prev)
        && ($previous['DB_DATABASE'] ?? null) === $cfg['DB_DATABASE']
        && ($previous['DB_HOST'] ?? null) === $cfg['DB_HOST']) {
        return $prev;
    }
    $letters = 'abcdefghijklmnopqrstuvwxyz';
    $chars = $letters . '0123456789';
    for ($i = 0; $i < 50; $i++) {
        $prefix = $letters[random_int(0, 25)] . $chars[random_int(0, 35)] . $chars[random_int(0, 35)] . '_';
        if (!$taken($prefix)) return $prefix;
    }
    throw new PDOException('Could not find a free table prefix in this database.');
}

// --- Install ----------------------------------------------------------------

/** Every table any migration creates, children first. */
const BASEHIM_TABLES = [
    // migrations and auth_remember_tokens were missing until 1.2.43: a retried
    // install kept the first attempt's migration records and then added a
    // second set. idempotency_keys, post_views and rate_limits since 1.2.98.
    'migrations', 'auth_remember_tokens', 'search_index', 'idempotency_keys', 'post_views', 'rate_limits',
    'mcp_oauth_tokens', 'mcp_oauth_codes', 'mcp_oauth_clients',
    'activity_log', 'user_activity_log', 'notifications', 'refresh_tokens',
    'api_keys', 'password_resets', 'auth_login_attempts', 'scheduled_tasks',
    'apps', 'menu_items', 'menus', 'seo_meta', 'settings', 'comments',
    'post_term', 'terms', 'taxonomies', 'post_revisions', 'post_meta',
    'posts', 'media', 'users',
];

/**
 * Creates the tables by running every migration, in filename order.
 *
 * Retry-safe: this install's own tables (its prefix only) are dropped first,
 * so a run that failed half-way can simply be repeated.
 *
 * @return string[] migrations applied
 */
function installSchema(PDO $pdo, array $cfg): array
{
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach (BASEHIM_TABLES as $t) {
        $pdo->exec(pxSql("DROP TABLE IF EXISTS {{$t}}", $cfg));
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');

    // Every migration, not just 001: installing 001 alone once left fresh
    // sites without apps, API keys, MCP, password resets or the scheduler.
    $pdo->exec(pxSql(
        'CREATE TABLE IF NOT EXISTS {migrations} (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `migration` VARCHAR(255) NOT NULL,
            `applied_at` DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        $cfg
    ));

    $files = glob(__DIR__ . '/database/migrations/*.sql') ?: [];
    // Sort by full filename, matching UpdateService exactly. Some numbers
    // repeat (two 002_, two 003_), so the whole name orders them.
    sort($files);

    $record = $pdo->prepare(pxSql('INSERT INTO {migrations} (migration, applied_at) VALUES (?, ?)', $cfg));
    $applied = [];

    // Data repairs for existing sites: a fresh install has nothing to repair,
    // and running them would damage its correct rows. Recorded, not run.
    $recordOnly = ['013_utc_timestamps'];

    foreach ($files as $file) {
        // Recorded without ".sql", the form the runners use. Recording the
        // file name made the first update after an install run every
        // migration a second time.
        $key = preg_replace('/\\.sql$/', '', basename($file));
        if (!in_array($key, $recordOnly, true)) {
            $sql = (string) file_get_contents($file);
            // Strip SQL line comments first, or a chunk that starts with a
            // comment header is rejected along with the CREATE TABLE after it.
            $clean = implode("\n", array_filter(explode("\n", $sql), static fn(string $l): bool => !preg_match('/^\s*--/', $l)));
            // Split on semicolons at end of line (multi-line statements). The
            // PREPARE/EXECUTE blocks in later migrations split into valid
            // statements whose session variables persist on this connection.
            foreach (array_filter(array_map('trim', preg_split('/;\s*[\r\n]+/', $clean))) as $stmt) {
                $pdo->exec(pxSql($stmt, $cfg));
            }
        }
        $record->execute([$key, gmdate('Y-m-d H:i:s')]);
        $applied[] = $key;
    }
    return $applied;
}

/**
 * The first account, starter content, menus and settings. Runs after the
 * schema; .env is written only once this has succeeded.
 */
function seedSite(PDO $pdo, array $cfg, array $site): void
{
    $hash = password_hash($site['password'], PASSWORD_BCRYPT, ['cost' => 12]);
    $pdo->prepare(pxSql("INSERT INTO {users} (uuid, username, email, password_hash, display_name, role, status, email_verified_at) VALUES (?, ?, ?, ?, ?, 'super_admin', 'active', NOW())", $cfg))
        ->execute([uuid4(), $site['username'], $site['email'], $hash, $site['display_name']]);
    $userId = (int) $pdo->lastInsertId();

    // The default taxonomies (Categories + Tags). Without these the Categories
    // and Tags screens 404 and posts can't be classified. "tag", as migration
    // 001 creates and all of core uses.
    $pdo->exec(pxSql("INSERT INTO {taxonomies} (slug, label, singular, hierarchical, show_in_api, post_types) VALUES
        ('category', 'Categories', 'Category', 1, 1, '[\"post\"]'),
        ('tag', 'Tags', 'Tag', 0, 1, '[\"post\"]')
        ON DUPLICATE KEY UPDATE label = VALUES(label)", $cfg));
    $catTaxId = (int) $pdo->query(pxSql("SELECT id FROM {taxonomies} WHERE slug='category'", $cfg))->fetchColumn();
    $uncatId = 0;
    if ($catTaxId > 0) {
        $pdo->prepare(pxSql("INSERT INTO {terms} (taxonomy_id, name, slug, count) VALUES (?, 'Uncategorized', 'uncategorized', 0)
            ON DUPLICATE KEY UPDATE name = VALUES(name)", $cfg))->execute([$catTaxId]);
        $uncatId = (int) $pdo->query(pxSql("SELECT id FROM {terms} WHERE taxonomy_id={$catTaxId} AND slug='uncategorized'", $cfg))->fetchColumn();
    }

    $aboutId = 0;
    if ($site['starter']) {
        $title = h($site['site_title']);
        $welcome = '<p>This is the first post on ' . $title . '. Edit it or delete it, then start writing your own.</p>'
            . '<p>A few things to try first:</p>'
            . '<ul><li><strong>Write.</strong> Open <em>Posts</em> in your dashboard and choose <em>New post</em>. Drafts save as you type.</li>'
            . '<li><strong>Make it yours.</strong> <em>Customize</em> sets your colours, fonts and logo, with a live preview.</li>'
            . '<li><strong>Tell people who you are.</strong> Your <em>About</em> page is ready for your own words.</li>'
            . '<li><strong>Add features.</strong> <em>Apps</em> and the <em>Marketplace</em> add things like forms, SEO tools and analytics.</li></ul>'
            . '<p>Happy publishing!</p>';
        $pdo->prepare(pxSql("INSERT INTO {posts} (uuid, author_id, type, status, slug, title, content, excerpt, published_at) VALUES (?, ?, 'post', 'published', 'hello-world', 'Hello, world!', ?, ?, NOW())", $cfg))
            ->execute([uuid4(), $userId, $welcome, 'The first post on ' . $site['site_title'] . ', with a few things to try first.']);
        $welcomeId = (int) $pdo->lastInsertId();
        if ($uncatId > 0) {
            $pdo->prepare(pxSql('INSERT IGNORE INTO {post_term} (post_id, term_id, term_order) VALUES (?, ?, 0)', $cfg))->execute([$welcomeId, $uncatId]);
        }

        $about = '<p>This is your About page. Visitors often look here first, so tell them who you are, what this site is about and how to reach you.</p>'
            . '<p>Edit it from <em>Pages</em> in your dashboard.</p>';
        $pdo->prepare(pxSql("INSERT INTO {posts} (uuid, author_id, type, status, slug, title, content, excerpt, published_at) VALUES (?, ?, 'page', 'published', 'about', 'About', ?, ?, NOW())", $cfg))
            ->execute([uuid4(), $userId, $about, 'Who we are and what this site is about.']);
        $aboutId = (int) $pdo->lastInsertId();
    }
    if ($uncatId > 0) {
        $pdo->prepare(pxSql('UPDATE {terms} SET count = (SELECT COUNT(*) FROM {post_term} WHERE term_id = ?) WHERE id = ?', $cfg))->execute([$uncatId, $uncatId]);
    }

    // The primary menu (created empty by migration 001): Home, plus About when
    // there is one, so a new site has navigation from the start.
    $menuId = (int) $pdo->query(pxSql("SELECT id FROM {menus} WHERE location = 'primary' ORDER BY id LIMIT 1", $cfg))->fetchColumn();
    if ($menuId > 0 && (int) $pdo->query(pxSql("SELECT COUNT(*) FROM {menu_items} WHERE menu_id = {$menuId}", $cfg))->fetchColumn() === 0) {
        $item = $pdo->prepare(pxSql('INSERT INTO {menu_items} (menu_id, type, object_id, title, url, target, menu_order) VALUES (?, ?, ?, ?, ?, ?, ?)', $cfg));
        $item->execute([$menuId, 'custom', null, 'Home', '/', '_self', 0]);
        if ($aboutId > 0) $item->execute([$menuId, 'page', $aboutId, 'About', '/about', '_self', 1]);
    }

    // Baseline settings. The migrations seed some of these rows and not
    // others, so upsert.
    $seed = [
        ['general', 'site_title', $site['site_title']],
        ['general', 'tagline', $site['tagline']],
        ['general', 'admin_email', $site['email']],
        ['general', 'timezone', $site['timezone']],
        ['appearance', 'active_theme', 'default'],
    ];
    // Send mail as noreply@ the site's own domain. Without a sender on this
    // domain the host's mail server refuses or spam-files everything —
    // password resets and sign-in codes included.
    $mailHost = strtolower((string) (parse_url($site['app_url'], PHP_URL_HOST) ?: ''));
    if (str_starts_with($mailHost, 'www.')) $mailHost = substr($mailHost, 4);
    if ($mailHost !== '' && str_contains($mailHost, '.') && preg_match('/^[a-z0-9.-]+$/', $mailHost) && !filter_var($mailHost, FILTER_VALIDATE_IP)) {
        $seed[] = ['email', 'from_email', 'noreply@' . $mailHost];
    }
    $set = $pdo->prepare(pxSql(
        'INSERT INTO {settings} (setting_group, setting_key, setting_value, is_json, autoload) VALUES (?, ?, ?, 0, 1)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)',
        $cfg
    ));
    foreach ($seed as [$g, $k, $v]) {
        $set->execute([$g, $k, (string) $v]);
    }
}

/** A value for .env: quoted, with nothing that could end the line. */
function envValue(string $v): string
{
    return '"' . str_replace(["\r", "\n", "\0"], '', $v) . '"';
}

function writeEnv(array $cfg, array $site): void
{
    $lines = [
        'APP_NAME=' . envValue($site['site_title']),
        'APP_ENV=production',
        'APP_DEBUG=false',
        'APP_URL=' . $site['app_url'],
        'APP_TIMEZONE=UTC',
        'APP_LOCALE=en',
        'APP_KEY=' . bin2hex(random_bytes(16)),
        '',
        'DB_DRIVER=mysql',
        'DB_HOST=' . $cfg['DB_HOST'],
        'DB_PORT=' . $cfg['DB_PORT'],
        'DB_DATABASE=' . envValue($cfg['DB_DATABASE']),
        'DB_USERNAME=' . envValue($cfg['DB_USERNAME']),
        'DB_PASSWORD=' . envValue($cfg['DB_PASSWORD']),
        'DB_CHARSET=utf8mb4',
        'DB_COLLATION=utf8mb4_unicode_ci',
        'DB_PREFIX=' . $cfg['DB_PREFIX'],
        '',
        'JWT_SECRET=' . bin2hex(random_bytes(32)),
        '',
        'SITE_TITLE=' . envValue($site['site_title']),
        'ADMIN_EMAIL=' . $site['email'],
        '',
    ];
    // Keep whatever else a host or deploy script already put in .env
    // (TRUSTED_PROXIES, MAIL_*, …): only the keys written above are replaced.
    $existing = __DIR__ . '/.env';
    if (is_file($existing)) {
        $ours = [];
        foreach ($lines as $l) if (str_contains($l, '=')) $ours[strtok($l, '=')] = true;
        $kept = [];
        foreach (file($existing, FILE_IGNORE_NEW_LINES) ?: [] as $l) {
            $k = trim((string) strtok($l, '='));
            if (str_contains($l, '=') && $k !== '' && $k[0] !== '#' && !isset($ours[$k]) && $k !== 'INSTALLED') $kept[] = $l;
        }
        if ($kept) array_push($lines, '', '# Kept from the .env that was here before installing', ...$kept);
    }
    $lines[] = '';
    $lines[] = 'INSTALLED=true';
    // End with a newline. Without one, anything later appended to .env (a
    // host panel, a deploy script, `echo X=1 >> .env`) was glued onto
    // INSTALLED=true, and the site went back to setup.
    if (@file_put_contents(__DIR__ . '/.env', implode("\n", $lines) . "\n", LOCK_EX) === false) {
        throw new RuntimeException('The settings file .env could not be written in ' . __DIR__ . '. Make the folder writable and try again; nothing is lost.');
    }
    @chmod(__DIR__ . '/.env', 0640);
}

/** The address this browser used to reach the installer, as a suggestion. */
function suggestedUrl(): string
{
    // BH-089: Request::serverIsSecure() rather than the raw HTTPS server variable, so a
    // site behind Cloudflare is suggested https://. The page's script also
    // replaces this with the address bar's own origin, which is right behind
    // any proxy.
    $scheme = \App\Core\Request::serverIsSecure() ? 'https' : 'http';
    $host = preg_replace('/[^A-Za-z0-9.:\[\]-]/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    return $scheme . '://' . $host . BASEHIM_BASE;
}

// --- Requests ---------------------------------------------------------------

$steps = ['check' => 'Check', 'database' => 'Database', 'site' => 'Your site', 'done' => 'Done'];
$step = (string) ($_GET['step'] ?? 'check');
// Old links (?step=2 …) still land somewhere sensible.
$step = ['1' => 'check', '2' => 'database', '3' => 'site', '4' => 'site', '5' => 'done'][$step] ?? $step;
if (!isset($steps[$step])) $step = 'check';
if ($step === 'done' && empty($_SESSION['install_done'])) $step = 'check';
if ($step === 'site' && empty($_SESSION['install_db_config'])) $step = 'database';

if (empty($_SESSION['install_csrf'])) $_SESSION['install_csrf'] = bin2hex(random_bytes(16));
$csrf = (string) $_SESSION['install_csrf'];

$error = null;      // ['title' => …, 'hint' => …, 'detail' => …]
$fieldErrors = [];  // field => message
$old = [];          // values to show again

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if (!hash_equals($csrf, (string) ($_POST['_csrf'] ?? ''))) {
        $error = ['title' => 'This form has expired.', 'hint' => 'Your session ended or the page was open in another tab. Check the details and submit again.', 'detail' => ''];
        $step = $action === 'install' ? 'site' : 'database';
        $old = $_POST;
        unset($old['password'], $old['_csrf']);
    } elseif ($action === 'database') {
        $step = 'database';
        $old = $_POST;
        $cfg = [
            'DB_HOST'     => trim((string) ($_POST['db_host'] ?? 'localhost')) ?: 'localhost',
            'DB_PORT'     => trim((string) ($_POST['db_port'] ?? '3306')) ?: '3306',
            'DB_DATABASE' => trim((string) ($_POST['db_database'] ?? '')),
            'DB_USERNAME' => trim((string) ($_POST['db_username'] ?? '')),
            'DB_PASSWORD' => (string) ($_POST['db_password'] ?? ''),
            'DB_PREFIX'   => '',
        ];
        if (!preg_match('/^[A-Za-z0-9._:\[\]-]{1,255}$/', $cfg['DB_HOST'])) $fieldErrors['db_host'] = 'Enter a host name, such as localhost.';
        if (!ctype_digit($cfg['DB_PORT']) || (int) $cfg['DB_PORT'] < 1 || (int) $cfg['DB_PORT'] > 65535) $fieldErrors['db_port'] = 'Enter a port number, usually 3306.';
        if ($cfg['DB_DATABASE'] === '' || !preg_match('/^[^\/\\\\.\x00]{1,64}$/', $cfg['DB_DATABASE'])) $fieldErrors['db_database'] = 'Enter the database name.';
        if ($cfg['DB_USERNAME'] === '') $fieldErrors['db_username'] = 'Enter the database user name.';
        if (preg_match('/[\r\n\0]/', $cfg['DB_PASSWORD'])) $fieldErrors['db_password'] = 'The password can\'t contain line breaks.';

        if (!$fieldErrors) {
            try {
                $pdo = installerConnect($cfg);
                $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
                $isMaria = stripos($version, 'mariadb') !== false;
                $num = preg_match('/^(\d+\.\d+(?:\.\d+)?)/', $version, $vm) ? $vm[1] : '0';
                if (version_compare($num, $isMaria ? '10.3' : '5.7', '<')) {
                    throw new DomainException(($isMaria ? 'MariaDB' : 'MySQL') . ' ' . $num . ' is too old. Basehim needs MySQL 5.7 or MariaDB 10.3 or newer; ask your host about a newer database server.');
                }
                $cfg['DB_PREFIX'] = installerPrefix($pdo, $_SESSION['install_db_config'] ?? null, $cfg);
                // Can this user create tables? Better to know now than half-way
                // through the install.
                $probe = pxSql('{' . 'bh_probe' . '}', $cfg);
                $pdo->exec("CREATE TABLE IF NOT EXISTS {$probe} (id INT) ENGINE=InnoDB");
                $pdo->exec("DROP TABLE IF EXISTS {$probe}");
                $_SESSION['install_db_config'] = $cfg;
                $_SESSION['install_db_version'] = ($isMaria ? 'MariaDB ' : 'MySQL ') . $num;
                header('Location: install.php?step=site');
                exit;
            } catch (DomainException $e) {
                $error = ['title' => 'This database server is too old.', 'hint' => h($e->getMessage()), 'detail' => ''];
            } catch (PDOException $e) {
                $error = explainDbError($e, $cfg);
            }
        }
    } elseif ($action === 'install') {
        $step = 'site';
        $old = $_POST;
        unset($old['password']);
        $cfg = $_SESSION['install_db_config'] ?? null;
        $site = [
            'site_title'   => trim(preg_replace('/[\x00-\x1F\x7F]/u', '', (string) ($_POST['site_title'] ?? '')) ?? ''),
            'tagline'      => trim(preg_replace('/[\x00-\x1F\x7F]/u', '', (string) ($_POST['tagline'] ?? '')) ?? ''),
            'app_url'      => rtrim(trim((string) ($_POST['app_url'] ?? '')), '/'),
            'timezone'     => (string) ($_POST['timezone'] ?? 'UTC'),
            'username'     => trim((string) ($_POST['username'] ?? '')),
            'display_name' => trim(preg_replace('/[\x00-\x1F\x7F]/u', '', (string) ($_POST['display_name'] ?? '')) ?? ''),
            'email'        => trim((string) ($_POST['email'] ?? '')),
            'password'     => (string) ($_POST['password'] ?? ''),
            'starter'      => !empty($_POST['starter']),
        ];
        if ($site['site_title'] === '') $fieldErrors['site_title'] = 'Give your site a name. You can change it later.';
        elseif (mb_strlen($site['site_title']) > 120) $fieldErrors['site_title'] = 'Keep the name under 120 characters.';
        if (mb_strlen($site['tagline']) > 200) $fieldErrors['tagline'] = 'Keep the tagline under 200 characters.';
        $u = parse_url($site['app_url']);
        if (!is_array($u) || !in_array(strtolower((string) ($u['scheme'] ?? '')), ['http', 'https'], true) || empty($u['host']) || isset($u['query']) || isset($u['fragment'])
            || preg_match('/[\s"\'<>]/', $site['app_url'])) {
            $fieldErrors['app_url'] = 'Enter the full address, starting with https:// (or http://).';
        }
        if (!in_array($site['timezone'], DateTimeZone::listIdentifiers(), true)) $fieldErrors['timezone'] = 'Choose a timezone.';
        if (!preg_match('/^[a-zA-Z0-9_.-]{3,60}$/', $site['username'])) $fieldErrors['username'] = 'Use 3 to 60 letters, numbers, dots, dashes or underscores.';
        if (!filter_var($site['email'], FILTER_VALIDATE_EMAIL)) $fieldErrors['email'] = 'Enter a valid email address. Password resets go here.';
        if (strlen($site['password']) < 8) $fieldErrors['password'] = 'Use at least 8 characters.';
        elseif (strlen($site['password']) > 200) $fieldErrors['password'] = 'Use at most 200 characters.';
        elseif (strcasecmp($site['password'], $site['username']) === 0) $fieldErrors['password'] = 'Don\'t use your username as the password.';
        if ($site['display_name'] === '') $site['display_name'] = $site['username'];

        if (!$cfg) {
            $error = ['title' => 'The database details have expired.', 'hint' => 'Enter them again on the Database step.', 'detail' => ''];
            $step = 'database';
        } elseif (!$fieldErrors) {
            try {
                @set_time_limit(300);
                $pdo = installerConnect($cfg);
                $applied = installSchema($pdo, $cfg);
                seedSite($pdo, $cfg, $site);
                writeEnv($cfg, $site);
                unset($_SESSION['install_db_config'], $_SESSION['install_db_version']);
                session_regenerate_id(true);
                $_SESSION['install_done'] = [
                    'at' => time(), 'site_title' => $site['site_title'], 'username' => $site['username'],
                    'url' => $site['app_url'], 'tables' => count(BASEHIM_TABLES), 'migrations' => count($applied),
                    'starter' => $site['starter'],
                ];
                header('Location: install.php?step=done');
                exit;
            } catch (PDOException $e) {
                $error = explainDbError($e, $cfg);
                $error['title'] = 'Setting up the database didn\'t finish. ' . $error['title'];
                $error['hint'] .= ' It\'s safe to try again: the install starts over and only touches its own tables.';
            } catch (RuntimeException $e) {
                $error = ['title' => 'Almost there: the settings file couldn\'t be saved.', 'hint' => h($e->getMessage()), 'detail' => ''];
            }
        }
    }
}

$requirements = $step === 'check' ? checkRequirements() : [];
$blocked = $step === 'check' && in_array(true, array_map(static fn($r) => $r['required'] && !$r['ok'], $requirements), true);
$warnings = $step === 'check' ? count(array_filter($requirements, static fn($r) => !$r['required'] && !$r['ok'])) : 0;
$cfgNow = $_SESSION['install_db_config'] ?? [];
$done = $_SESSION['install_done'] ?? [];
$stepKeys = array_keys($steps);
$stepIndex = (int) array_search($step, $stepKeys, true);
$val = static fn(string $k, string $default = ''): string => h($old[$k] ?? $default);
$fieldError = static function (string $k) use ($fieldErrors): string {
    return isset($fieldErrors[$k]) ? '<p class="field-error" id="' . h($k) . '-error">' . ico('exclamation-circle', 'ico ico--sm') . h($fieldErrors[$k]) . '</p>' : '';
};
$invalid = static fn(string $k): string => isset($fieldErrors[$k]) ? ' aria-invalid="true" aria-describedby="' . h($k) . '-error"' : '';
$asset = static fn(string $p): string => h(BASEHIM_BASE . '/admin/assets/' . $p . '?v=' . rawurlencode(BASEHIM_VERSION));

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?= h($steps[$step]) ?> · Set up Basehim</title>
    <link rel="icon" href="<?= $asset('img/favicon-32.png') ?>">
    <link rel="stylesheet" href="<?= $asset('css/installer.css') ?>">
</head>
<body>
<div class="shell">
    <header class="brand">
        <img src="<?= $asset('img/logo-128.png') ?>" alt="" width="56" height="56" class="brand__logo">
        <div>
            <h1 class="brand__title">Set up Basehim</h1>
            <p class="brand__sub">Version <?= h(BASEHIM_VERSION) ?> · about a minute</p>
        </div>
    </header>

    <ol class="steps" aria-label="Installation steps">
        <?php foreach ($stepKeys as $i => $key): ?>
            <?php $state = $i < $stepIndex ? 'done' : ($i === $stepIndex ? 'current' : 'todo'); ?>
            <li class="steps__item steps__item--<?= $state ?>"<?= $state === 'current' ? ' aria-current="step"' : '' ?>>
                <span class="steps__dot"><?= $state === 'done' ? ico('check', 'ico ico--sm') : $i + 1 ?></span>
                <span class="steps__label"><?= h($steps[$key]) ?></span>
            </li>
        <?php endforeach; ?>
    </ol>

    <main class="card">
        <?php if ($error): ?>
            <div class="alert alert--error" role="alert">
                <?= ico('x-circle', 'ico alert__icon') ?>
                <div>
                    <p class="alert__title"><?= $error['title'] ?></p>
                    <?php if ($error['hint'] !== ''): ?><p class="alert__text"><?= $error['hint'] ?></p><?php endif; ?>
                    <?php if ($error['detail'] !== ''): ?>
                        <details class="alert__details"><summary>Technical details</summary><code><?= h($error['detail']) ?></code></details>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($step === 'check'): ?>
            <h2 class="card__title">Welcome! First, a quick check of your server</h2>
            <p class="card__lead">Basehim needs a few things from your hosting. Everything marked required must pass; recommended items can wait.</p>

            <ul class="checks">
                <?php foreach ($requirements as $r): ?>
                    <?php $tone = $r['ok'] ? 'ok' : ($r['required'] ? 'fail' : 'warn'); ?>
                    <li class="check check--<?= $tone ?>">
                        <?= ico($tone === 'ok' ? 'check-circle' : ($tone === 'fail' ? 'x-circle' : 'exclamation-triangle'), 'ico check__icon') ?>
                        <div class="check__body">
                            <div class="check__row">
                                <span class="check__name"><?= h($r['name']) ?><?php if (!$r['required']): ?> <span class="tag">Recommended</span><?php endif; ?></span>
                                <span class="check__value"><?= h($r['value']) ?></span>
                            </div>
                            <?php if (!$r['ok']): ?><p class="check__fix"><?= $r['fix'] ?></p><?php endif; ?>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>

            <div class="actions">
                <?php if ($blocked): ?>
                    <p class="actions__note">Fix the items in red, then <a href="install.php">check again</a>.</p>
                    <a href="install.php" class="btn btn--secondary"><?= ico('arrow-path') ?>Check again</a>
                <?php else: ?>
                    <p class="actions__note"><?= $warnings ? h($warnings . ' recommended ' . ($warnings === 1 ? 'item is' : 'items are') . ' missing. You can add ' . ($warnings === 1 ? 'it' : 'them') . ' later.') : 'Everything Basehim needs is here.' ?></p>
                    <a href="install.php?step=database" class="btn btn--primary">Continue<?= ico('arrow-right') ?></a>
                <?php endif; ?>
            </div>

        <?php elseif ($step === 'database'): ?>
            <form method="post" action="install.php?step=database" class="form" data-busy="Connecting…" novalidate>
                <input type="hidden" name="action" value="database">
                <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
                <h2 class="card__title">Connect your database</h2>
                <p class="card__lead">Create an empty MySQL or MariaDB database and a user in your hosting control panel first (on cPanel: <strong>MySQL Databases</strong>), and give the user all privileges on it.</p>

                <div class="grid">
                    <div class="field field--wide">
                        <label for="db_database">Database name</label>
                        <input id="db_database" name="db_database" required autocomplete="off" spellcheck="false" value="<?= $val('db_database', $cfgNow['DB_DATABASE'] ?? '') ?>" placeholder="cpaneluser_basehim"<?= $invalid('db_database') ?>>
                        <?= $fieldError('db_database') ?: '<p class="help">Exactly as your control panel shows it, including any prefix it adds.</p>' ?>
                    </div>
                    <div class="field">
                        <label for="db_username">User name</label>
                        <input id="db_username" name="db_username" required autocomplete="off" spellcheck="false" value="<?= $val('db_username', $cfgNow['DB_USERNAME'] ?? '') ?>"<?= $invalid('db_username') ?>>
                        <?= $fieldError('db_username') ?>
                    </div>
                    <div class="field">
                        <label for="db_password">Password</label>
                        <div class="reveal">
                            <input id="db_password" name="db_password" type="password" autocomplete="off" value="<?= $val('db_password') ?>"<?= $invalid('db_password') ?>>
                            <button type="button" class="reveal__btn" data-reveal="db_password" aria-label="Show password"><?= ico('eye') ?></button>
                        </div>
                        <?= $fieldError('db_password') ?>
                    </div>
                </div>

                <details class="more"<?= isset($fieldErrors['db_host']) || isset($fieldErrors['db_port']) || ($old['db_host'] ?? 'localhost') !== 'localhost' || ($old['db_port'] ?? '3306') !== '3306' || ($error && str_contains($error['title'], 'answered')) ? ' open' : '' ?>>
                    <summary><?= ico('chevron-down', 'ico ico--sm more__chev') ?>Server <span class="more__value"><?= $val('db_host', $cfgNow['DB_HOST'] ?? 'localhost') ?>:<?= $val('db_port', $cfgNow['DB_PORT'] ?? '3306') ?></span></summary>
                    <div class="grid">
                        <div class="field">
                            <label for="db_host">Host</label>
                            <input id="db_host" name="db_host" required spellcheck="false" value="<?= $val('db_host', $cfgNow['DB_HOST'] ?? 'localhost') ?>"<?= $invalid('db_host') ?>>
                            <?= $fieldError('db_host') ?: '<p class="help"><code>localhost</code> on almost all shared hosting.</p>' ?>
                        </div>
                        <div class="field">
                            <label for="db_port">Port</label>
                            <input id="db_port" name="db_port" required inputmode="numeric" value="<?= $val('db_port', $cfgNow['DB_PORT'] ?? '3306') ?>"<?= $invalid('db_port') ?>>
                            <?= $fieldError('db_port') ?>
                        </div>
                    </div>
                </details>

                <p class="note"><?= ico('lock-closed', 'ico ico--sm') ?><span>Several sites can share one database: each gets its own table prefix, and Basehim never touches other tables.</span></p>

                <div class="actions">
                    <a href="install.php" class="btn btn--ghost"><?= ico('arrow-left') ?>Back</a>
                    <button type="submit" class="btn btn--primary">Test and continue<?= ico('arrow-right') ?></button>
                </div>
            </form>

        <?php elseif ($step === 'site'): ?>
            <form method="post" action="install.php?step=site" class="form" data-busy="Setting up your site…" novalidate>
                <input type="hidden" name="action" value="install">
                <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
                <h2 class="card__title">Tell us about your site</h2>
                <p class="card__lead">
                    <?= ico('check-circle', 'ico ico--sm ico--ok') ?>Connected to <strong><?= h($cfgNow['DB_DATABASE'] ?? '') ?></strong><?= !empty($_SESSION['install_db_version']) ? ' (' . h($_SESSION['install_db_version']) . ')' : '' ?>. <a href="install.php?step=database">Change</a>
                </p>

                <fieldset class="group">
                    <legend>Your site</legend>
                    <div class="grid">
                        <div class="field field--wide">
                            <label for="site_title">Site name</label>
                            <input id="site_title" name="site_title" required maxlength="120" value="<?= $val('site_title') ?>" placeholder="Northwind Studio"<?= $invalid('site_title') ?>>
                            <?= $fieldError('site_title') ?>
                        </div>
                        <div class="field field--wide">
                            <label for="tagline">Tagline <span class="optional">optional</span></label>
                            <input id="tagline" name="tagline" maxlength="200" value="<?= $val('tagline') ?>" placeholder="Design and print, made in Lisbon"<?= $invalid('tagline') ?>>
                            <?= $fieldError('tagline') ?: '<p class="help">A few words under your site\'s name.</p>' ?>
                        </div>
                        <div class="field">
                            <label for="app_url">Address</label>
                            <input id="app_url" name="app_url" type="url" required spellcheck="false" value="<?= $val('app_url', suggestedUrl()) ?>" data-suggest="<?= h(BASEHIM_BASE) ?>"<?= isset($old['app_url']) ? '' : ' data-autofill' ?><?= $invalid('app_url') ?>>
                            <?= $fieldError('app_url') ?: '<p class="help">Use <code>https://</code> if your site has a certificate.</p>' ?>
                        </div>
                        <div class="field">
                            <label for="timezone">Timezone</label>
                            <select id="timezone" name="timezone"<?= isset($old['timezone']) ? '' : ' data-autofill' ?><?= $invalid('timezone') ?>>
                                <?php $tzNow = (string) ($old['timezone'] ?? 'UTC'); foreach (DateTimeZone::listIdentifiers() as $tz): ?>
                                    <option value="<?= h($tz) ?>"<?= $tz === $tzNow ? ' selected' : '' ?>><?= h(str_replace('_', ' ', $tz)) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?= $fieldError('timezone') ?: '<p class="help">For post dates and scheduling.</p>' ?>
                        </div>
                    </div>
                </fieldset>

                <fieldset class="group">
                    <legend>Your account</legend>
                    <div class="grid">
                        <div class="field">
                            <label for="username">Username</label>
                            <input id="username" name="username" required minlength="3" maxlength="60" autocomplete="username" spellcheck="false" autocapitalize="none" value="<?= $val('username') ?>"<?= $invalid('username') ?>>
                            <?= $fieldError('username') ?>
                        </div>
                        <div class="field">
                            <label for="display_name">Your name <span class="optional">optional</span></label>
                            <input id="display_name" name="display_name" maxlength="120" autocomplete="name" value="<?= $val('display_name') ?>">
                            <p class="help">Shown on your posts.</p>
                        </div>
                        <div class="field field--wide">
                            <label for="email">Email</label>
                            <input id="email" name="email" type="email" required autocomplete="email" spellcheck="false" value="<?= $val('email') ?>"<?= $invalid('email') ?>>
                            <?= $fieldError('email') ?: '<p class="help">For password resets and sign-in codes.</p>' ?>
                        </div>
                        <div class="field field--wide">
                            <label for="password">Password</label>
                            <div class="reveal">
                                <input id="password" name="password" type="password" required minlength="8" autocomplete="new-password" aria-describedby="pw-meter"<?= $invalid('password') ?>>
                                <button type="button" class="reveal__btn" data-reveal="password" aria-label="Show password"><?= ico('eye') ?></button>
                            </div>
                            <div class="meter" id="pw-meter" aria-live="polite"><span class="meter__bar"><span class="meter__fill"></span></span><span class="meter__text">At least 8 characters. Longer is stronger.</span></div>
                            <?= $fieldError('password') ?>
                        </div>
                    </div>
                </fieldset>

                <label class="toggle">
                    <input type="checkbox" name="starter" value="1"<?= !isset($old['action']) || !empty($old['starter']) ? ' checked' : '' ?>>
                    <span class="toggle__track" aria-hidden="true"></span>
                    <span><strong>Add a welcome post and an About page</strong><br><span class="help">So your site isn't empty on day one. Edit or delete them any time.</span></span>
                </label>

                <div class="actions">
                    <a href="install.php?step=database" class="btn btn--ghost"><?= ico('arrow-left') ?>Back</a>
                    <button type="submit" class="btn btn--primary"><?= ico('sparkles') ?>Install Basehim</button>
                </div>
            </form>

        <?php elseif ($step === 'done'): ?>
            <div class="done">
                <div class="done__badge"><?= ico('check', 'ico ico--lg') ?></div>
                <h2 class="card__title">“<?= h($done['site_title'] ?? 'Your site') ?>” is ready</h2>
                <p class="card__lead">Sign in with <strong><?= h($done['username'] ?? '') ?></strong> and the password you just chose.</p>
                <div class="actions actions--center">
                    <a href="<?= h(BASEHIM_BASE) ?>/admin/login" class="btn btn--primary btn--lg"><?= ico('arrow-right-on-rectangle') ?>Sign in to your dashboard</a>
                    <a href="<?= h(BASEHIM_BASE) ?>/" class="btn btn--secondary btn--lg" target="_blank" rel="noopener"><?= ico('arrow-top-right-on-square') ?>View your site</a>
                </div>
            </div>

            <h3 class="next__title">Good first steps</h3>
            <ul class="next">
                <li><a href="<?= h(BASEHIM_BASE) ?>/admin/posts/create"><?= ico('pencil-square', 'ico next__icon') ?><span><strong>Write your first post</strong><span>The editor saves drafts as you type.</span></span></a></li>
                <li><a href="<?= h(BASEHIM_BASE) ?>/admin/customize"><?= ico('paint-brush', 'ico next__icon') ?><span><strong>Make it look like yours</strong><span>Colours, fonts and your logo, with a live preview.</span></span></a></li>
                <li><a href="<?= h(BASEHIM_BASE) ?>/admin/settings/email"><?= ico('envelope', 'ico next__icon') ?><span><strong>Check that email works</strong><span>Password resets and notifications depend on it.</span></span></a></li>
                <li><a href="<?= h(BASEHIM_BASE) ?>/admin/users/create"><?= ico('users', 'ico next__icon') ?><span><strong>Invite your team</strong><span>Editors, authors and contributors each get the right access.</span></span></a></li>
            </ul>

            <p class="note"><?= ico('shield-check', 'ico ico--sm') ?><span>The installer has locked itself. You can delete <code>install.php</code> from your server if you like. Keep <code>.env</code> private: it holds your database password and the site's secret keys.</span></p>
        <?php endif; ?>
    </main>

    <footer class="foot">Basehim <?= h(BASEHIM_VERSION) ?> · PHP <?= h(PHP_VERSION) ?> · <a href="https://www.basehim.com/docs/installation" target="_blank" rel="noopener">Installation guide</a></footer>
</div>

<script>
(function () {
    // Show or hide a password.
    document.querySelectorAll('[data-reveal]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var input = document.getElementById(btn.getAttribute('data-reveal'));
            var show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
            btn.classList.toggle('is-on', show);
        });
    });

    // Suggest the address this page was opened at (right behind any proxy) and
    // the browser's timezone, unless the person has already chosen.
    var url = document.querySelector('#app_url[data-autofill]');
    if (url) url.value = location.origin + (url.getAttribute('data-suggest') || '');
    var tz = document.querySelector('#timezone[data-autofill]');
    try {
        var zone = Intl.DateTimeFormat().resolvedOptions().timeZone;
        if (tz && zone && tz.querySelector('option[value="' + zone + '"]')) tz.value = zone;
    } catch (e) { /* keep UTC */ }

    // Password strength: a guide, not a rule (the rule is 8 characters).
    var pw = document.getElementById('password');
    var meter = document.getElementById('pw-meter');
    if (pw && meter) {
        var labels = ['At least 8 characters. Longer is stronger.', 'Weak: add length, or mix in numbers and symbols.', 'Fair: a few more characters would help.', 'Good.', 'Strong.'];
        var rate = function () {
            var v = pw.value, s = 0;
            if (v.length >= 8) {
                s = 1;
                if (v.length >= 12) s++;
                if (v.length >= 16) s++;
                var kinds = [/[a-z]/, /[A-Z]/, /\d/, /[^A-Za-z0-9]/].filter(function (r) { return r.test(v); }).length;
                if (kinds >= 3) s++;
                var user = (document.getElementById('username') || {}).value || '';
                if (/^(.)\1+$/.test(v) || /^(password|12345678|qwerty)/i.test(v) || (user.length >= 3 && v.toLowerCase().indexOf(user.toLowerCase()) !== -1)) s = 1;
            }
            s = Math.min(s, 4);
            meter.setAttribute('data-score', v ? String(s) : '');
            meter.querySelector('.meter__text').textContent = v ? labels[s] : labels[0];
        };
        pw.addEventListener('input', rate);
    }

    // One submit per click, with a visible "working" state.
    document.querySelectorAll('form[data-busy]').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            if (form.dataset.sent) { e.preventDefault(); return; }
            // Let the browser point at the first empty required field.
            if (!form.checkValidity()) { e.preventDefault(); form.reportValidity(); return; }
            form.dataset.sent = '1';
            var btn = form.querySelector('button[type=submit]');
            if (btn) {
                btn.disabled = true;
                btn.classList.add('is-busy');
                btn.innerHTML = '<span class="spinner" aria-hidden="true"></span>' + form.getAttribute('data-busy');
            }
        });
    });
})();
</script>
</body>
</html>
