<?php

declare(strict_types=1);

use App\Http\Middleware\AdminAreaPolicy;

/*
 * Every signed-in admin route needs a capability rule, or is open on purpose.
 * Before 1.2.47 /admin/customize, /admin/terms and /admin/menu-items had no
 * rule and any signed-in user (an author, a contributor) could use them.
 */
test('admin areas: every authenticated admin route has a capability rule or is listed as open', function () {
    $root = dirname(__DIR__, 2);
    $ref = new ReflectionClassConstant(AdminAreaPolicy::class, 'MAP');
    $map = $ref->getValue();
    $routes = (string) file_get_contents($root . '/routes/admin.php');
    preg_match_all("#->(?:get|post|any)\s*\(\s*'([^']+)'#", $routes, $m);
    $guest = '#^/admin/(login|forgot-password|reset-password|register)(/|$)#';
    $missing = [];
    foreach (array_unique($m[1]) as $route) {
        $path = '/admin' . ($route === '/' ? '' : $route);
        if (preg_match($guest, $path)) continue;
        $open = false;
        foreach (AdminAreaPolicy::OPEN as $o) {
            if ($path === $o || $path === $o . '/' || str_starts_with($path, rtrim($o, '/') . '/') && $o !== '/admin') { $open = true; break; }
        }
        if ($open) continue;
        $ruled = false;
        foreach (array_keys($map) as $prefix) {
            if (str_starts_with($path, $prefix)) { $ruled = true; break; }
        }
        if (!$ruled) $missing[] = $path;
    }
    assert_same([], $missing, 'admin routes with no capability rule (add them to AdminAreaPolicy::MAP or OPEN)');
});

test('admin areas: the three that were open now need the right capability', function () {
    $map = (new ReflectionClassConstant(AdminAreaPolicy::class, 'MAP'))->getValue();
    assert_same('manage_themes', $map['/admin/customize'] ?? null);
    assert_same('manage_taxonomies', $map['/admin/terms'] ?? null);
    assert_same('manage_menus', $map['/admin/menu-items'] ?? null);
});
