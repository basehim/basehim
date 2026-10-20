<?php

declare(strict_types=1);

/*
 * Quick Edit in the data table (admin/assets/js/datatable.js, 1.2.61), run for
 * real in Node against tests/js/mini-dom.js: tests/js/quick-edit.test.js. Each
 * case there becomes one test here. Skipped without Node 18+.
 */

(function () {
    $node = trim((string) shell_exec('command -v node 2>/dev/null'));
    $skip = $node === '' ? 'Node.js is not installed'
        : ((int) ltrim((string) shell_exec(escapeshellarg($node) . ' --version'), 'v') < 18 ? 'needs Node.js 18+' : null);
    $cases = null;
    $error = null;
    if ($skip === null) {
        $out = (string) shell_exec(escapeshellarg($node) . ' ' . escapeshellarg(dirname(__DIR__) . '/js/quick-edit.test.js') . ' 2>&1');
        $cases = json_decode(trim($out), true);
        if (!is_array($cases)) $error = 'node output: ' . $out;
    }
    if ($skip !== null || $error !== null) {
        test('quick edit (js): runs', function () use ($skip, $error) {
            if ($skip !== null) skip($skip);
            fail($error);
        });
        return;
    }
    foreach ($cases as $case) {
        test('quick edit (js): ' . $case['name'], function () use ($case) {
            if (!$case['ok']) fail($case['error'] ?? 'failed');
        });
    }
})();
