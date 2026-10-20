<?php

declare(strict_types=1);

/*
 * App fault isolation (1.2.71): the parts that need no database.
 *
 * DeclarationCheck must flag what PHP refuses to load — and nothing PHP
 * accepts, or healthy apps would be switched off. So each case is also loaded
 * by real PHP in a separate process, and the two answers must agree. Doing
 * that here, in a child process, is the point: these are engine fatals that
 * would end the test runner itself.
 */

use App\Services\AppHealth\AppGuard;
use App\Services\AppHealth\DeclarationCheck;
use App\Services\AppHealth\HealthStore;
use App\Services\AppHealth\Preflight;

/** Write a one-class app to a temporary folder and return its directory. */
function bh_ah_app(string $classBody, string $header = '', string $extends = '\\App\\Core\\App', array $extraFiles = []): string
{
    $dir = sys_get_temp_dir() . '/bh-ah-' . bin2hex(random_bytes(5));
    mkdir($dir . '/src', 0755, true);
    file_put_contents($dir . '/app.json', json_encode(['name' => 'T', 'slug' => 't', 'version' => '1.0.0', 'namespace' => 'BhAh\\T', 'entry' => 'App']));
    file_put_contents($dir . '/src/App.php', "<?php\nnamespace BhAh\\T;\n{$header}\nclass App extends {$extends}\n{\n{$classBody}\n}\n");
    foreach ($extraFiles as $rel => $code) {
        @mkdir(dirname($dir . '/' . $rel), 0755, true);
        file_put_contents($dir . '/' . $rel, $code);
    }
    return $dir;
}

function bh_ah_check(string $dir): array
{
    $files = (new Preflight(BASEHIM_ROOT))->files($dir);
    return (new DeclarationCheck($dir))->run($files)['problems'];
}

/** Does real PHP load the app's entry class? Runs in a child process. */
function bh_ah_php_loads(string $dir): array
{
    $script = sprintf(
        'define("BASEHIM_ROOT", %s); require BASEHIM_ROOT . "/app/Core/Autoloader.php"; \\App\\Core\\Autoloader::register();'
        . ' \\App\\Core\\Autoloader::addNamespace("BhAh\\\\T", substr(%s, strlen(BASEHIM_ROOT) + 1));'
        . ' spl_autoload_register(function ($c) { if ($c === "BhAh\\\\T\\\\App") require %s; });'
        . ' echo class_exists("BhAh\\\\T\\\\App") ? "LOADED" : "MISSING";',
        var_export(BASEHIM_ROOT, true), var_export($dir . '/src', true), var_export($dir . '/src/App.php', true)
    );
    $p = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', '-r', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $code = proc_close($p);
    return ['loaded' => str_contains((string) $out, 'LOADED'), 'code' => $code, 'error' => trim((string) $err)];
}

function bh_ah_rm(string $dir): void
{
    if (!is_dir($dir)) return;
    foreach (scandir($dir) ?: [] as $e) {
        if ($e === '.' || $e === '..') continue;
        is_dir("$dir/$e") ? bh_ah_rm("$dir/$e") : unlink("$dir/$e");
    }
    rmdir($dir);
}

function bh_ah_cleanup(string $dir): void
{
    if (str_starts_with($dir, sys_get_temp_dir() . '/bh-ah-')) bh_ah_rm($dir);
}

test('app health: a healthy app has no problems, and PHP loads it', function () {
    $dir = bh_ah_app(<<<'PHP'
    public function boot(): void {}
    protected function schema(string $sql): void {}          // same signature as core: allowed
    public function table(string $name, string $suffix = ''): string { return $name; } // wider: allowed
    private function helper(int $x): int { return $x; }
PHP);
    try {
        assert_same([], bh_ah_check($dir), 'no problems expected');
        assert_true(bh_ah_php_loads($dir)['loaded'], 'PHP should load it');
    } finally { bh_ah_cleanup($dir); }
});

/*
 * Each case: the class body, the problem code DeclarationCheck must report,
 * and the text of PHP's own fatal error.
 */
