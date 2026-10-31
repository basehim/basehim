<?php

declare(strict_types=1);

/*
 * The App base class's inherited surface is a compatibility contract.
 *
 * 1.2.68's first build added App::registerDashboardWidget(). The Analytics app
 * already had a private method of that name, and PHP refuses to load a class
 * that narrows an inherited method's visibility — so every request fatalled
 * and cloudhim.com went down (issues.md BH-014). Any public or protected
 * method added here can do the same to an app nobody here has seen.
 *
 * This test fails when that list changes. If you mean to add a method: pick a
 * name no app is likely to use, say so in the changelog, and add it below.
 */
test('app base class: no public or protected method is added without review', function () {
    $expected = [
        '__construct', 'addAction', 'addAdminMenu', 'addAdminScript', 'addAdminStyle', 'addEditorConfig',
        'addEditorScript', 'addEditorStyle', 'addFilter', 'addFooter', 'addHead', 'adminGet', 'adminPost',
        'adminView', 'ajax', 'allSettings', 'allowed', 'api', 'appStorageDir', 'applyFilters', 'asset', 'cache', 'config',
        'containerDir', 'db', 'deleteSetting', 'doAction', 'dropAllSettings', 'enqueueScript', 'enqueueStyle',
        'get', 'getSetting', 'icon', 'iconUrl', 'log', 'logger', 'make', 'manifest', 'onActivate',
        'onDeactivate', 'onInstall', 'onUninstall', 'onUpgrade', 'path', 'permissions', 'post',
        'registerBlockRenderer', 'registerFields', 'registerPostType', 'registerTaxonomy', 'registerWidget', 'request', 'route', 'routeGroup',
        'router', 'schedule', 'schema', 'session', 'setSetting', 'settingGroup', 'settings', 'slug', 'table',
        'useAjaxClient', 'view', 'widgets', 'boot',
    ];
    $actual = [];
    foreach ((new ReflectionClass(\App\Core\App::class))->getMethods() as $m) {
        if ($m->getDeclaringClass()->getName() !== \App\Core\App::class) continue;
        if ($m->isPublic() || $m->isProtected()) $actual[] = $m->getName();
    }
    sort($expected);
    sort($actual);
    $added = array_values(array_diff($actual, $expected));
    $removed = array_values(array_diff($expected, $actual));
    assert_same([], $added, 'New methods on App can break installed apps: ' . implode(', ', $added));
    assert_same([], $removed, 'Removing methods from App breaks apps that call them: ' . implode(', ', $removed));
});

test('app base class: an app with its own private registerDashboardWidget() still loads', function () {
    $code = 'namespace BhTestClash { class App extends \App\Core\App {'
          . ' public function boot(): void {}'
          . ' private function registerDashboardWidget(string $k, array $d): void {} } echo "loaded"; }';
    // Fatal errors from incompatible declarations can't be caught in-process,
    // so load it in a child PHP.
    $root = dirname(__DIR__, 2);
    $script = '<?php namespace { spl_autoload_register(function ($c) { if (str_starts_with($c, "App\\\\")) require '
            . var_export($root . '/app/', true) . ' . str_replace("\\\\", "/", substr($c, 4)) . ".php"; }); } '
            . $code;
    $file = tempnam(sys_get_temp_dir(), 'bhclash');
    file_put_contents($file, $script);
    $out = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($file) . ' 2>&1');
    @unlink($file);
    assert_contains('loaded', $out, $out);
});
