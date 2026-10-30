<?php

declare(strict_types=1);

/*
 * 1.2.93 — the frontend widget framework without a database: settings
 * schemas, definitions, placements and the renderer, with the settings row
 * and the cache in memory.
 */

use App\Core\Cache;
use App\Core\WidgetAreaRegistry;
use App\Core\WidgetRegistry;
use App\Core\Widgets\Definition;
use App\Core\Widgets\FrontendWidgets;
use App\Core\Widgets\Placements;
use App\Core\Widgets\Schema;
use App\Core\Widgets\WidgetError;

/** A settings row in memory, with SettingService's get/update contract. */
function fw_settings(mixed $initial = null): \App\Services\SettingService
{
    return new class($initial) extends \App\Services\SettingService {
        public int $writes = 0;
        public function __construct(public mixed $value) {}
        public function get(string $group, string $key, $default = null) { return $this->value ?? $default; }
        public function update(string $group, string $key, callable $fn, mixed $default = null, bool $autoload = true): mixed
        {
            $new = $fn($this->value === null ? null : json_decode(json_encode($this->value), true));
            $this->value = json_decode(json_encode($new), true);
            $this->writes++;
            return $new;
        }
    };
}

/** @return array{0:FrontendWidgets,1:WidgetRegistry,2:object,3:?Cache,4:array} */
function fw_make(array $env = [], mixed $stored = null, bool $cache = false): array
{
    $reg = new WidgetRegistry();
    $areas = new WidgetAreaRegistry();
    $areas->register('sidebar', ['name' => 'Sidebar']);
    $areas->register('footer', ['name' => 'Footer', 'before_widget' => '<section id="%1$s" class="w %2$s" data-k="%3$s">',
        'after_widget' => '</section>', 'before_title' => '<h4>', 'after_title' => '</h4>']);
    $areas->register('ads', ['name' => 'Ads', 'supports' => ['ads'], 'max_widgets' => 1]);
    $settings = fw_settings($stored);
    $dir = sys_get_temp_dir() . '/bhfw-' . bin2hex(random_bytes(4));
    $c = $cache ? new Cache($dir) : null;
    $box = ['user' => null, 'theme_path' => '', 'apps' => [], 'url' => '/'];
    $box = (object) $box;
    $fw = new FrontendWidgets($reg, $areas, new Placements($settings), $c, null, null, $env + [
        'user' => fn() => $box->user,
        'theme' => fn() => 'test',
        'theme_path' => fn() => $box->theme_path,
        'app_active' => fn(string $s) => in_array($s, $box->apps, true),
        'request' => fn() => ['method' => 'GET', 'url' => $box->url],
    ]);
    return [$fw, $reg, $settings, $c, $box];
}

function fw_echo_widget(WidgetRegistry $reg, string $key = 'core.echo', array $front = [], array $fields = []): void
{
    $ok = $reg->register($key, [
        'title' => 'Echo', 'source' => 'core', 'surfaces' => ['frontend'],
        'fields' => $fields ?: [
            ['key' => 'title', 'type' => 'text'],
            ['key' => 'n', 'type' => 'number', 'default' => 3, 'min' => 1, 'max' => 10],
            ['key' => 'mode', 'type' => 'select', 'default' => 'a', 'options' => ['a' => 'A', 'b' => 'B']],
            ['key' => 'secret', 'type' => 'text', 'private' => true],
        ],
        'frontend' => $front + [
            'template' => fn(array $v) => '<p class="echo">' . htmlspecialchars((string) $v['settings']['n']) . ':' . $v['settings']['mode'] . ':' . ($v['data']['x'] ?? '') . '</p>',
            'data' => fn(array $s, array $ctx) => ['x' => 'd'],
        ],
    ]);
    assert_true($ok, json_encode($reg->rejected()));
}

