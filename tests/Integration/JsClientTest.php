<?php

declare(strict_types=1);

/*
 * The JavaScript client, run for real: tests/js/ajax-client.test.js loads
 * admin/assets/js/ajax.js in Node and calls the running test server. Each
 * JS case is reported as its own test here. Skipped when Node 18+ is absent.
 */

(function () {
    $results = null;
    $run = function () use (&$results): array {
        if ($results !== null) return $results;
        $node = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($node === '') return $results = ['skip' => 'Node.js is not installed'];
        $major = (int) ltrim((string) shell_exec(escapeshellarg($node) . ' --version'), 'v');
        if ($major < 18) return $results = ['skip' => 'needs Node.js 18+ (has fetch)'];

        $admin = bh_browser('admin');
        $env = [
            'BASE_URL'     => $admin->baseUrl,
            'ADMIN_COOKIE' => 'BASEHIMSESS=' . ($admin->cookies['BASEHIMSESS'] ?? ''),
            'ADMIN_TOKEN'  => $admin->csrf,
            'PATH'         => (string) getenv('PATH'),
            'NO_PROXY'     => '*',
        ];
        $proc = proc_open([$node, bh_env()['root'] . '/tests/js/ajax-client.test.js'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, bh_env()['root'], $env);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        proc_close($proc);
        $decoded = json_decode((string) $out, true);
        if (!is_array($decoded)) return $results = ['error' => "node output: {$out} {$err}"];
        $by = [];
        foreach ($decoded as $r) $by[$r['name']] = $r;
        return $results = ['cases' => $by];
    };

    $names = [
        'success resolves with the handler data',
        'server errors become AjaxError with code, status, message and request ID',
        'validation errors carry per-field messages',
        'a stale token is refreshed once and the request retried',
        'a visitor page with no token fetches one before its first POST',
        'an expired session is reported, not retried forever',
        'GET builds a query string and refuses nested values without a request',
        'idempotency keys: generated, sent, and a reused key is a duplicate',
        'a non-JSON answer is BAD_RESPONSE',
        'an unreachable server is NETWORK_ERROR',
        'a hanging server is TIMEOUT, and a caller abort is ABORTED',
        'an empty action name is refused locally',
        'the admin layout config (body data attributes) works without BASEHIM_AJAX',
    ];
    foreach ($names as $name) {
        test('js client: ' . $name, function () use ($run, $name) {
            $r = $run();
            if (isset($r['skip'])) skip($r['skip']);
            if (isset($r['error'])) fail($r['error']);
            $case = $r['cases'][$name] ?? null;
            if ($case === null) fail('case did not run');
            if (!$case['ok']) fail($case['error'] ?? 'failed');
        });
    }
})();