$cases = [
    'narrower visibility (the KiCad schema() case)' => ["public function boot(): void {}\n    private function schema(): array { return []; }", 'visibility', 'Access level to'],
    'an extra required parameter'                  => ["public function boot(): void {}\n    protected function schema(string \$sql, array \$opts): void {}", 'signature', 'must be compatible'],
    'a different return type'                      => ["public function boot(): void {}\n    protected function table(string \$name): array { return []; }", 'signature', 'must be compatible'],
    'a narrower parameter type'                    => ["public function boot(): void {}\n    protected function schema(int \$sql): void {}", 'signature', 'must be compatible'],
    'static vs instance'                           => ["public function boot(): void {}\n    protected static function schema(string \$sql): void {}", 'static-mismatch', 'Cannot make'],
    'boot() not implemented'                       => ["public function other(): void {}", 'abstract-missing', 'abstract method'],
    'boot() without its return type'               => ["public function boot() {}", 'signature', 'must be compatible'],
];
foreach ($cases as $label => [$body, $code, $phpSays]) {
    test("app health: flags {$label}, as PHP does", function () use ($body, $code, $phpSays) {
        $dir = bh_ah_app($body);
        try {
            $problems = bh_ah_check($dir);
            $codes = array_column($problems, 'code');
            assert_true(in_array($code, $codes, true), "expected {$code}, got " . export($codes));
            $errs = array_filter($problems, fn($p) => $p['severity'] === 'error');
            assert_true($errs !== [], 'must be an error, not a warning');
            $php = bh_ah_php_loads($dir);
            assert_false($php['loaded'], 'PHP itself must refuse this class');
            assert_contains($phpSays, $php['error'], 'PHP error text');
            // The runner (this process) is still here.
            assert_true(class_exists(\App\Core\App::class));
        } finally { bh_ah_cleanup($dir); }
    });
}

test('app health: flags a syntax error and a class name core already uses', function () {
    $dir = bh_ah_app('public function boot(): void {}', '', '\\App\\Core\\App', [
        'src/Broken.php' => "<?php\nnamespace BhAh\\T;\nclass Broken { function x( { } }\n",
        'src/Clash.php' => "<?php\nnamespace App\\Core\\Ajax;\nfinal class InputSchema {}\n",
        'vendor/lib/Old.php' => "<?php\nclass Old { function ( }\n",
    ]);
    try {
        $p = bh_ah_check($dir);
        $by = [];
        foreach ($p as $x) $by[$x['code']][] = $x;
        assert_true(isset($by['syntax']), 'syntax error expected');
        assert_true(isset($by['name-in-use']), 'class name clash expected');
        assert_contains('app/Core/Ajax/InputSchema.php', $by['name-in-use'][0]['message']);
        // A broken file under vendor/ is reported, but does not block.
        $vendor = array_values(array_filter($by['syntax'], fn($x) => str_contains($x['file'], 'vendor/')));
        assert_same('warning', $vendor[0]['severity'] ?? null, 'vendor syntax error is a warning');
    } finally { bh_ah_cleanup($dir); }
});

test('app health: a missing parent is flagged', function () {
    $dir = bh_ah_app('public function boot(): void {}', '', '\\App\\Core\\NoSuchBase');
    try {
        assert_true(in_array('missing-parent', array_column(bh_ah_check($dir), 'code'), true));
    } finally { bh_ah_cleanup($dir); }
});

test('app health: the isolated load test reports an engine fatal and the caller carries on', function () {
    $pf = new Preflight(BASEHIM_ROOT);
    if (!$pf->isolation()['available']) skip('no PHP command line here: ' . $pf->isolation()['reason']);
    $bad = bh_ah_app("public function boot(): void {}\n    private function schema(): array { return []; }");
    $good = bh_ah_app('public function boot(): void {}');
    try {
        $r = $pf->runMany([
            'bad' => ['dir' => $bad, 'manifest' => json_decode((string) file_get_contents($bad . '/app.json'), true)],
            'good' => ['dir' => $good, 'manifest' => json_decode((string) file_get_contents($good . '/app.json'), true)],
        ]);
        assert_false($r['bad']['ok']);
        assert_true($r['bad']['isolated']);
        $fatal = array_values(array_filter($r['bad']['errors'], fn($e) => $e['code'] === 'fatal'));
        assert_contains('Access level to', $fatal[0]['message'] ?? '');
        assert_not_contains(BASEHIM_ROOT, $fatal[0]['message'] ?? '', 'no server paths');
        assert_true($r['good']['ok'], 'healthy app passes: ' . export($r['good']['errors']));
        assert_true($r['good']['isolated']);
    } finally { bh_ah_cleanup($bad); bh_ah_cleanup($good); }
});

test('app health: the fingerprint changes when a file or the manifest changes', function () {
    $dir = bh_ah_app('public function boot(): void {}');
    try {
        $pf = new Preflight(BASEHIM_ROOT);
        $a = $pf->fingerprint($dir);
        assert_same($a, $pf->fingerprint($dir));
        file_put_contents($dir . '/src/App.php', file_get_contents($dir . '/src/App.php') . "\n// edit\n");
        clearstatcache();
        $b = $pf->fingerprint($dir);
        assert_true($a !== $b, 'edit changes it');
        file_put_contents($dir . '/src/Extra.php', "<?php\n");
        clearstatcache();
        assert_true($b !== $pf->fingerprint($dir), 'a new file changes it');
    } finally { bh_ah_cleanup($dir); }
});