test('frontend widgets: schema validates, normalises and keeps legacy values', function () {
    $s = new Schema([
        ['key' => 'title', 'type' => 'text'],
        ['key' => 'count', 'type' => 'number', 'default' => 5, 'min' => 1, 'max' => 20],
        ['key' => 'size', 'type' => 'number', 'default' => 1.5, 'step' => 0.1, 'min' => 0.5, 'max' => 4],
        ['key' => 'on', 'type' => 'checkbox', 'default' => '1'],
        ['key' => 'pick', 'type' => 'select', 'options' => ['x' => 'X', 'y' => 'Y'], 'default' => 'x'],
        ['key' => 'many', 'type' => 'multiselect', 'options' => ['x', 'y', 'z']],
        ['key' => 'terms', 'type' => 'terms', 'multiple' => true],
        ['key' => 'link', 'type' => 'url'],
        ['key' => 'colour', 'type' => 'color'],
        ['key' => 'when', 'type' => 'date'],
        ['key' => 'img', 'type' => 'media'],
        ['key' => 'need', 'type' => 'text', 'required' => true, 'show_if' => ['pick' => 'y']],
        ['key' => 'admin', 'type' => 'text', 'capability' => 'unfiltered_html'],
    ]);
    $d = $s->defaults();
    assert_same(5, $d['count']);
    assert_same('1', $d['on']);
    assert_same([], $d['many']);

    // Legacy stored strings read the way widgets always read them.
    $n = $s->normalise(['count' => '8', 'on' => '0', 'pick' => 'nope', 'count2' => 'x', 'link' => 'javascript:alert(1)']);
    assert_same(8, $n['count']);
    assert_same('0', $n['on']);
    assert_same('x', $n['pick'], 'unknown option falls back to the default');
    assert_false(array_key_exists('count2', $n), 'unknown keys dropped');
    assert_same('', $n['link'], 'unsafe URL dropped when rendering');
    assert_same(20, $s->normalise(['count' => 999])['count'], 'clamped when rendering');
    assert_same(1.5, $s->normalise([])['size']);

    $can = fn(string $c) => false;
    [$v, $e] = $s->validate(['count' => '99', 'pick' => 'y', 'many' => ['x', 'q'], 'link' => 'javascript:x', 'colour' => 'red',
        'when' => '2026-02-30', 'terms' => ['3', 'a'], 'img' => 'x', 'admin' => 'try'], ['admin' => 'kept'], $can);
    foreach (['count', 'many', 'link', 'colour', 'when', 'terms', 'img', 'need'] as $k) assert_true(isset($e[$k]), "error for {$k}: " . json_encode($e));
    assert_same('kept', $v['admin'], 'a capability-protected field keeps its saved value');
    assert_same('0', $v['on'], 'an unchecked box is sent as missing');

    [$v, $e] = $s->validate(['count' => '7', 'pick' => 'x', 'many' => 'x,z', 'terms' => '[4,4,9]', 'link' => '/about',
        'colour' => '#ABCDEF', 'when' => '2026-02-28', 'img' => '12', 'size' => '2.25'], [], fn() => true);
    assert_same([], $e, json_encode($e));
    assert_same(7, $v['count']);
    assert_same(['x', 'z'], $v['many']);
    assert_same([4, 9], $v['terms']);
    assert_same('#abcdef', $v['colour']);
    assert_same(12, $v['img']);
    assert_same(2.25, $v['size']);
    assert_true(Schema::safeUrl('https://example.com/x'));
    assert_false(Schema::safeUrl('//evil.example'));
    assert_false(Schema::safeUrl('data:text/html,x'));
});

test('frontend widgets: definition options are checked at registration', function () {
    $reg = new WidgetRegistry();
    assert_false($reg->register('core.bad', ['title' => 'Bad', 'source' => 'core', 'surfaces' => ['frontend'], 'frontend' => ['template' => '/etc/passwd']]));
    $r = $reg->rejected();
    assert_contains('template', end($r)['reason']);
    assert_false($reg->register('core.bad2', ['title' => 'Bad', 'source' => 'core', 'surfaces' => ['frontend'],
        'frontend' => ['template' => fn() => '', 'assets' => ['inline_css' => '</style><script>']]]));
    assert_false($reg->register('core.bad3', ['title' => 'Bad', 'source' => 'core', 'surfaces' => ['frontend']]), 'needs render or template');
    // An app may not point at core's templates.
    assert_false($reg->register('x.y', ['title' => 'X', 'source' => 'app:x', 'surfaces' => ['frontend'],
        'frontend' => ['template' => BASEHIM_ROOT . '/app/Core/Widgets/templates/html.php']]));

    assert_true($reg->register('core.ok', ['title' => 'OK', 'source' => 'core', 'surfaces' => ['frontend'], 'frontend' => [
        'category' => 'nonsense', 'version' => 'v?', 'cache' => 999999, 'template' => BASEHIM_ROOT . '/app/Core/Widgets/templates/html.php',
        'aliases' => ['core.old-ok'], 'assets' => ['css' => ['https://cdn.example/x.css', 'javascript:x'], 'js' => ['/x.js']],
    ]]));
    $w = $reg->get('core.old-ok');
    assert_same('core.ok', $w['key'], 'aliases resolve');
    assert_same('other', $w['frontend']['category']);
    assert_same('1.0.0', $w['frontend']['version']);
    assert_same(Definition::MAX_TTL, $w['frontend']['cache']['ttl']);
    assert_same(['https://cdn.example/x.css'], $w['frontend']['assets']['css']);
    $pub = WidgetRegistry::publicDefinition($w);
    assert_false(isset($pub['frontend']['template']), 'no paths in the public definition');
    assert_true($pub['frontend']['has_template']);
});

