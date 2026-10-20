<?php

declare(strict_types=1);

namespace App\Services\AppHealth;

/**
 * Preflight — can this app's code be loaded against this core? (1.2.71)
 *
 * Three checks, cheapest first:
 *
 *  1. The manifest: namespace, entry class and source folder make sense.
 *  2. DeclarationCheck: every PHP file is tokenized (syntax errors) and each
 *     class compared with its parent — without running any app code.
 *  3. The isolated load test: a separate `php` process declares the app's
 *     classes against core (preflight-worker.php). This is the only check
 *     that sees everything PHP would refuse, traits and third-party parents
 *     included, and an engine fatal there ends that process, not a page.
 *
 * (3) needs the PHP command line and proc_open. Shared hosting often has
 * both; where it does not, the report says so ("isolated": false) and (1)–(2)
 * plus the runtime guard (AppGuard's shutdown handler) are the protection.
 *
 * Results are cached by a fingerprint of the app's PHP files (paths, sizes,
 * modification times), its manifest, the core version, App.php and the PHP
 * version, so a check runs again only when one of those changes.
 */
final class Preflight
{
    /** Re-read file times at most this often (seconds) for an app already checked. */
    public const FINGERPRINT_TTL = 60;
    /** How long one isolated load test may take. */
    public const WORKER_TIMEOUT = 20;
    /** Isolated tests run at once. */
    public const PARALLEL = 6;

    private string $root;

    /** @var array{bin:?string, version:?string, reason:string}|null */
    private static ?array $cli = null;

    public function __construct(?string $root = null)
    {
        $this->root = rtrim(str_replace('\\', '/', $root ?? (defined('BASEHIM_ROOT') ? (string) BASEHIM_ROOT : dirname(__DIR__, 3))), '/');
    }

    // ------------------------------------------------------------------
    // Files and fingerprint
    // ------------------------------------------------------------------

