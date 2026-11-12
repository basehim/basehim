<?php

declare(strict_types=1);

/*
 * 1.2.128: the dashboard's stat tiles keep their numbers level on a phone,
 * and the At a glance hints group thousands like the numbers do.
 */

test('dashboard: a tile\'s pill sits beside its number, not in the label row', function () {
    $root = dirname(__DIR__, 2);
    $js = (string) file_get_contents($root . '/admin/assets/js/dashboard.js');
    assert_contains("var vrow = el('div', 'bh-dstat__vrow');", $js);
    assert_contains('if (pill) vrow.appendChild(pill);', $js);
    assert_not_contains('if (pill) top.appendChild(pill);', $js);
    $css = (string) file_get_contents($root . '/admin/assets/css/tailwind.min.css');
    assert_contains('.bh-dstat__vrow{', $css);
    assert_not_contains('.bh-dstat__top>.bh-dstat__pill', $css);
});

test('dashboard: At a glance hints group thousands ("4,622 published")', function () {
    $src = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Services/Dashboard/CoreDashboardWidgets.php');
    assert_not_contains("sprintf('%d published", $src);
    assert_contains("number_format(\$pending) . ' waiting for review'", $src);
    $m = new ReflectionMethod(\App\Services\Dashboard\CoreDashboardWidgets::class, 'plural');
    $m->setAccessible(true);
    assert_same('1,111 drafts', $m->invoke(null, 1111, 'draft'));
    assert_same('1 draft', $m->invoke(null, 1, 'draft'));
});

test('dashboard: tile charts are drawn from data, and the decorative bars stay only as a fallback', function () {
    $js = (string) file_get_contents(dirname(__DIR__, 2) . '/admin/assets/js/dashboard.js');
    assert_contains('function statChart(c)', $js);
    assert_contains("else if (!chart) {", $js);
    foreach (["c.type === 'line'", "c.type === 'bars'", "c.type === 'split'"] as $t) assert_contains($t, $js);
    $css = (string) file_get_contents(dirname(__DIR__, 2) . '/admin/assets/css/tailwind.min.css');
    foreach (['.bh-dstat__chart{', '.bh-dstat__split{', '.bh-dstat__line{', '.bh-dstat__seg--1{'] as $sel) assert_contains($sel, $css);
});