test('frontend widgets: placements read old records and survive odd data', function () {
    $all = Placements::normaliseAll(['sidebar' => [
        ['id' => 'w_1', 'widget' => 'core.search', 'settings' => ['title' => 'S']],
        ['id' => 'w_1', 'widget' => 'core.tags'],                  // a copied record
        ['id' => '<bad>', 'widget' => 'core.tags'],
        'junk',
    ], 3 => [], 'footer' => 'x']);
    assert_same(['sidebar'], array_keys($all));
    assert_same(2, count($all['sidebar']));
    assert_same(['title' => 'S'], $all['sidebar'][0]['settings']);
    assert_false($all['sidebar'][0]['disabled']);
    assert_true($all['sidebar'][1]['id'] !== 'w_1', 'duplicate ids are made unique');
    assert_same('a b-c', Placements::cleanClass('a  b-c "><x 1bad a'));
    assert_same([], Placements::normaliseAll('{not json'));
});

test('frontend widgets: add, save (422), move, order, duplicate, remove', function () {
    [$fw, $reg, $settings] = fw_make();
    fw_echo_widget($reg);
    $a = $fw->add('sidebar', 'core.echo');
    assert_same(3, $a['settings']['n'], 'starts from the defaults');
    $b = $fw->add('sidebar', 'core.echo', ['n' => '5'], 0);
    assert_same([$b['id'], $a['id']], array_column($fw->placements()->area('sidebar'), 'id'), 'position honoured');

    $e = assert_throws(WidgetError::class, fn() => $fw->save($a['id'], ['n' => '50']));
    assert_same(422, $e->status);
    assert_true(isset($e->errors['n']));
    assert_same(3, $fw->placements()->find($a['id'])[2]['settings']['n'], 'nothing saved on error');

    $fw->save($a['id'], ['n' => '9', 'mode' => 'b', 'title' => 'T'], ['class' => 'x y', 'hide_title' => '1']);
    $inst = $fw->placements()->find($a['id'])[2];
    assert_same(9, $inst['settings']['n']);
    assert_same('x y', $inst['class']);
    assert_true($inst['hide_title']);
    assert_same('1.0.0', $inst['v']);

    $fw->move($a['id'], 'footer', 0);
    assert_same('footer', $fw->placements()->find($a['id'])[0]);
    $c = $fw->duplicate($b['id']);
    assert_same([$b['id'], $c['id']], array_column($fw->placements()->area('sidebar'), 'id'));
    $fw->order('sidebar', [$c['id']]);
    assert_same([$c['id'], $b['id']], array_column($fw->placements()->area('sidebar'), 'id'), 'unlisted ids keep their place at the end');
    $fw->remove($c['id']);
    assert_same(404, assert_throws(WidgetError::class, fn() => $fw->remove($c['id']))->status);
    assert_same(404, assert_throws(WidgetError::class, fn() => $fw->add('nowhere', 'core.echo'))->status);
    assert_same(404, assert_throws(WidgetError::class, fn() => $fw->add('sidebar', 'core.nothing'))->status);

    // Area rules: category and maximum.
    assert_contains('does not take', assert_throws(WidgetError::class, fn() => $fw->add('ads', 'core.echo'))->getMessage());
    fw_echo_widget($reg, 'core.ad', ['category' => 'ads']);
    $fw->add('ads', 'core.ad');
    assert_contains('at most 1', assert_throws(WidgetError::class, fn() => $fw->add('ads', 'core.ad'))->getMessage());
    assert_contains('does not take', assert_throws(WidgetError::class, fn() => $fw->move($b['id'], 'ads', 0))->getMessage());
});