    /** @return string[] the app's PHP files, absolute, sorted */
    public function files(string $dir): array
    {
        $out = [];
        if (!is_dir($dir)) return $out;
        $skip = ['node_modules' => true, '.git' => true];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::UNIX_PATHS),
                static fn(\SplFileInfo $f) => !($f->isDir() && isset($skip[$f->getFilename()])) && !$f->isLink()
            )
        );
        foreach ($it as $f) {
            /** @var \SplFileInfo $f */
            if ($f->isFile() && strtolower($f->getExtension()) === 'php') {
                $out[] = str_replace('\\', '/', $f->getPathname());
                if (count($out) >= 5000) break;
            }
        }
        sort($out);
        return $out;
    }

    public function fingerprint(string $dir, ?array $files = null): string
    {
        $files ??= $this->files($dir);
        $parts = [
            'core:' . (defined('BASEHIM_VERSION') ? BASEHIM_VERSION : '?'),
            'php:' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
            'base:' . @filemtime($this->root . '/app/Core/App.php') . ':' . @filesize($this->root . '/app/Core/App.php'),
            'manifest:' . @filemtime($dir . '/app.json') . ':' . @filesize($dir . '/app.json'),
        ];
        $len = strlen(rtrim($dir, '/')) + 1;
        foreach ($files as $f) {
            $parts[] = substr($f, $len) . ':' . @filesize($f) . ':' . @filemtime($f);
        }
        return sha1(implode("\n", $parts));
    }

    // ------------------------------------------------------------------
    // Running the checks
    // ------------------------------------------------------------------

    /**
     * Check one app. See runMany().
     */
    public function run(string $slug, string $dir, array $manifest, bool $isolated = true): array
    {
        return $this->runMany([$slug => ['dir' => $dir, 'manifest' => $manifest]], $isolated)[$slug];
    }

    /**
     * Check several apps; their isolated load tests run in parallel.
     *
     * @param array<string, array{dir:string, manifest:array}> $apps
     * @return array<string, array{ok:bool, errors:array, warnings:array, isolated:bool, isolated_note:string,
     *                             fingerprint:string, checked_at:string, duration_ms:int, files:int, classes:int}>
     */
    public function runMany(array $apps, bool $isolated = true): array
    {
        $reports = [];
        $jobs = [];
        foreach ($apps as $slug => $a) {
            $t0 = microtime(true);
            $dir = rtrim(str_replace('\\', '/', $a['dir']), '/');
            $manifest = $a['manifest'];
            $files = $this->files($dir);
            $problems = $this->manifestProblems($slug, $dir, $manifest);

            $decl = (new DeclarationCheck($dir))->run($files);
            $problems = array_merge($problems, $decl['problems']);

            $reports[$slug] = [
                'paths' => $this->declaredPaths($files),
                'ok' => true, 'errors' => [], 'warnings' => [], 'isolated' => false, 'isolated_note' => '',
                'fingerprint' => $this->fingerprint($dir, $files), 'checked_at' => gmdate('c'),
                'duration_ms' => 0, 'files' => count($files), 'classes' => count($decl['classes']),
                '_t0' => $t0, '_problems' => $problems,
            ];

            if ($isolated) {
                $ns = trim((string) ($manifest['namespace'] ?? ''), '\\');
                $entry = trim((string) ($manifest['entry'] ?? 'App'), '\\');
                $classes = [];
                foreach ($decl['classes'] as $c) {
                    $abs = $c['file'];
                    // Third-party and test folders: loaded only if the app asks for them.
                    if ($this->isSideFile($abs, $dir)) continue;
                    $classes[$c['name']] = $abs;
                }
                $jobs[$slug] = [
                    'root' => $this->root, 'version' => defined('BASEHIM_VERSION') ? BASEHIM_VERSION : '0.0.0',
                    'app_dir' => $dir, 'namespace' => $ns, 'src' => (string) ($manifest['src'] ?? 'src'),
                    'entry' => $ns !== '' ? $ns . '\\' . $entry : $entry, 'classes' => $classes,
                ];
            }
        }

        if ($jobs) {
            $results = $this->isolate($jobs);
            foreach ($results as $slug => $res) {
                $reports[$slug]['isolated'] = $res['ran'];
                $reports[$slug]['isolated_note'] = $res['note'];
                $reports[$slug]['_problems'] = array_merge($reports[$slug]['_problems'], $res['problems']);
            }
        } else {
            foreach ($reports as $slug => $_) {
                $reports[$slug]['isolated_note'] = 'Not run for this check.';
            }
        }

        foreach ($reports as $slug => $r) {
            $seen = [];
            foreach ($r['_problems'] as $p) {
                // The tokenizer and the load test often report the same thing.
                $key = ($p['file'] ?? '') . ':' . ($p['line'] ?? '') . ':' . ($p['severity'] ?? '');
                if (($p['code'] ?? '') === 'fatal' || !isset($seen[$key])) {
                    $seen[$key] = true;
                    if (($p['severity'] ?? 'error') === 'error') $r['errors'][] = $p; else $r['warnings'][] = $p;
                }
            }
            // An engine fatal repeats a declaration error already explained: keep the explanation first.
            $r['ok'] = $r['errors'] === [];
            $r['duration_ms'] = (int) round((microtime(true) - $r['_t0']) * 1000);
            unset($r['_t0'], $r['_problems']);
            $reports[$slug] = $r;
        }
        return $reports;
    }

    /**
     * The URL prefixes an app's source declares, read as plain text (the
     * literal strings passed to adminGet()/get()/addAdminMenu()). Used only
     * to show an app's report at its own URLs when it never got to register
     * them because it failed before booting.
     *
     * @return array{admin: string[], public: string[]}
     */
    private function declaredPaths(array $files): array
    {
        $admin = [];
        $public = [];
        foreach (array_slice($files, 0, 2000) as $f) {
            if (@filesize($f) > 2_000_000) continue;
            $code = (string) @file_get_contents($f);
            if (!str_contains($code, 'admin') && !str_contains($code, 'get(') && !str_contains($code, 'post(')) continue;
            if (preg_match_all('/->\s*admin(?:Get|Post)\s*\(\s*[\'"]\/?([A-Za-z0-9_-]+)/', $code, $m)) {
                foreach ($m[1] as $seg) $admin['/admin/' . $seg] = true;
            }
            if (preg_match_all('/[\'"]url[\'"]\s*=>\s*[\'"]\/admin\/([A-Za-z0-9_-]+)/', $code, $m)) {
                foreach ($m[1] as $seg) $admin['/admin/' . $seg] = true;
            }
            if (preg_match_all('/\$this\s*->\s*(?:get|post|route)\s*\(\s*(?:\[[^\]]*\]\s*,\s*)?[\'"]\/([A-Za-z0-9_-]+)/', $code, $m)) {
                foreach ($m[1] as $seg) $public['/' . $seg] = true;
            }
        }
        foreach (['/admin/apps', '/admin/ajax', '/admin/login', '/admin/logout', '/admin/dashboard', '/admin/settings', '/admin/posts', '/admin/pages', '/admin/media', '/admin/users'] as $core) unset($admin[$core]);
        foreach (['/admin', '/api', '/mcp', '/oauth', '/ajax', '/feed', '/search', '/sitemap.xml'] as $core) unset($public[$core]);
        $admin = array_keys($admin); sort($admin);
        $public = array_keys($public); sort($public);
        return ['admin' => array_slice($admin, 0, 20), 'public' => array_slice($public, 0, 20)];
    }

    private function manifestProblems(string $slug, string $dir, array $manifest): array
    {
        $out = [];
        $add = static function (string $code, string $msg, string $sev = 'error') use (&$out): void {
            $out[] = ['code' => $code, 'message' => $msg, 'file' => 'app.json', 'line' => 0, 'severity' => $sev];
        };
        if (!is_file($dir . '/app.json')) {
            $add('manifest-missing', 'The app has no app.json.');
            return $out;
        }
        $ns = $manifest['namespace'] ?? null;
        if ($ns !== null && (!is_string($ns) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)*\\\\?$/', $ns))) {
            $add('manifest-namespace', '"namespace" is not a valid PHP namespace.');
        } elseif (is_string($ns) && (trim($ns, '\\') === 'App' || str_starts_with(trim($ns, '\\') . '\\', 'App\\Core\\') || str_starts_with(trim($ns, '\\') . '\\', 'App\\Services\\'))) {
            $add('manifest-namespace', "\"namespace\" {$ns} is core's own; the app's classes could replace or clash with core classes.", 'warning');
        }
        $entry = $manifest['entry'] ?? null;
        if ($entry !== null && (!is_string($entry) || !preg_match('/^[A-Za-z_][A-Za-z0-9_\\\\]*$/', $entry))) {
            $add('manifest-entry', '"entry" is not a valid class name.');
        }
        if (is_string($ns) && $ns !== '') {
            $src = (string) ($manifest['src'] ?? 'src');
            if (str_contains($src, '..') || !is_dir($dir . '/' . trim($src, '/'))) {
                $add('manifest-src', "The source folder \"{$src}\" does not exist in the app.");
            }
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // Isolated load test
    // ------------------------------------------------------------------

    /**
     * Whether the isolated load test can run here, and why not.
     *
     * @return array{available:bool, bin:?string, reason:string}
     */
    public function isolation(): array
    {
        $cli = self::cli();
        return ['available' => $cli['bin'] !== null, 'bin' => $cli['bin'], 'reason' => $cli['reason']];
    }

    /**
     * @param array<string, array> $jobs
     * @return array<string, array{ran:bool, note:string, problems:array}>
     */
    private function isolate(array $jobs): array
    {
        $out = [];
        $cli = self::cli();
        if ($cli['bin'] === null) {
            foreach ($jobs as $slug => $_) {
                $out[$slug] = ['ran' => false, 'note' => 'Not available on this server: ' . $cli['reason'], 'problems' => []];
            }
            return $out;
        }

        $worker = __DIR__ . '/preflight-worker.php';
        $queue = $jobs;
        $running = [];
        while ($queue || $running) {
            while ($queue && count($running) < self::PARALLEL) {
                $slug = array_key_first($queue);
                $job = $queue[$slug];
                unset($queue[$slug]);
                $proc = @proc_open(
                    [$cli['bin'], '-d', 'display_errors=0', '-d', 'log_errors=0', '-d', 'memory_limit=256M',
                     '-d', 'max_execution_time=' . self::WORKER_TIMEOUT, '-d', 'opcache.enable_cli=0', $worker],
                    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                    $pipes,
                    $this->root,
                    // A clean environment: app code that runs at include time
                    // must not see database or API credentials from the server.
                    ['PATH' => '/usr/local/bin:/usr/bin:/bin', 'BASEHIM_PREFLIGHT' => '1']
                );
                if (!is_resource($proc)) {
                    $out[$slug] = ['ran' => false, 'note' => 'The PHP command line could not be started.', 'problems' => []];
                    continue;
                }
                fwrite($pipes[0], (string) json_encode($job, JSON_UNESCAPED_SLASHES));
                fclose($pipes[0]);
                stream_set_blocking($pipes[1], false);
                stream_set_blocking($pipes[2], false);
                $running[$slug] = ['proc' => $proc, 'pipes' => $pipes, 'out' => '', 'err' => '', 'start' => microtime(true)];
            }

            foreach ($running as $slug => &$r) {
                $r['out'] .= (string) stream_get_contents($r['pipes'][1]);
                $r['err'] .= (string) stream_get_contents($r['pipes'][2]);
                $status = proc_get_status($r['proc']);
                $timedOut = (microtime(true) - $r['start']) > self::WORKER_TIMEOUT + 5;
                if ($status['running'] && !$timedOut) continue;
                if ($timedOut) proc_terminate($r['proc'], 9);
                $r['out'] .= (string) stream_get_contents($r['pipes'][1]);
                $r['err'] .= (string) stream_get_contents($r['pipes'][2]);
                fclose($r['pipes'][1]);
                fclose($r['pipes'][2]);
                proc_close($r['proc']);
                $out[$slug] = $this->readWorker($r['out'], $r['err'], $timedOut);
                unset($running[$slug]);
            }
            unset($r);
            if ($running) usleep(10000);
        }
        return $out;
    }

    private function readWorker(string $stdout, string $stderr, bool $timedOut): array
    {
        if ($timedOut) {
            return ['ran' => true, 'note' => 'The load test did not finish in ' . self::WORKER_TIMEOUT . ' seconds.', 'problems' => [[
                'code' => 'timeout', 'severity' => 'warning', 'file' => '', 'line' => 0,
                'message' => 'Declaring the app\'s classes took longer than ' . self::WORKER_TIMEOUT . ' seconds; a class file may run slow code when it is loaded.',
            ]]];
        }
        $json = null;
        foreach (preg_split('/\r?\n/', $stdout) ?: [] as $line) {
            if (str_starts_with($line, 'BHPREFLIGHT ')) $json = json_decode(substr($line, 12), true);
        }
        if (!is_array($json)) {
            return ['ran' => false, 'note' => 'The load test gave no answer' . ($stderr !== '' ? ': ' . $this->clean(mb_substr(trim($stderr), 0, 300)) : '.'), 'problems' => []];
        }
        $problems = [];
        foreach ((array) ($json['problems'] ?? []) as $p) {
            $p['message'] = $this->clean((string) ($p['message'] ?? ''));
            $p['severity'] ??= 'warning';
            $p['file'] ??= '';
            $p['line'] ??= 0;
            $problems[] = $p;
        }
        if (!empty($json['fatal'])) {
            $f = $json['fatal'];
            $problems[] = [
                'code' => 'fatal', 'severity' => 'error',
                'message' => 'PHP refused to load ' . ($f['class'] ? $f['class'] : 'the app') . ': ' . $this->clean((string) $f['message']),
                'file' => (string) ($f['file'] ?? ''), 'line' => (int) ($f['line'] ?? 0),
            ];
        }
        return ['ran' => true, 'note' => 'Loaded ' . (int) ($json['loaded'] ?? 0) . ' class(es) in a separate PHP process.', 'problems' => $problems];
    }

    /** Server paths out of a message. */
    private function clean(string $msg): string
    {
        return str_replace([$this->root . '/', $this->root], ['', ''], $msg);
    }

    private function isSideFile(string $file, string $dir): bool
    {
        $rel = '/' . ltrim(substr($file, strlen($dir)), '/');
        foreach (DeclarationCheck::SIDE_DIRS as $d) {
            if (str_contains(strtolower($rel), '/' . $d . '/')) return true;
        }
        return false;
    }

    /**
     * Find a PHP command line of the same version as the running PHP. Under
     * PHP-FPM, PHP_BINARY is php-fpm, which cannot run a script, so the
     * usual places are tried (cPanel's ea-php, CloudLinux alt-php, distro
     * packages). PHP_CLI_BINARY in .env or the environment overrides.
     *
     * @return array{bin:?string, version:?string, reason:string}
     */
    public static function cli(): array
    {
        if (self::$cli !== null) return self::$cli;
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        if (!function_exists('proc_open') || in_array('proc_open', $disabled, true)) {
            return self::$cli = ['bin' => null, 'version' => null, 'reason' => 'proc_open is disabled.'];
        }
        $want = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
        $xy = PHP_MAJOR_VERSION . PHP_MINOR_VERSION;
        $candidates = [];
        $env = (string) (getenv('PHP_CLI_BINARY') ?: ($_ENV['PHP_CLI_BINARY'] ?? ''));
        if ($env !== '') $candidates[] = $env;
        if (PHP_SAPI === 'cli' || preg_match('#/php[\d.]*$#', PHP_BINARY)) $candidates[] = PHP_BINARY;
        array_push($candidates,
            PHP_BINDIR . '/php', PHP_BINDIR . '/php' . $want, PHP_BINDIR . '/php' . $xy,
            "/opt/cpanel/ea-php{$xy}/root/usr/bin/php", "/usr/local/bin/ea-php{$xy}", "/opt/alt/php{$xy}/usr/bin/php",
            "/usr/bin/php{$want}", "/usr/local/bin/php{$want}", '/usr/local/bin/php', '/usr/bin/php'
        );
        $tried = 0;
        foreach (array_unique($candidates) as $bin) {
            if ($bin === '' || str_contains(basename($bin), 'fpm') || str_contains(basename($bin), 'cgi')) continue;
            if (!@is_file($bin)) continue;
            $tried++;
            $v = self::probe($bin);
            if ($v === $want) return self::$cli = ['bin' => $bin, 'version' => $v, 'reason' => ''];
        }
        return self::$cli = ['bin' => null, 'version' => null, 'reason' => $tried
            ? "no PHP command line of version {$want} was found."
            : 'no PHP command line was found (set PHP_CLI_BINARY in .env to its path).'];
    }

    private static function probe(string $bin): ?string
    {
        $p = @proc_open([$bin, '-r', 'echo PHP_MAJOR_VERSION, ".", PHP_MINOR_VERSION;'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['PATH' => '/usr/local/bin:/usr/bin:/bin']);
        if (!is_resource($p)) return null;
        stream_set_blocking($pipes[1], false);
        $out = '';
        $start = microtime(true);
        while (microtime(true) - $start < 5) {
            $out .= (string) stream_get_contents($pipes[1]);
            if (!proc_get_status($p)['running']) break;
            usleep(10000);
        }
        $out .= (string) stream_get_contents($pipes[1]);
        if (proc_get_status($p)['running']) proc_terminate($p, 9);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($p);
        return preg_match('/^\d+\.\d+$/', trim($out)) ? trim($out) : null;
    }

    /** For tests: forget the detected command line. */
    public static function resetCli(): void
    {
        self::$cli = null;
    }
}
