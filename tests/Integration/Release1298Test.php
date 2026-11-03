<?php

declare(strict_types=1);

/*
 * 1.2.98 — the installer, run for real: install.php copied into a scratch site
 * and served by PHP's built-in server, against its own throwaway database.
 * BH-086 (the done screen is shown, once), BH-087 (GD only recommended),
 * BH-088 (no hardcoded table count), plus the rebuilt flow: friendly database
 * errors, form tokens, starter content, the primary menu, the timezone and
 * tagline, and a host's own .env lines kept.
 */

/** A scratch site with the real installer, served on a free port. */
function r98_site(): array
{
    static $site = null;
    if ($site !== null) return $site;
    $env = bh_env();
    $root = $env['root'];
    $dir = sys_get_temp_dir() . '/bh-installer-' . bin2hex(random_bytes(4));
    mkdir($dir . '/storage', 0775, true);
    mkdir($dir . '/content', 0775, true);
    copy($root . '/install.php', $dir . '/install.php');
    copy($root . '/index.php', $dir . '/index.php');
    foreach (['app', 'admin', 'database'] as $d) symlink($root . '/' . $d, $dir . '/' . $d);
    // A host put these here before anyone ran the installer.
    file_put_contents($dir . '/.env', "TRUSTED_PROXIES=10.0.0.0/8\nMAIL_HOST=smtp.example.test\n");

    $port = 0;
    $sock = stream_socket_server('tcp://127.0.0.1:0');
    if ($sock) { $port = (int) substr(strrchr(stream_socket_get_name($sock, false), ':'), 1); fclose($sock); }
    $proc = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $dir], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $dir);
    for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) usleep(100000);

    $db = $env['db'];
    $name = 'bh_installer_test';
    $pdo = new PDO("mysql:host={$db['host']};port={$db['port']};charset=utf8mb4", $db['user'], $db['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("DROP DATABASE IF EXISTS `{$name}`");
    $pdo->exec("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

    register_shutdown_function(static function () use ($proc, $dir, $pdo, $name): void {
        proc_terminate($proc);
        $pdo->exec("DROP DATABASE IF EXISTS `{$name}`");
        exec('rm -rf ' . escapeshellarg($dir));
    });
    return $site = ['dir' => $dir, 'url' => 'http://127.0.0.1:' . $port, 'db' => $db + ['inst' => $name], 'pdo' => $pdo];
}

/** One request with a cookie jar; redirects are not followed. */
function r98_req(array &$jar, string $method, string $path, array $form = []): object
{
    $site = r98_site();
    $headers = ['Connection: close'];
    if ($jar) $headers[] = 'Cookie: ' . implode('; ', array_map(static fn($k, $v) => "$k=$v", array_keys($jar), $jar));
    $opts = ['method' => $method, 'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 120];
    if ($method === 'POST') {
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        $opts['content'] = http_build_query($form);
    }
    $opts['header'] = implode("\r\n", $headers);
    $body = (string) @file_get_contents($site['url'] . $path, false, stream_context_create(['http' => $opts]));
    $status = 0; $location = null;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+ (\d+)#', $h, $m)) $status = (int) $m[1];
        if (stripos($h, 'Location:') === 0) $location = trim(substr($h, 9));
        if (stripos($h, 'Set-Cookie:') === 0 && preg_match('/^Set-Cookie:\s*([^=]+)=([^;]*)/i', $h, $m)) $jar[$m[1]] = $m[2];
    }
    return (object) ['status' => $status, 'body' => $body, 'location' => $location];
}

function r98_csrf(string $html): string
{
    return preg_match('/name="_csrf" value="([^"]+)"/', $html, $m) ? html_entity_decode($m[1]) : '';
}

test('1.2.98 installer: requirements, database errors, install, done screen once, then locked', function () {
    $site = r98_site();
    $db = $site['db'];
    $jar = [];

    // 1. The check screen: Basehim's logo, no CDN, GD only recommended (BH-087).
    $r = r98_req($jar, 'GET', '/install.php');
    assert_same(200, $r->status, $r->body);
    assert_contains('Set up Basehim', $r->body);
    assert_contains('/admin/assets/img/logo-128.png', $r->body);
    assert_contains('/admin/assets/css/installer.css', $r->body);
    assert_not_contains('cdnjs', $r->body, 'no CDN: the installer must work offline and behind strict firewalls');
    assert_not_contains('fa-solid', $r->body);
    assert_true((bool) preg_match('/GD \(image sizes\) <span class="tag">Recommended<\/span>/', $r->body), 'GD is recommended, not required');
    assert_contains('href="install.php?step=database"', $r->body);

    // 2. A form without its token is refused, with a reason.
    $r = r98_req($jar, 'POST', '/install.php?step=database', ['action' => 'database', 'db_database' => $db['inst']]);
    assert_contains('This form has expired', $r->body);

    $page = r98_req($jar, 'GET', '/install.php?step=database');
    $csrf = r98_csrf($page->body);
    assert_true($csrf !== '', 'the form carries a token');
    $form = ['action' => 'database', '_csrf' => $csrf, 'db_host' => $db['host'], 'db_port' => (string) $db['port'],
        'db_database' => $db['inst'], 'db_username' => $db['user'], 'db_password' => $db['pass']];

    // Wrong user: a sentence a site owner can act on, plus the server's words.
    $r = r98_req($jar, 'POST', '/install.php?step=database', ['db_username' => 'bh_nobody_' . bin2hex(random_bytes(3)), 'db_password' => 'nope'] + $form);
    assert_same(200, $r->status);
    assert_contains('refused this user name or password', $r->body);
    assert_contains('Technical details', $r->body);
    // Nothing could be reached.
    $r = r98_req($jar, 'POST', '/install.php?step=database', ['db_port' => '1'] + $form);
    assert_contains('No database server answered', $r->body);
    // Empty name: a field error, no connection attempt.
    $r = r98_req($jar, 'POST', '/install.php?step=database', ['db_database' => ''] + $form);
    assert_contains('Enter the database name.', $r->body);

    $r = r98_req($jar, 'POST', '/install.php?step=database', $form);
    assert_same(302, $r->status, $r->body);
    assert_same('install.php?step=site', $r->location);

    // 3. The site step: no table count (BH-088), the database is confirmed.
    $page = r98_req($jar, 'GET', '/install.php?step=site');
    assert_contains('Connected to <strong>' . $db['inst'] . '</strong>', $page->body);
    assert_not_contains('17 tables', $page->body);
    $csrf = r98_csrf($page->body);

    $bad = r98_req($jar, 'POST', '/install.php?step=site', ['action' => 'install', '_csrf' => $csrf, 'site_title' => '', 'app_url' => 'javascript:alert(1)',
        'timezone' => 'Mars/Olympus', 'username' => 'a', 'email' => 'nope', 'password' => 'short']);
    foreach (['Give your site a name', 'Enter the full address', 'Choose a timezone', 'Use 3 to 60 letters', 'Enter a valid email', 'Use at least 8 characters'] as $msg) {
        assert_contains($msg, $bad->body);
    }
    assert_not_contains('INSTALLED', (string) file_get_contents($site['dir'] . '/.env'), 'nothing is written until the form is valid');

    $r = r98_req($jar, 'POST', '/install.php?step=site', ['action' => 'install', '_csrf' => $csrf,
        'site_title' => 'Northwind "Studio"', 'tagline' => 'Design and print', 'app_url' => 'https://www.northwind.test/',
        'timezone' => 'Asia/Karachi', 'username' => 'maria', 'display_name' => 'Maria Lopez', 'email' => 'maria@example.com',
        'password' => 'A-long-pass-123', 'starter' => '1']);
    assert_same(302, $r->status, $r->body);
    assert_same('install.php?step=done', $r->location);

    // 4. BH-086: the done screen is shown to the browser that installed…
    $done = r98_req($jar, 'GET', '/install.php?step=done');
    assert_same(200, $done->status, 'the done screen used to redirect away');
    assert_contains('is ready', $done->body);
    assert_contains('Northwind &quot;Studio&quot;', $done->body);
    assert_contains('/admin/login', $done->body);
    // …and to nobody else, and never accepts a POST.
    $other = [];
    assert_same(302, r98_req($other, 'GET', '/install.php?step=done')->status);
    assert_same(302, r98_req($jar, 'POST', '/install.php?step=done', ['action' => 'install'])->status);
    assert_same(302, r98_req($jar, 'GET', '/install.php')->status, 'the installer is locked');

    // 5. What was written.
    $envText = (string) file_get_contents($site['dir'] . '/.env');
    assert_contains("INSTALLED=true\n", $envText);
    assert_contains('APP_URL=https://www.northwind.test', $envText);
    assert_contains('APP_NAME="Northwind "Studio""', $envText);
    assert_contains('TRUSTED_PROXIES=10.0.0.0/8', $envText, 'a host\'s own lines are kept');
    assert_contains('MAIL_HOST=smtp.example.test', $envText);
    assert_true((bool) preg_match('/^DB_PREFIX=([a-z][a-z0-9]{2}_)$/m', $envText, $pm), 'a random prefix');
    // Read it back with core's own parser, in a separate process (this one's
    // Env already holds the test site's settings).
    $code = 'define("BASEHIM_ROOT", $argv[1]); require $argv[1] . "/app/Core/Autoloader.php"; \\App\\Core\\Autoloader::register();'
        . ' \\App\\Core\\Env::load($argv[2]); echo \\App\\Core\\Env::get("APP_NAME"), "|", var_export(\\App\\Core\\Env::get("INSTALLED"), true);';
    $out = shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' ' . escapeshellarg(bh_env()['root']) . ' ' . escapeshellarg($site['dir'] . '/.env'));
    assert_same('Northwind "Studio"|true', trim((string) $out), 'quotes in the title survive the .env parser, and INSTALLED reads as true');

    $p = $pm[1];
    $pdo = new PDO("mysql:host={$db['host']};port={$db['port']};dbname={$db['inst']};charset=utf8mb4", $db['user'], $db['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $q = static fn(string $sql) => $pdo->query(str_replace('{p}', $p, $sql))->fetchAll(PDO::FETCH_ASSOC);
    assert_same('super_admin', $q("SELECT role FROM {p}users WHERE username = 'maria'")[0]['role'] ?? null);
    $settings = [];
    foreach ($q('SELECT setting_group g, setting_key k, setting_value v FROM {p}settings') as $s) $settings[$s['g'] . '.' . $s['k']] = $s['v'];
    assert_same('Design and print', $settings['general.tagline'] ?? null, 'the tagline asked for, not "A Modern API-First CMS"');
    assert_same('Asia/Karachi', $settings['general.timezone'] ?? null);
    assert_same('noreply@northwind.test', $settings['email.from_email'] ?? null);
    assert_same(['about', 'hello-world'], array_column($q('SELECT slug FROM {p}posts ORDER BY slug'), 'slug'));
    assert_not_contains('<h1', (string) $q("SELECT content FROM {p}posts WHERE slug = 'about'")[0]['content'], 'the page title is the only h1');
    $menu = $q("SELECT i.title, i.url FROM {p}menu_items i JOIN {p}menus m ON m.id = i.menu_id WHERE m.location = 'primary' ORDER BY i.menu_order");
    assert_same(['Home', 'About'], array_column($menu, 'title'), 'a new site has navigation');
    $files = count(glob(bh_env()['root'] . '/database/migrations/*.sql') ?: []);
    assert_same($files, (int) $q('SELECT COUNT(*) c FROM {p}migrations')[0]['c'], 'every migration is recorded once');
});

test('1.2.98 installer: no raw $_SERVER[\'HTTPS\'] (BH-089) and no CDN assets', function () {
    $src = (string) file_get_contents(bh_env()['root'] . '/install.php');
    assert_not_contains("\$_SERVER['HTTPS']", $src, 'use Request::serverIsSecure()');
    assert_contains('Request::serverIsSecure()', $src);
    assert_not_contains('https://cdnjs', $src);
});

test('1.2.98 default theme: lead post, one "Page not found", singular author link', function () {
    $b = bh_browser();
    $home = $b->request('GET', '/');
    assert_same(200, $home->status);
    if (str_contains($home->body, '/content/themes/default/')) {
        assert_contains('/content/themes/default/assets/theme.css', $home->body);
        if (str_contains($home->body, 'bht-card') || str_contains($home->body, 'bht-lead')) {
            assert_contains('class="bht-lead group"', $home->body, 'the newest post leads on page one');
        }
        assert_not_contains('pl-3" aria-hidden', $home->body, 'the header search icon is no longer squeezed by its own padding');
        $nf = $b->request('GET', '/r98-no-such-page');
        assert_same(404, $nf->status);
        $bodyOnly = substr($nf->body, (int) strpos($nf->body, '<body'));
        assert_same(1, substr_count($bodyOnly, 'Page not found'), 'said once on the page, not twice');
        assert_contains('action="/search"', $nf->body);
    }
    $box = bh_author_box(['author_id' => 0, 'author_name' => 'X']);
    assert_true(is_string($box));
    $src = (string) file_get_contents(bh_env()['root'] . '/bootstrap.php');
    assert_contains("'link_text_one' => 'View their post'", $src, 'one post is not "View all 1 posts"');
});