test('frontend widgets: rendering — template, title, wrapper, hidden and missing widgets', function () {
    [$fw, $reg] = fw_make();
    fw_echo_widget($reg);
    $id = $fw->add('footer', 'core.echo', ['title' => 'Hello <b>', 'n' => '4'])['id'];
    $html = $fw->renderArea('footer');
    assert_contains('<section id="' . $id . '" class="w widget-core-echo" data-k="core.echo"><h4>Hello &lt;b&gt;</h4><p class="echo">4:a:d</p></section>', $html);
    assert_contains('widget-area widget-area--footer', $html);
    assert_true($fw->hasWidgets('footer'));

    $fw->setOptions($id, ['hide_title' => true]);
    assert_not_contains('<h4>', $fw->renderArea('footer'));
    $fw->setOptions($id, ['disabled' => true]);
    assert_same('', $fw->renderArea('footer'), 'a hidden widget shows nothing, and an empty area no wrapper');
    assert_false($fw->hasWidgets('footer'));

    // A widget that disappears (its app switched off) is kept and skipped.
    $fw->placements()->change(function ($all) { $all['footer'][] = ['id' => 'w_gone', 'widget' => 'app.gone', 'settings' => ['a' => 1]]; return $all; });
    assert_not_contains('app.gone"', preg_replace('/<!--.*?-->/', '', $fw->renderArea('footer')));
    assert_same(['a' => 1], $fw->placements()->find('w_gone')[2]['settings'], 'its settings survive');
    assert_same('', $fw->renderArea('no-such-area'));
});

test('frontend widgets: one failing widget costs only itself', function () {
    [$fw, $reg] = fw_make();
    fw_echo_widget($reg);
    $reg->register('core.boom', ['title' => 'Boom', 'source' => 'core', 'surfaces' => ['frontend'],
        'frontend' => ['template' => function () { throw new \RuntimeException('secret path /x/y'); }]]);
    $reg->register('core.half', ['title' => 'Half', 'source' => 'core', 'surfaces' => ['frontend'],
        'frontend' => ['template' => function () { echo 'partial'; throw new \RuntimeException('x'); }]]);
    $fw->add('sidebar', 'core.boom');
    $fw->add('sidebar', 'core.half');
    $fw->add('sidebar', 'core.echo');
    $level = ob_get_level();
    $html = $fw->renderArea('sidebar');
    assert_same($level, ob_get_level(), 'output buffers restored');
    assert_contains('<p class="echo">', $html);
    assert_contains('failed to render', $html);
    assert_not_contains('secret path', $html, 'the error is logged, not shown');
});

test('frontend widgets: legacy render widgets keep working, with the area\'s title markup', function () {
    [$fw, $reg] = fw_make();
    $reg->register('legacy.w', ['title' => 'Legacy', 'source' => 'core', 'surfaces' => ['frontend'],
        'fields' => [['key' => 'title', 'type' => 'text']],
        'render' => fn(array $s) => $s['__before_title'] . htmlspecialchars($s['title']) . $s['__after_title'] . '<i>legacy</i>']);
    $fw->placements()->change(fn() => ['footer' => [['id' => 'w_old', 'widget' => 'legacy.w', 'settings' => ['title' => 'Old']]]]);
    assert_contains('<h4>Old</h4><i>legacy</i>', $fw->renderArea('footer'));
});

test('frontend widgets: requires, migrations and theme templates', function () {
    [$fw, $reg, , , $box] = fw_make();
    fw_echo_widget($reg, 'core.needs', ['requires' => ['shop'], 'version' => '2.0.0',
        'migrate' => fn(array $s, string $from) => $from === '0' ? ['n' => (int) ($s['old_n'] ?? 1) * 2] + $s : $s]);
    $fw->placements()->change(fn() => ['sidebar' => [['id' => 'w_m', 'widget' => 'core.needs', 'settings' => ['old_n' => 4]]]]);
    assert_contains('Needs the shop app', (string) $fw->unavailable('core.needs'));
    assert_false($fw->hasWidgets('sidebar'));
    assert_contains('WidgetError', get_class(assert_throws(WidgetError::class, fn() => $fw->add('sidebar', 'core.needs'))));

    $box->apps = ['shop'];
    assert_contains('<p class="echo">8:a:d</p>', $fw->renderArea('sidebar'), 'pre-version settings migrated');

    // The theme overrides by key, and by area before that.
    $dir = sys_get_temp_dir() . '/bhfw-theme-' . bin2hex(random_bytes(3));
    @mkdir($dir . '/templates/widgets/sidebar', 0755, true);
    file_put_contents($dir . '/templates/widgets/core.needs.php', '<b><?= $e($settings["n"]) ?>/<?= $e($data["x"]) ?></b>');
    $box->theme_path = $dir;
    assert_contains('<b>8/d</b>', $fw->renderArea('sidebar'));
    file_put_contents($dir . '/templates/widgets/sidebar/needs.php', '<u><?= $e($area["key"]) ?></u>');
    assert_contains('<u>sidebar</u>', $fw->renderArea('sidebar'), 'core widgets also answer to their short name, per area first');
    array_map('unlink', [$dir . '/templates/widgets/sidebar/needs.php', $dir . '/templates/widgets/core.needs.php']);
});

