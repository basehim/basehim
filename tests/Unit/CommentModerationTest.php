<?php

declare(strict_types=1);

/*
 * 1.2.88: the Comments data table's row menu and bulk bar submit to
 * /admin/comments/bulk. The route was never registered when the table
 * replaced the old list, so Approve, Unapprove, Spam and Delete all ended in
 * a 404. These tests tie every table's bulk endpoint to a real route, and the
 * comments table's actions to what the controller and service accept.
 */

function cm_root(): string { return dirname(__DIR__, 2); }

/** POST routes in routes/admin.php, as full /admin/... paths. */
function cm_admin_posts(): array
{
    $src = (string) file_get_contents(cm_root() . '/routes/admin.php');
    preg_match_all("#->post\s*\(\s*'([^']+)'\s*,\s*\[\s*'([^']+)'\s*,\s*'([^']+)'#", $src, $m, PREG_SET_ORDER);
    $out = [];
    foreach ($m as [, $path, $class, $method]) $out['/admin' . $path] = [stripslashes($class), $method];
    return $out;
}

test('comments: every data table bulk endpoint on an admin screen is a registered POST route', function () {
    $routes = cm_admin_posts();
    $missing = [];
    foreach (glob(cm_root() . '/admin/views/*/index.php') ?: [] as $view) {
        $src = (string) file_get_contents($view);
        if (!preg_match_all("#'bulkEndpoint'\s*=>\s*([^\n]+?),\s*\n#", $src, $m)) continue;
        foreach ($m[1] as $expr) {
            if (trim($expr) === "''") continue;           // a table without a bulk form
            // Expand the two shapes used: $base . '/admin/comments/bulk' and $base . '/admin/' . $type . 's/bulk'.
            $paths = str_contains($expr, '$type')
                ? ['/admin/posts/bulk', '/admin/pages/bulk']
                : [preg_replace("#^.*?'(/admin/[^']+)'.*$#", '$1', $expr)];
            foreach ($paths as $p) {
                if (!isset($routes[$p])) $missing[] = basename(dirname($view)) . ': ' . $p;
            }
        }
    }
    assert_same([], $missing, 'bulk endpoints with no route');
});

test('comments: /admin/comments/bulk reaches CommentController::bulk, which exists', function () {
    $routes = cm_admin_posts();
    assert_same(['App\\Http\\Controllers\\Admin\\CommentController', 'bulk'], $routes['/admin/comments/bulk'] ?? null);
    assert_true(method_exists(\App\Http\Controllers\Admin\CommentController::class, 'bulk'));
    // Declared before the {id} routes and not shaped like one, so "bulk" is never read as a comment id.
    $src = (string) file_get_contents(cm_root() . '/routes/admin.php');
    assert_true(strpos($src, "'/comments/bulk'") < strpos($src, "'/comments/{id}/approve'"));
});

test('comments: the route sits under the Comments area, so it needs moderate_comments', function () {
    $map = (new ReflectionClassConstant(\App\Http\Middleware\AdminAreaPolicy::class, 'MAP'))->getValue();
    assert_same('moderate_comments', $map['/admin/comments'] ?? null);
});

test('comments: each action the table offers is one the bulk handler accepts', function () {
    $tables = (string) file_get_contents(cm_root() . '/routes/tables.php');
    preg_match("#register\('comments\.list'.*?'bulk_actions'\s*=>\s*\[([^\]]*)\]#s", $tables, $m);
    preg_match_all("#'([a-z]+)'#", $m[1] ?? '', $acts);
    assert_same(['approve', 'pending', 'spam', 'delete'], $acts[1]);

    $ctl = (string) file_get_contents(cm_root() . '/app/Http/Controllers/Admin/CommentController.php');
    preg_match("#statusMap\s*=\s*\[([^\]]*)\]#", $ctl, $sm);
    foreach (['approve' => 'approved', 'pending' => 'pending', 'spam' => 'spam'] as $a => $status) {
        assert_true(str_contains($sm[1] ?? '', "'{$a}' => '{$status}'"), "the handler maps {$a} to {$status}");
        assert_true(in_array($status, \App\Services\CommentService::STATUSES, true), "{$status} is a status the service stores");
    }
    assert_true(str_contains($ctl, "\$action === 'delete'"), 'the handler deletes');
});

test('comments: the table status filter only offers statuses the service knows', function () {
    $tables = (string) file_get_contents(cm_root() . '/routes/tables.php');
    preg_match("#\\\$commentStatus\s*=\s*\['field'\s*=>\s*'status',\s*'in'\s*=>\s*\[([^\]]*)\]#", $tables, $m);
    preg_match_all("#'([a-z]+)'#", $m[1] ?? '', $st);
    assert_true($st[1] !== []);
    foreach ($st[1] as $s) assert_true(in_array($s, \App\Services\CommentService::STATUSES, true), $s);
});

test('comments: the service refuses an unknown status before touching the database', function () {
    // A service with no database: setStatus must return before it needs one.
    $svc = (new ReflectionClass(\App\Services\CommentService::class))->newInstanceWithoutConstructor();
    assert_same(false, $svc->setStatus(1, 'published'));
    assert_same(false, $svc->setStatus(1, ''));
    assert_same(false, $svc->setStatus(1, "approved' OR 1=1 --"));
});
