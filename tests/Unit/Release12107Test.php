<?php

declare(strict_types=1);

/*
 * 1.2.107 — Data table UI/UX overhaul. On phones and tablets the scattered
 * inline filters, the view switch and Screen Options collapse into one
 * "Filter & sort" button that opens a right-hand drawer — with a Sort control
 * (the sort normally lives in the table header, which phones don't show),
 * every filter, and rows-per-page. The floating bulk bar is rebuilt on the
 * button system, and every load paints a skeleton shaped like the current
 * view. The stylesheet is now compiled from a Tailwind source.
 */

function r12107_file(string $rel): string
{
    return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $rel);
}

test('Data table: the toolbar has one Filter & sort button and the bulk bar has its own Clear', function () {
    $p = r12107_file('admin/views/partials/datatable.php');
    assert_contains('data-dt-drawer-open', $p, 'the mobile Filter & sort button');
    assert_contains('bh-dt-filterm', $p);
    assert_contains('data-dt-drawer-badge', $p, 'an active-filter badge');
    // Bulk bar: a separator and a Clear button that is its own element now.
    assert_contains('bh-dt-bulk__sep', $p);
    assert_contains('data-dt-bulk-clear', $p, 'Clear is a sibling, not appended into the actions');
});

test('Data table: the script builds the drawer, a sort control and the skeleton', function () {
    $js = r12107_file('admin/assets/js/datatable.js');
    assert_contains('buildDrawer', $js, 'the drawer builder');
    assert_contains('bh-dt-drawer', $js);
    assert_contains('renderSkeleton', $js, 'skeleton rendering');
    assert_contains('bh-dt-skelcard', $js, 'card-shaped skeletons');
    assert_contains('bh-dt-skelrow', $js, 'row-shaped skeletons');
    assert_contains('BULK_ICON', $js, 'bulk actions carry icons');
    assert_contains('bh-dt-bulk__n', $js, 'the count is a chip + "selected"');
    // The skeleton runs at the start of every load.
    assert_contains('renderSkeleton();', $js);
});

test('Data table: the stylesheet is compiled from a Tailwind source', function () {
    // The source is authored with @apply and carries the new sections.
    $src = r12107_file('admin/assets/css/src/datatable.css');
    assert_contains('@apply', $src, 'the source uses Tailwind @apply');
    assert_contains('bh-dt-drawer__panel', $src);
    assert_contains('bh-dt-skel__bar', $src);
    assert_contains('bh-dt-bulk__n', $src);
    assert_contains('npm run build:datatable', $src, 'the source says how it is built');

    // The compiled file the admin loads exists and carries the expanded classes,
    // and no longer holds the old hand-written bulk text.
    $out = r12107_file('admin/assets/css/datatable.css');
    assert_contains('bh-dt-drawer__panel', $out, 'compiled drawer styles shipped');
    assert_contains('bh-dt-skel__bar', $out, 'compiled skeleton styles shipped');
    assert_contains('bh-dt-filterm', $out);

    // The build script is wired up.
    $pkg = r12107_file('package.json');
    assert_contains('build:datatable', $pkg);
});