test('app health: messages lose server paths and secrets', function () {
    $g = new AppGuard(new HealthStore(sys_get_temp_dir() . '/bh-ah-store-' . getmypid()), new Preflight(BASEHIM_ROOT));
    $m = $g->sanitize('Failed in ' . BASEHIM_ROOT . '/content/apps/x/src/App.php with password=hunter2 and /home/someone/secret.txt');
    assert_not_contains(BASEHIM_ROOT, $m);
    assert_not_contains('hunter2', $m);
    assert_not_contains('/home/someone', $m);
    assert_contains('content/apps/x/src/App.php', $m);
});

test('app health: an unreachable database is infrastructure, not an app defect', function () {
    assert_same('infrastructure', AppGuard::categoryOf(new \PDOException('SQLSTATE[HY000] [2002] Connection refused')));
    assert_same('infrastructure', AppGuard::categoryOf(new \RuntimeException('wrapped', 0, new \PDOException('x'))));
    assert_same('type-error', AppGuard::categoryOf(new \TypeError('x')));
    assert_same('dependency', AppGuard::categoryOf(new \Error('Class "Foo\\Bar" not found')));
    assert_same('exception', AppGuard::categoryOf(new \RuntimeException('boom')));
    assert_true(AppGuard::isDeclarationFatal(E_COMPILE_ERROR, 'anything'));
    assert_true(AppGuard::isDeclarationFatal(E_ERROR, 'Class X contains 1 abstract method and must therefore be declared abstract'));
    assert_false(AppGuard::isDeclarationFatal(E_ERROR, 'Allowed memory size of 134217728 bytes exhausted'));
});

test('app health: with the database down, the quarantine is still kept (and written later)', function () {
    $dir = sys_get_temp_dir() . '/bh-ah-store-' . bin2hex(random_bytes(4));
    $store = new HealthStore($dir);
    $down = static function () { throw new \PDOException('SQLSTATE[HY000] [2002] Connection refused'); };
    $g = new AppGuard($store, new Preflight(BASEHIM_ROOT), $down);
    try {
        $rec = $g->quarantine('demo', '1.0.0', 'boot', ['category' => 'exception', 'message' => 'boom', 'error_type' => 'RuntimeException']);
        assert_true($rec['stored'], 'record written to disk');
        assert_false($rec['deactivated'], 'must not claim the database was updated');
        assert_contains('could not be updated', (string) ($rec['persist_note'] ?? ''));
        // A new request (fresh guard) still sees it quarantined, from the file.
        $g2 = new AppGuard(new HealthStore($dir), new Preflight(BASEHIM_ROOT), $down);
        assert_true($g2->isQuarantined('demo'));
        // The same failure again is not a second failure.
        $again = $g2->quarantine('demo', '1.0.0', 'boot', ['category' => 'exception', 'message' => 'boom']);
        assert_same(1, (int) $again['failures']);
        assert_same([], $again['history']);
    } finally { bh_ah_rm($dir); }
});

test('app health: ambiguous fatals (memory, time) quarantine only on the third in ten minutes', function () {
    $dir = sys_get_temp_dir() . '/bh-ah-store-' . bin2hex(random_bytes(4));
    $g = new AppGuard(new HealthStore($dir), new Preflight(BASEHIM_ROOT));
    $strike = new ReflectionMethod($g, 'strike');
    try {
        $f = ['category' => 'resource-limit', 'message' => 'Allowed memory size exhausted', 'error_type' => 'E_ERROR'];
        assert_false($strike->invoke($g, 'demo', 'request', $f));
        assert_false($g->isQuarantined('demo'));
        assert_false($strike->invoke($g, 'demo', 'request', $f));
        $strike->invoke($g, 'demo', 'request', $f);
        assert_true($g->isQuarantined('demo'), 'third strike quarantines');
    } finally { bh_ah_rm($dir); }
});

test('app health: report files cannot be named outside the store', function () {
    assert_same('', HealthStore::safeSlug('../etc/passwd'));
    assert_same('', HealthStore::safeSlug('a/b'));
    assert_same('', HealthStore::safeSlug('index.json'));
    assert_same('kicad-web', HealthStore::safeSlug('kicad-web'));
});
