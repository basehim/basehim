<?php

declare(strict_types=1);

/*
 * The editor's Publish panel (admin/assets/js/post-publish.js, 1.2.60), run
 * for real in Node against a small fake DOM: tests/js/post-publish.test.js.
 * Each case there becomes one test here. Skipped without Node 18+.
 */

(function () {
    static $results = null;
    $run = function () use (&$results): array {
        if ($results !== null) return $results;
        $node = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($node === '') return $results = ['skip' => 'Node.js is not installed'];
        if ((int) ltrim((string) shell_exec(escapeshellarg($node) . ' --version'), 'v') < 18) {
            return $results = ['skip' => 'needs Node.js 18+'];
        }
        $out = (string) shell_exec(escapeshellarg($node) . ' ' . escapeshellarg(dirname(__DIR__) . '/js/post-publish.test.js') . ' 2>&1');
        $decoded = json_decode(trim($out), true);
        return $results = is_array($decoded) ? ['cases' => $decoded] : ['error' => 'node output: ' . $out];
    };
    $r = $run();
    if (isset($r['skip']) || isset($r['error'])) {
        test('publish panel (js): runs', function () use ($r) {
            if (isset($r['skip'])) skip($r['skip']);
            fail($r['error']);
        });
        return;
    }
    foreach ($r['cases'] as $case) {
        test('publish panel (js): ' . $case['name'], function () use ($case) {
            if (!$case['ok']) fail($case['error'] ?? 'failed');
        });
    }
})();
