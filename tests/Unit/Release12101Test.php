<?php

declare(strict_types=1);

/*
 * 1.2.101 — BH-107 (the hub hears about an update at once), BH-108 (the hub
 * gets the Site title, tagline and icon, and hears about a change), BH-109 (a
 * toolbar menu for every declared filter, options worked out on display),
 * BH-110 (an upgrade's lifecycle runs the new code), BH-111 (Response::file
 * streams), and Quick View for data tables.
 */

use App\Core\Response;
use App\Core\Table\TableRegistry;

/** An UpdateService over saved settings, without a database. */
function r101_updates(array $saved): \App\Services\UpdateService
{
    $settings = new class($saved) extends \App\Services\SettingService {
        public function __construct(private array $saved) {}
        public function get(string $group, string $key, $default = null) { return $this->saved[$group . '.' . $key] ?? $default; }
    };
    $db = (new ReflectionClass(\App\Core\Database::class))->newInstanceWithoutConstructor();
    return new \App\Services\UpdateService($db, $settings);
}

test('BH-108: the hub is told the Site title, tagline and icon as a full address; the host only without a title', function () {
    $_SERVER['HTTP_HOST'] = 'www.example.org';
    $id = r101_updates([
        'general.site_title' => 'Northwind Studio', 'general.tagline' => 'Design and print',
        'appearance.favicon_url' => '/uploads/2026/11/icon.png',
    ])->siteIdentity();
    assert_same('Northwind Studio', $id['name']);
    assert_same('Design and print', $id['tagline']);
    assert_true((bool) preg_match('#^https?://www\.example\.org(/[^/]+)*/uploads/2026/11/icon\.png$#', $id['icon']), $id['icon']);
    $bare = r101_updates([])->siteIdentity();
    assert_same(['www.example.org', '', ''], [$bare['name'], $bare['tagline'], $bare['icon']]);
    assert_same('https://cdn.example.net/i.png', r101_updates(['appearance.favicon_url' => 'https://cdn.example.net/i.png'])->siteIdentity()['icon']);
});

test('BH-107 / BH-108: checks send the identity and version; a save of the title, tagline or icon sends it after the response; an update reports the new version', function () {
    $root = dirname(__DIR__, 2);
    $u = (string) file_get_contents($root . '/app/Services/UpdateService.php');
    assert_contains('] + $identity);', $u, 'check-in carries name, tagline, icon');
    assert_contains('if (!$this->heartbeat($newVersion)) {', $u, 'apply() reports the version just installed');
    assert_contains("'identity_sent'", $u);
    assert_false(str_contains($u, "set('updates', 'site_name'"), 'the host is no longer frozen as the name');
    $s = (string) file_get_contents($root . '/app/Services/SettingService.php');
    assert_contains("'general.site_title' => true, 'general.tagline' => true, 'appearance.favicon_url' => true", $s);
    assert_contains('UpdateService::heartbeatAfterResponse()', $s);
});

test('BH-111: Response::file streams a file with its length and download name; a missing file is a 404', function () {
    $f = tempnam(sys_get_temp_dir(), 'r101');
    file_put_contents($f, str_repeat('x', 1234));
    $r = Response::file($f, 'basehim 1.2.101.zip', 'application/zip', ['X-Checksum-Sha256' => 'abc']);
    $h = $r->getHeaders();
    assert_same(['application/zip', '1234', 'abc'], [$h['Content-Type'], $h['Content-Length'], $h['X-Checksum-Sha256']]);
    assert_same('attachment; filename="basehim_1.2.101.zip"; filename*=UTF-8\'\'basehim%201.2.101.zip', $h['Content-Disposition']);
    assert_same('', $r->getBody(), 'never read into memory');
    assert_same($f, $r->getFile());
    // send() drops output buffers (they would corrupt the download), so run it in its own process.
    $script = tempnam(sys_get_temp_dir(), 'r101s') . '.php';
    file_put_contents($script, '<?php require ' . var_export(dirname(__DIR__, 2) . '/app/Core/Response.php', true) . ';'
        . ' echo "junk"; ob_start(); echo "buffered";'
        . ' \\App\\Core\\Response::file(' . var_export($f, true) . ')->send();');
    $out = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script));
    unlink($script);
    assert_same('junk' . str_repeat('x', 1234), $out, 'the file, and nothing that was still in a buffer');
    unlink($f);
    assert_same(404, Response::file($f)->getStatus());
});