test('frontend widgets: output cache is for signed-out visitors only, and cleared on change', function () {
    [$fw, $reg, , $cache, $box] = fw_make([], null, true);
    $calls = 0;
    fw_echo_widget($reg, 'core.c', ['cache' => 300, 'data' => function () use (&$calls) { $calls++; return ['x' => 'n' . $calls]; }]);
    $id = $fw->add('sidebar', 'core.c')['id'];
    $first = $fw->renderArea('sidebar');
    assert_same($first, $fw->renderArea('sidebar'));
    assert_same(1, $calls, 'second render came from the cache');

    $box->user = ['id' => 1, 'role' => 'administrator'];
    $fw->renderArea('sidebar');
    $fw->renderArea('sidebar');
    assert_same(3, $calls, 'signed-in visitors are never served or stored in the cache');
    $box->user = null;

    $fw->save($id, ['n' => '6']);
    assert_contains('6:a:n4', $fw->renderArea('sidebar'), 'a save clears the cache');
    $fw->flush();
    $fw->renderArea('sidebar');
    assert_same(5, $calls);

    // Varying by path: one entry per page.
    fw_echo_widget($reg, 'core.v', ['cache' => ['ttl' => 300, 'vary' => ['path']], 'data' => fn($s, $ctx) => ['x' => $ctx['path']]]);
    $fw->add('footer', 'core.v');
    $box->url = '/a?q=1';
    assert_contains(':/a<', $fw->renderArea('footer'));
    $box->url = '/b';
    assert_contains(':/b<', $fw->renderArea('footer'));
    $cache->flush();
});

test('frontend widgets: assets print once per page, never inside the cache', function () {
    [$fw, $reg] = fw_make([], null, true);
    fw_echo_widget($reg, 'core.a1', ['cache' => 60, 'assets' => ['inline_css' => '.a{}', 'css' => ['/a.css'], 'inline_js' => 'var a;']]);
    fw_echo_widget($reg, 'core.a2', ['assets' => ['inline_css' => '.a{}', 'css' => ['/a.css']]]);
    $fw->add('sidebar', 'core.a1');
    $fw->add('sidebar', 'core.a1');
    $fw->add('footer', 'core.a2');
    $html = $fw->renderArea('sidebar') . $fw->renderArea('footer');
    assert_same(1, substr_count($html, '.a{}'), 'shared inline CSS once');
    assert_same(1, substr_count($html, '/a.css'));
    assert_same(1, substr_count($html, 'var a;'));
});

test('frontend widgets: public settings leave out private and HTML fields', function () {
    [$fw, $reg] = fw_make();
    fw_echo_widget($reg, 'core.p', [], [['key' => 'title', 'type' => 'text'], ['key' => 'secret', 'type' => 'text', 'private' => true],
        ['key' => 'raw', 'type' => 'html']]);
    assert_same(['title' => 'T'], $fw->publicSettings(['widget' => 'core.p', 'settings' => ['title' => 'T', 'secret' => 's', 'raw' => '<x>']]));
});

test('frontend widgets: the dashboard framework is untouched by frontend options', function () {
    $reg = new WidgetRegistry();
    assert_true($reg->register('core.both', ['title' => 'Both', 'source' => 'core', 'surfaces' => ['frontend', 'dashboard'],
        'render' => fn() => 'x', 'frontend' => ['category' => 'content'], 'dashboard' => ['size' => 'sm']]));
    $w = $reg->get('core.both');
    assert_same('content', $w['frontend']['category']);
    assert_true(isset($w['dashboard']['size']));
    assert_same(1, count($reg->definitions('dashboard')));
    assert_true($reg->register('core.dash', ['title' => 'Dash', 'source' => 'core', 'surfaces' => ['dashboard'], 'dashboard' => ['data' => fn() => []]]));
    assert_same([], $reg->get('core.dash')['frontend'], 'no frontend part for dashboard-only widgets');
});
