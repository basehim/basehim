<?php
/**
 * Basehim app preflight worker (1.2.71) — command line only.
 *
 * Started by App\Services\AppHealth\Preflight in a separate PHP process to
 * find out whether an app's classes can be declared against this version of
 * core. Some failures (an incompatible override, a class name already in use)
 * are engine fatals that end the process; here that costs only this process,
 * never a page request.
 *
 * What it does: registers core's autoloader and the app's namespace, then
 * asks PHP for each of the app's classes (class_exists() and friends, which
 * autoload them). What it does not do: read .env, connect to the database,
 * boot the application, construct the app or call boot(). Class files that
 * run code when included still run it here, in this throw-away process.
 *
 * Input (stdin, JSON): {root, version, app_dir, namespace, src, classes: {fqn: file}, entry}
 * Output (stdout): one line "BHPREFLIGHT " + JSON {ok, loaded, problems[], fatal?}
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$job = json_decode((string) stream_get_contents(STDIN), true);
if (!is_array($job) || empty($job['root']) || empty($job['app_dir'])) {
    fwrite(STDOUT, 'BHPREFLIGHT ' . json_encode(['ok' => false, 'problems' => [['code' => 'worker-input', 'message' => 'The preflight worker got no job.']]]) . "\n");
    exit(2);
}

$root = rtrim((string) $job['root'], '/');
$appDir = rtrim((string) $job['app_dir'], '/');
define('BASEHIM_ROOT', $root);
define('BASEHIM_START', microtime(true));
define('BASEHIM_BASE', '');
define('BASEHIM_VERSION', (string) ($job['version'] ?? '0.0.0'));
define('BASEHIM_PREFLIGHT', true);

ini_set('display_errors', '0');
ini_set('log_errors', '0');
error_reporting(E_ALL);

$state = ['current' => null, 'loaded' => 0, 'problems' => [], 'done' => false];

$rel = static function (string $file) use ($root): string {
    $file = str_replace('\\', '/', $file);
    return str_starts_with($file, $root . '/') ? substr($file, strlen($root) + 1) : basename($file);
};

// An engine fatal ends the script; report it from here.
register_shutdown_function(static function () use (&$state, $rel): void {
    if ($state['done']) return;
    $e = error_get_last();
    $fatal = null;
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR], true)) {
        $fatal = [
            'type' => $e['type'], 'message' => (string) $e['message'],
            'file' => $rel((string) $e['file']), 'line' => (int) $e['line'],
            'class' => $state['current'],
        ];
    }
    fwrite(STDOUT, 'BHPREFLIGHT ' . json_encode([
        'ok' => false, 'loaded' => $state['loaded'], 'problems' => $state['problems'], 'fatal' => $fatal,
    ], JSON_UNESCAPED_SLASHES) . "\n");
});

// Notices and warnings while declaring are reported, not fatal.
set_error_handler(static function (int $no, string $msg, string $file, int $line) use (&$state, $rel): bool {
    if (count($state['problems']) < 50) {
        $state['problems'][] = ['code' => 'php-notice', 'severity' => 'warning', 'message' => $msg, 'file' => $rel($file), 'line' => $line, 'class' => $state['current']];
    }
    return true;
});

require $root . '/app/Core/Autoloader.php';
\App\Core\Autoloader::register();

$ns = trim((string) ($job['namespace'] ?? ''), '\\');
if ($ns !== '') {
    $src = trim((string) ($job['src'] ?? 'src'), '/');
    $relDir = ltrim(substr($appDir . '/' . $src, strlen($root)), '/');
    \App\Core\Autoloader::addNamespace($ns, $relDir);
}

// Classes found by the tokenizer, for files outside the PSR-4 layout.
$map = [];
foreach ((array) ($job['classes'] ?? []) as $fqn => $file) {
    $map[strtolower(ltrim((string) $fqn, '\\'))] = (string) $file;
}
spl_autoload_register(static function (string $class) use ($map): void {
    $f = $map[strtolower($class)] ?? null;
    if ($f !== null && is_file($f)) require_once $f;
});

$order = array_keys((array) ($job['classes'] ?? []));
$entry = (string) ($job['entry'] ?? '');
if ($entry !== '') {
    $order = array_values(array_unique(array_merge([$entry], $order)));
}

foreach ($order as $fqn) {
    $state['current'] = $fqn;
    try {
        if (!class_exists($fqn) && !interface_exists($fqn) && !trait_exists($fqn) && !(function_exists('enum_exists') && enum_exists($fqn))) {
            if ($fqn === $entry) {
                $state['problems'][] = ['code' => 'entry-missing', 'severity' => 'warning', 'message' => "The entry class {$fqn} was not found.", 'class' => $fqn];
            }
            continue;
        }
        $state['loaded']++;
    } catch (\Throwable $e) {
        $state['problems'][] = [
            'code' => $e instanceof \ParseError ? 'syntax' : 'load-error',
            'severity' => $e instanceof \ParseError || $e instanceof \CompileError ? 'error' : 'warning',
            'message' => get_class($e) . ': ' . $e->getMessage(),
            'file' => $rel($e->getFile()), 'line' => $e->getLine(), 'class' => $fqn,
        ];
    }
}

$state['current'] = null;
$state['done'] = true;
$errors = array_filter($state['problems'], static fn($p) => ($p['severity'] ?? '') === 'error');
fwrite(STDOUT, 'BHPREFLIGHT ' . json_encode([
    'ok' => !$errors, 'loaded' => $state['loaded'], 'problems' => $state['problems'], 'fatal' => null,
], JSON_UNESCAPED_SLASHES) . "\n");
exit(0);