/** A registry with one table using the new keys. */
function r101_table(array $extra = []): TableRegistry
{
    $hooks = new \App\Core\HookRegistry();
    $logger = (new ReflectionClass(\App\Core\Logger::class))->newInstanceWithoutConstructor();
    $reg = new TableRegistry($hooks, $logger);
    $reg->register('r101.things', $extra + [
        'label' => 'Things', 'capability' => 'manage_options', 'provider' => \App\Services\Table\AppTableProvider::class,
        'columns' => [
            ['id' => 'name', 'label' => 'Name', 'value' => 'name'],
            ['id' => 'php', 'label' => 'PHP', 'value' => 'php', 'filter' => ['field' => 'php', 'all' => 'Any PHP', 'options' => fn() => ['8.3' => 'PHP 8.3', '8.2' => 'PHP 8.2']]],
            ['id' => 'kind', 'label' => 'Kind', 'value' => 'kind', 'filter' => ['field' => 'kind', 'in' => ['a', 'b'], 'all' => 'Any kind']],
        ],
    ]);
    return $reg;
}

test('BH-109: a filter can take its options when shown, and name its empty choice', function () {
    $cols = r101_table()->get('r101.things')->columns;
    $php = $cols[1]->toClientArray()['filter'];
    assert_same(['field' => 'php', 'all' => 'Any PHP', 'in' => ['8.3', '8.2'], 'labels' => ['8.3' => 'PHP 8.3', '8.2' => 'PHP 8.2']], $php);
    assert_same('Any kind', $cols[2]->toClientArray()['filter']['all']);
    $calls = 0;
    $col = new \App\Core\Table\Column(id: 'x', label: 'X', value: 'x', filter: ['field' => 'x'], filterOptions: function () use (&$calls) { $calls++; return ['1' => 'One']; });
    $col->filterSpec(); $col->filterSpec(); $col->toClientArray();
    assert_same(1, $calls, 'worked out once');
    $bad = new \App\Core\Table\Column(id: 'y', label: 'Y', value: 'y', filter: ['field' => 'y'], filterOptions: fn() => throw new RuntimeException('no'));
    assert_same(['field' => 'y'], $bad->filterSpec(), 'a failing callable leaves the filter open, not broken');
});

test('BH-109: the toolbar draws a menu for every declared filter and keeps them in the address', function () {
    $root = dirname(__DIR__, 2);
    $p = (string) file_get_contents($root . '/admin/views/partials/datatable.php');
    assert_contains('<?php foreach ($__menus as $__field => $__mc):', $p);
    assert_contains("data-dt-filter=\"<?= htmlspecialchars((string) \$__field) ?>\"", $p);
    $js = (string) file_get_contents($root . '/admin/assets/js/datatable.js');
    assert_contains("root.querySelectorAll('[data-dt-filter]').forEach(function (sel) { menus.push(", $js);
});

test('Quick View: a table declares a callable; the dialog gets text and checked links only', function () {
    $reg = r101_table(['quick_view' => fn(int $id) => $id === 7 ? ['title' => 'Seven'] : null]);
    assert_true(is_callable($reg->get('r101.things')->quickView));
    $threw = false;
    try { r101_table(['quick_view' => 'strtoupper']); } catch (InvalidArgumentException) { $threw = true; }
    assert_true($threw, 'a string is refused');

    $clean = (new ReflectionMethod(\App\Http\Controllers\Ajax\TableActions::class, 'cleanView'))->invoke(null, [
        'title' => 'Northwind <b>Studio</b>', 'image' => 'javascript:alert(1)', 'icon' => 'globe-alt',
        'badges' => [['label' => 'Online', 'tone' => 'green'], ['label' => 'Odd', 'tone' => 'pink']],
        'sections' => [['title' => 'Site', 'items' => [
            ['label' => 'Address', 'value' => 'example.org', 'url' => 'https://example.org'],
            ['label' => 'Bad', 'value' => 'x', 'url' => 'data:text/html,hi'],
        ]]],
        'links' => [['label' => 'Visit', 'url' => 'https://example.org', 'new_tab' => true], ['label' => 'Evil', 'url' => '//evil.example']],
    ]);
    assert_same('Northwind <b>Studio</b>', $clean['title'], 'kept as text; the dialog sets textContent');
    assert_same('', $clean['image']);
    assert_same('gray', $clean['badges'][1]['tone']);
    assert_same(['https://example.org', ''], array_column($clean['sections'][0]['items'], 'url'));
    assert_same(['Visit'], array_column($clean['links'], 'label'));
    $js = (string) file_get_contents(dirname(__DIR__, 2) . '/admin/assets/js/datatable.js');
    assert_contains("if (payload.quick_view) items.push({ label: 'Quick View'", $js);
    assert_false((bool) preg_match('/quickView[\s\S]{0,4000}innerHTML = (v|it|sec|l)\./', $js), 'no data through innerHTML');
});

test('BH-110: an upgrade whose old code is loaded finishes on the next request, with OPcache cleared for the new files', function () {
    $s = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Services/AppService.php');
    assert_contains('$staleCode = $this->entryClassLoaded($slug);', $s);
    assert_contains('if (isset($this->deferredUpgrades[$slug])) return null;', $s);
    assert_contains('if (!$this->finishPendingUpgrade($slug, $row)) continue;', $s);
    assert_contains("        \$this->forgetCompiled(\$targetDir);\n\n        // 2c.", $s);
});
