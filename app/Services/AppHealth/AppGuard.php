<?php

declare(strict_types=1);

namespace App\Services\AppHealth;

use App\Core\Database;
use App\Core\Logger;

/**
 * AppGuard — keeps one broken app from taking the site down (1.2.71).
 *
 * Every app's code is loaded through AppService, and AppService asks this
 * class first:
 *
 *  - safeToLoad(): is the app quarantined, or has its code changed since it
 *    last passed Preflight? A changed app is checked again before any of its
 *    code is included in this process.
 *  - begin()/stage()/end() mark which app is loading and at what stage, so a
 *    failure is credited to the right app — including an engine fatal, which
 *    no try/catch sees and only the shutdown handler can report.
 *  - quarantine() records the failure (storage/app-health/{slug}.json), sets
 *    the app's status to 'error' when the database is reachable, logs it once,
 *    and from then on the app is not loaded until an administrator validates
 *    and reactivates it.
 *
 * What it cannot do: an engine fatal (an incompatible override, a class name
 * already in use) still ends the request it happens in — PHP gives no way to
 * continue after one. The shutdown handler quarantines the app, so that
 * request answers 503 with an automatic retry and the next one runs without
 * the app. Preflight exists so that this case is caught before the code is
 * loaded at all: at install, upgrade, activation, and whenever an active app's
 * files or core change.
 *
 * This is fault isolation, not a sandbox. An app's code runs with the same
 * rights as core.
 */
final class AppGuard
{
    /** Failures with an ambiguous cause count as strikes; this many in STRIKE_WINDOW quarantine. */
    public const STRIKES = 3;
    public const STRIKE_WINDOW = 600;
    /** Retry a database write that failed at most this often (seconds). */
    public const PERSIST_RETRY = 60;
    /** Earlier failures kept in a record. */
    public const HISTORY = 5;

    private string $requestId;
    private ?string $current = null;
    private string $stage = '';
    private ?string $currentVersion = null;
    private bool $shutdownRegistered = false;
    /** @var array<string,true> failures already handled in this request */
    private array $handled = [];
    /** @var array<string,true> apps whose fingerprint was looked at in this request */
    private array $looked = [];
    /** @var array<string,true> apps whose unchanged fingerprint is to be noted (one index write) */
    private array $pendingSeen = [];
        /** @var array<string,string> app slug => its directory (for crediting fatals by file) */
    private array $dirs = [];

    public function __construct(
        private HealthStore $store,
        private Preflight $preflight,
        private ?\Closure $db = null,
        private ?\Closure $logger = null,
        private ?\Closure $appLogger = null,
    ) {
        $this->requestId = bin2hex(random_bytes(6));
    }

    public function store(): HealthStore { return $this->store; }
    public function preflight(): Preflight { return $this->preflight; }
    public function requestId(): string { return $this->requestId; }
    public function currentApp(): ?string { return $this->current; }

    // ------------------------------------------------------------------
    // State
    // ------------------------------------------------------------------

    /** The app's health record, or null when it has none. */
    public function record(string $slug): ?array
    {
        return $this->store->get($slug);
    }

    public function isQuarantined(string $slug): bool
    {
        return ($this->store->get($slug)['state'] ?? '') === 'quarantined';
    }

    /** Quarantined apps, keyed by slug. */
    public function quarantined(): array
    {
        return array_filter($this->store->all(), static fn($r) => ($r['state'] ?? '') === 'quarantined');
    }

    // ------------------------------------------------------------------
    // Before loading
    // ------------------------------------------------------------------

    /**
     * Whether this app's code may be included in this process: it is not
     * quarantined, and its files (as they are now) passed Preflight.
     *
     * A stale or missing validation runs Preflight now. When it fails and the
     * app is active, the app is quarantined; when it is not active (an install
     * or an activation attempt), the report is saved for the admin and the
     * app stays off.
     *
     * @param bool $fresh re-read file times now (after an upload) instead of trusting the last minute's
     */
    public function safeToLoad(string $slug, string $dir, array $manifest, bool $active, bool $fresh = false): bool
    {
        $this->dirs[$slug] = rtrim(str_replace('\\', '/', $dir), '/');
        if ($this->isQuarantined($slug) && ($this->store->get($slug)['allow_load'] ?? null) !== $this->requestId) {
            return false;
        }
        $report = $this->validation($slug, $dir, $manifest, $fresh);
        $this->flushSeen();
        if ($report === null || $report['ok']) return true;

        if ($active) {
            $this->quarantine($slug, (string) ($manifest['version'] ?? ''), 'validation', $this->fromReport($report), $report);
        } else {
            $this->saveReport($slug, $report, (string) ($manifest['version'] ?? ''));
        }
        return false;
    }

    /**
     * Preflight several active apps at once (boot), so their isolated load
     * tests run in parallel. Returns the slugs that may boot.
     *
     * @param array<string, array{dir:string, manifest:array}> $apps
     * @return string[]
     */
    public function preflightActive(array $apps): array
    {
        $stale = [];
        $ok = [];
        foreach ($apps as $slug => $a) {
            $this->dirs[$slug] = rtrim(str_replace('\\', '/', $a['dir']), '/');
            if ($this->isQuarantined($slug)) {
                $this->persistPending($slug);
                continue;
            }
            $cached = $this->cachedValidation($slug, $a['dir'], false);
            if ($cached === null) { $stale[$slug] = $a; continue; }
            if ($cached['ok']) { $ok[] = $slug; continue; }
            // Failed before and nothing changed: quarantine (idempotent) and skip.
            $this->quarantine($slug, (string) ($a['manifest']['version'] ?? ''), 'validation', $this->fromReport($cached), $cached);
        }
        if ($stale) {
            $reports = $this->runLocked($stale);
            foreach ($reports as $slug => $report) {
                if ($report['ok']) { $ok[] = $slug; continue; }
                $this->quarantine($slug, (string) ($stale[$slug]['manifest']['version'] ?? ''), 'validation', $this->fromReport($report), $report);
            }
        }
        $this->flushSeen();
        return $ok;
    }

    /**
     * Validate an app now, ignoring the cache (activation, "Check again").
     * Saves the report and updates the cache.
     */
    public function validateNow(string $slug, string $dir, array $manifest): array
    {
        $this->dirs[$slug] = rtrim(str_replace('\\', '/', $dir), '/');
        $report = $this->preflight->run($slug, $dir, $manifest);
        $this->cache($slug, $report);
        $this->saveReport($slug, $report, (string) ($manifest['version'] ?? ''));
        return $report;
    }

    /** The cached validation if it still matches the files, else a fresh one. */
    private function validation(string $slug, string $dir, array $manifest, bool $fresh): ?array
    {
        $cached = $this->cachedValidation($slug, $dir, $fresh);
        if ($cached !== null) return $cached;
        return $this->runLocked([$slug => ['dir' => $dir, 'manifest' => $manifest]])[$slug] ?? null;
    }

    /**
     * The cached result for the app's current files, or null when there is
     * none or it no longer applies. File times are re-read at most once a
     * minute per app (FINGERPRINT_TTL), and always when $fresh.
     */
    private function cachedValidation(string $slug, string $dir, bool $fresh): ?array
    {
        $entry = $this->store->index()['validated'][$slug] ?? null;
        if (!is_array($entry) || empty($entry['fp'])) return null;
        $core = (defined('BASEHIM_VERSION') ? BASEHIM_VERSION : '?') . '/' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
        if (($entry['core'] ?? '') !== $core) return null;

        $recent = !$fresh && (time() - (int) ($entry['seen'] ?? 0)) < Preflight::FINGERPRINT_TTL;
        if (!$recent && !isset($this->looked[$slug])) {
            $this->looked[$slug] = true;
            $fp = $this->preflight->fingerprint($dir);
            if ($fp !== $entry['fp']) return null;
            $this->pendingSeen[$slug] = true;
        }
        return ['ok' => (bool) $entry['ok'], 'errors' => $entry['errors'] ?? [], 'warnings' => [],
                'isolated' => (bool) ($entry['isolated'] ?? false), 'isolated_note' => (string) ($entry['note'] ?? ''),
                'fingerprint' => $entry['fp'], 'checked_at' => (string) ($entry['at'] ?? ''), 'cached' => true];
    }

    /** Note, in one index write, the apps whose files were found unchanged. */
    private function flushSeen(): void
    {
        if (!$this->pendingSeen) return;
        $slugs = array_keys($this->pendingSeen);
        $this->pendingSeen = [];
        $now = time();
        $this->store->updateIndex(static function (array $i) use ($slugs, $now): array {
            foreach ($slugs as $slug) {
                if (isset($i['validated'][$slug])) $i['validated'][$slug]['seen'] = $now;
            }
            return $i;
        });
    }

    /**
     * Run Preflight for apps that need it, one request at a time: a second
     * request arriving meanwhile waits for the first and then reads its result
     * instead of starting the same checks again.
     */
    private function runLocked(array $apps): array
    {
        $lock = $this->store->writable() ? @fopen($this->store->dir() . '/.validate.lock', 'c') : false;
        if ($lock !== false) flock($lock, LOCK_EX);
        try {
            if ($lock !== false) {
                // Another request may have finished these while this one waited.
                $this->store->refresh();
                $reports = [];
                foreach ($apps as $slug => $a) {
                    unset($this->looked[$slug]);
                    $c = $this->cachedValidation($slug, $a['dir'], true);
                    if ($c !== null) { $reports[$slug] = $c; unset($apps[$slug]); }
                }
            } else {
                $reports = [];
            }
            if ($apps) {
                foreach ($this->preflight->runMany($apps) as $slug => $report) {
                    $this->cache($slug, $report);
                    if (!$report['ok']) $this->saveReport($slug, $report, (string) ($apps[$slug]['manifest']['version'] ?? ''));
                    $reports[$slug] = $report;
                }
            }
            return $reports;
        } finally {
            if ($lock !== false) { flock($lock, LOCK_UN); fclose($lock); }
        }
    }

    private function cache(string $slug, array $report): void
    {
        $paths = $report['paths'] ?? null;
        $core = (defined('BASEHIM_VERSION') ? BASEHIM_VERSION : '?') . '/' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
        $this->looked[$slug] = true;
        $this->store->updateIndex(static function (array $i) use ($slug, $report, $core, $paths): array {
            $i['validated'][$slug] = [
                'fp' => $report['fingerprint'], 'ok' => $report['ok'], 'core' => $core,
                'isolated' => $report['isolated'], 'note' => $report['isolated_note'],
                'at' => $report['checked_at'], 'seen' => time(),
                'errors' => array_slice($report['errors'], 0, 5),
            ];
            // URL prefixes read from the source, until the app has booted and
            // registered its real ones (rememberRoutes).
            if (is_array($paths) && empty($i['routes'][$slug]['booted'])) {
                $i['routes'][$slug] = ['admin' => $paths['admin'] ?? [], 'public' => $paths['public'] ?? [], 'booted' => false];
            }
            return $i;
        });
    }

    /** Keep the latest full report on the app's record (shown on its health page). */
    private function saveReport(string $slug, array $report, string $version): void
    {
        $rec = $this->store->get($slug) ?? ['state' => 'ok', 'history' => []];
        $rec['validation'] = $this->trimReport($report);
        if ($version !== '') $rec['version'] = $version;
        if (!$report['ok'] && ($rec['state'] ?? 'ok') !== 'quarantined') $rec['state'] = 'invalid';
        if ($report['ok'] && ($rec['state'] ?? '') === 'invalid') $rec['state'] = 'ok';
        $this->store->put($slug, $rec);
    }

    private function trimReport(array $r): array
    {
        unset($r['cached'], $r['paths']);
        $r['errors'] = array_slice($r['errors'], 0, 30);
        $r['warnings'] = array_slice($r['warnings'] ?? [], 0, 30);
        return $r;
    }

    // ------------------------------------------------------------------
    // While loading
    // ------------------------------------------------------------------

    public function begin(string $slug, string $stage, ?string $version = null): void
    {
        $this->current = $slug;
        $this->stage = $stage;
        $this->currentVersion = $version;
    }

    public function stage(string $stage): void
    {
        if ($this->current !== null) $this->stage = $stage;
    }

    public function end(): void
    {
        $this->current = null;
        $this->stage = '';
        $this->currentVersion = null;
    }

    /**
     * An exception or error escaped an app's loading or boot().
     *
     * An infrastructure failure (the database is unreachable) does not mean
     * the app is broken: it is logged and the app is skipped for this request
     * only. Anything else quarantines the app.
     *
     * @return bool true when the app was quarantined
     */
    public function failed(string $slug, ?string $version, string $stage, \Throwable $e): bool
    {
        $category = self::categoryOf($e);
        $failure = [
            'category' => $category,
            'error_type' => get_class($e),
            'message' => $this->sanitize($e->getMessage()),
            'file' => $this->relPath($e->getFile()),
            'line' => $e->getLine(),
            'trace' => $this->sanitizeTrace($e),
        ];
        if ($category === 'infrastructure') {
            $this->log('warning', "App '{$slug}' skipped for this request: {$failure['message']}", $slug, $failure + ['stage' => $stage]);
            return false;
        }
        $this->quarantine($slug, (string) $version, $stage, $failure);
        return true;
    }

    // ------------------------------------------------------------------
    // Quarantine
    // ------------------------------------------------------------------

    /**
     * Switch an app off after a failure. Idempotent: the same failure is
     * recorded and logged once, and the database is written only while the
     * app is still marked active there.
     *
     * @param array $failure category, error_type, message, file, line
     */
    public function quarantine(string $slug, string $version, string $stage, array $failure, ?array $report = null): array
    {
        $key = $slug . '|' . $stage . '|' . ($failure['message'] ?? '');
        $rec = $this->store->get($slug) ?? ['history' => []];
        if (isset($this->handled[$key])) return $rec;
        $this->handled[$key] = true;

        $now = gmdate('c');
        $failure = [
            'category' => (string) ($failure['category'] ?? 'error'),
            'stage' => $stage,
            'error_type' => (string) ($failure['error_type'] ?? ''),
            'message' => mb_substr((string) ($failure['message'] ?? ''), 0, 1000),
            'file' => (string) ($failure['file'] ?? ''),
            'line' => (int) ($failure['line'] ?? 0),
            'trace' => array_slice((array) ($failure['trace'] ?? []), 0, 12),
            'at' => $now,
            'request_id' => $this->requestId,
            'url' => $this->requestPath(),
        ];

        $same = ($rec['state'] ?? '') === 'quarantined'
            && ($rec['failure']['message'] ?? null) === $failure['message']
            && ($rec['failure']['stage'] ?? null) === $stage;

        if (!$same) {
            if (!empty($rec['failure'])) {
                array_unshift($rec['history'], $rec['failure']);
                $rec['history'] = array_slice($rec['history'], 0, self::HISTORY);
            }
            $rec['failure'] = $failure;
            $rec['failures'] = (int) ($rec['failures'] ?? 0) + 1;
            $rec['quarantined_at'] = $now;
        }
        $rec['state'] = 'quarantined';
        $rec['recovery'] = ($rec['recovery'] ?? '') === 'retrying' ? 'retry-failed' : 'quarantined';
        if ($version !== '') $rec['version'] = $version;
        if ($report !== null) $rec['validation'] = $this->trimReport($report);
        unset($rec['allow_load'], $rec['strikes']);

        // Persist in the database, unless already done for this failure.
        if (!$same || empty($rec['deactivated'])) {
            $rec['deactivated'] = $this->markError($slug);
            $rec['persist_attempt_at'] = time();
            if (!$rec['deactivated']) {
                $rec['persist_note'] = 'The database could not be updated; the app stays switched off through this record until it can.';
            } else {
                unset($rec['persist_note']);
            }
        }
        $stored = $this->store->put($slug, $rec);
        $rec['stored'] = $stored;

        if (!$same) {
            $this->log('error', "App '{$slug}' was switched off: it failed at {$stage}. {$failure['message']}", $slug, [
                'stage' => $stage, 'category' => $failure['category'], 'error_type' => $failure['error_type'],
                'file' => $failure['file'], 'line' => $failure['line'], 'request_id' => $this->requestId,
                'deactivated' => $rec['deactivated'], 'recorded' => $stored,
            ]);
        }
        return $rec;
    }

    /** Retry the status write of a quarantine whose database update failed earlier, at most once a minute. */
    public function persistPending(string $slug): void
    {
        $rec = $this->store->get($slug);
        if (!$rec || ($rec['state'] ?? '') !== 'quarantined' || !empty($rec['deactivated'])) return;
        if (time() - (int) ($rec['persist_attempt_at'] ?? 0) < self::PERSIST_RETRY) return;
        $rec['persist_attempt_at'] = time();
        $rec['deactivated'] = $this->markError($slug);
        if ($rec['deactivated']) unset($rec['persist_note']);
        $this->store->put($slug, $rec);
    }

    /** UPDATE apps SET status='error' while it is 'active'. False when the database could not be written. */
    private function markError(string $slug): bool
    {
        $db = $this->db();
        if ($db === null) return false;
        try {
            $db->execute("UPDATE {apps} SET status = 'error' WHERE slug = :slug AND status = 'active'", ['slug' => $slug]);
            return true;
        } catch (\Throwable) {
            // An old install whose ENUM lacks 'error' still gets the app off.
            try {
                $db->execute("UPDATE {apps} SET status = 'inactive', activated_at = NULL WHERE slug = :slug AND status = 'active'", ['slug' => $slug]);
                return true;
            } catch (\Throwable) {
                return false;
            }
        }
    }

    /**
     * An administrator asked to try the app again. Called once Preflight has
     * passed; the next load in this request is allowed and the app's record
     * says it is being retried. A failure from here quarantines it again,
     * with the earlier failure kept in its history.
     */
    public function beginRetry(string $slug): void
    {
        $rec = $this->store->get($slug) ?? ['history' => []];
        $rec['state'] = 'retrying';
        $rec['recovery'] = 'retrying';
        $rec['retries'] = (int) ($rec['retries'] ?? 0) + 1;
        $rec['retried_at'] = gmdate('c');
        unset($rec['strikes']);
        $this->store->put($slug, $rec);
        $this->handled = [];
    }

    /** An app booted cleanly: close an open recovery, and remember its URL prefixes. */
    public function booted(string $slug, array $routes): void
    {
        $rec = $this->store->get($slug);
        if ($rec !== null && in_array($rec['state'] ?? '', ['retrying', 'invalid'], true)) {
            $rec['state'] = 'ok';
            $rec['recovery'] = 'recovered';
            $rec['recovered_at'] = gmdate('c');
            if (!empty($rec['failure'])) {
                array_unshift($rec['history'], $rec['failure']);
                $rec['history'] = array_slice($rec['history'], 0, self::HISTORY);
                unset($rec['failure']);
            }
            $this->store->put($slug, $rec);
            $this->log('info', "App '{$slug}' started again after its failure.", $slug, ['request_id' => $this->requestId]);
        }
        $this->rememberRoutes($slug, $routes);
    }

    /** Clear an app's state after an administrator switched it off or removed it. */
    public function released(string $slug, bool $forget = false): void
    {
        if ($forget) { $this->store->forget($slug); return; }
        $rec = $this->store->get($slug);
        if ($rec === null) return;
        if (in_array($rec['state'] ?? '', ['quarantined', 'retrying'], true)) {
            $rec['state'] = 'off';
            $rec['recovery'] = 'switched-off';
            $this->store->put($slug, $rec);
        }
    }

    // ------------------------------------------------------------------
    // URL prefixes of apps (to answer for an app that is off)
    // ------------------------------------------------------------------

    /**
     * @param array<int, array{pattern:string, owner:?string, methods:array}> $routes Router::routes()
     */
    private function rememberRoutes(string $slug, array $routes): void
    {
        $admin = [];
        $public = [];
        foreach ($routes as $r) {
            if (($r['owner'] ?? null) !== $slug || !empty($r['fallback'])) continue;
            $segs = explode('/', trim((string) $r['pattern'], '/'));
            $lit = [];
            foreach ($segs as $s) { if ($s === '' || $s[0] === '{') break; $lit[] = $s; }
            if (!$lit) continue;
            if ($lit[0] === 'admin') {
                if (count($lit) < 2 || in_array($lit[1], ['ajax', 'apps', 'login', 'logout'], true)) continue;
                $admin['/admin/' . $lit[1]] = true;
            } elseif (!in_array($lit[0], ['api', 'mcp', 'oauth', 'ajax'], true)) {
                $public['/' . $lit[0]] = true;
            }
        }
        $admin = array_keys($admin); sort($admin);
        $public = array_keys($public); sort($public);
        $known = $this->store->index()['routes'][$slug] ?? null;
        $now = ['admin' => $admin, 'public' => $public, 'booted' => true];
        if ($known === $now) return;
        $this->store->updateIndex(static function (array $i) use ($slug, $now): array {
            $i['routes'][$slug] = $now;
            return $i;
        });
    }

    /**
     * The quarantined app that owned this path, or null.
     *
     * @return array{slug:string, admin:bool}|null
     */
    public function ownerOfPath(string $path): ?array
    {
        $path = '/' . trim($path, '/');
        $routes = $this->store->index()['routes'] ?? [];
        foreach ($this->quarantined() as $slug => $_) {
            foreach (['admin' => true, 'public' => false] as $kind => $isAdmin) {
                foreach ((array) ($routes[$slug][$kind] ?? []) as $prefix) {
                    if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                        return ['slug' => $slug, 'admin' => $isAdmin];
                    }
                }
            }
        }
        return null;
    }

    // ------------------------------------------------------------------
    // Engine fatals
    // ------------------------------------------------------------------

    public function registerShutdown(): void
    {
        if ($this->shutdownRegistered) return;
        $this->shutdownRegistered = true;
        register_shutdown_function([$this, 'onShutdown']);
    }

    /**
     * After an engine fatal: if it came from an app (it was loading, or the
     * file is the app's), quarantine the app, and answer this request with a
     * 503 that retries by itself, since the next request runs without it.
     *
     * @internal called by PHP at shutdown
     */
    public function onShutdown(): void
    {
        $e = error_get_last();
        if (!$e || !in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR], true)) return;
        $message = (string) $e['message'];
        $file = str_replace('\\', '/', (string) $e['file']);

        $slug = $this->slugForFile($file);
        $stage = 'request';
        if ($this->current !== null && ($slug === null || $slug === $this->current)) {
            $slug = $this->current;
            $stage = $this->stage ?: 'load';
        }
        if ($slug === null) return;

        $definite = self::isDeclarationFatal($e['type'], $message);
        $resource = (bool) preg_match('/Allowed memory size|Maximum execution time|Out of memory/i', $message);

        $failure = [
            'category' => $definite ? 'declaration' : ($resource ? 'resource-limit' : 'fatal'),
            'error_type' => self::levelName($e['type']),
            'message' => $this->sanitize($message),
            'file' => $this->relPath($file),
            'line' => (int) $e['line'],
        ];

        $quarantined = false;
        try {
            if ($definite || ($stage !== 'request' && !$resource)) {
                $rec = $this->quarantine($slug, (string) $this->currentVersion, $stage, $failure);
                $quarantined = !empty($rec['stored']) || !empty($rec['deactivated']);
            } else {
                // Memory, time, or a fatal while serving a page: may be the
                // server. Repeated, it is the app.
                $quarantined = $this->strike($slug, $stage, $failure);
            }
        } catch (\Throwable) {
            return;
        }

        if ($quarantined && !headers_sent()) {
            while (ob_get_level() > 0) @ob_end_clean();
            $this->respondRetry();
        }
    }

    /** Count an ambiguous failure; quarantine on the third within ten minutes. */
    private function strike(string $slug, string $stage, array $failure): bool
    {
        $rec = $this->store->get($slug) ?? ['history' => []];
        $now = time();
        $strikes = array_values(array_filter((array) ($rec['strikes'] ?? []), static fn($t) => $now - (int) $t < self::STRIKE_WINDOW));
        $strikes[] = $now;
        if (count($strikes) >= self::STRIKES) {
            $failure['message'] .= ' (' . count($strikes) . ' times in ' . (int) (self::STRIKE_WINDOW / 60) . ' minutes)';
            $rec2 = $this->quarantine($slug, (string) $this->currentVersion, $stage, $failure);
            return !empty($rec2['stored']);
        }
        $rec['strikes'] = $strikes;
        $rec['state'] ??= 'ok';
        $rec['last_strike'] = $failure + ['stage' => $stage, 'at' => gmdate('c'), 'request_id' => $this->requestId];
        $this->store->put($slug, $rec);
        $this->log('warning', "App '{$slug}' hit a fatal error ({$failure['message']}); " . count($strikes) . ' of ' . self::STRIKES . ' before it is switched off.', $slug, ['stage' => $stage, 'request_id' => $this->requestId]);
        return false;
    }

    private function respondRetry(): void
    {
        $accept = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');
        $path = $this->requestPath();
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        // After a fatal, PHP has already chosen 500; an explicit status line
        // replaces it on every SAPI (http_response_code() alone does not on all).
        $proto = (string) ($_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.1');
        header((preg_match('#^HTTP/[\d.]+$#', $proto) ? $proto : 'HTTP/1.1') . ' 503 Service Unavailable', true, 503);
        http_response_code(503);
        header('Retry-After: 1');
        header('Cache-Control: no-store');
        header('X-Basehim-Request-Id: ' . $this->requestId);
        if (str_contains($accept, 'application/json') || preg_match('#^/(api|mcp|ajax)(/|$)#', $path) || str_contains($path, '/admin/ajax')) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'error' => ['code' => 'APP_DISABLED', 'message' => 'An app failed to load and has been switched off to protect the site. Please try again.'],
                'request_id' => $this->requestId,
            ]);
            return;
        }
        header('Content-Type: text/html; charset=utf-8');
        // Reload by itself once: the next request runs without the app.
        $refresh = in_array($method, ['GET', 'HEAD'], true);
        if ($refresh) header('Refresh: 1');
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>One moment</title><style>body{font:16px/1.5 system-ui,sans-serif;background:#f8fafc;color:#0f172a;display:grid;place-items:center;min-height:100vh;margin:0}'
            . 'main{max-width:28rem;padding:2rem;text-align:center}p{color:#475569}</style></head><body><main>'
            . '<h1>One moment…</h1><p>Part of this site had a problem and has been switched off. '
            . ($refresh ? 'The page will reload by itself.' : 'Please go back and try again.') . '</p></main></body></html>';
    }

    // ------------------------------------------------------------------
    // Classification and cleaning
    // ------------------------------------------------------------------

    /** 'infrastructure' when the cause is outside the app (database unreachable), else a defect category. */
    public static function categoryOf(\Throwable $e): string
    {
        for ($x = $e; $x !== null; $x = $x->getPrevious()) {
            if ($x instanceof \PDOException) return 'infrastructure';
            if (preg_match('/SQLSTATE\[(HY000|08\d{3})\]|Connection refused|server has gone away|Too many connections/i', $x->getMessage())) return 'infrastructure';
        }
        if ($e instanceof \ParseError) return 'syntax';
        if ($e instanceof \TypeError || $e instanceof \ArgumentCountError) return 'type-error';
        if ($e instanceof \Error && preg_match('/^(Class|Interface|Trait|Enum) "?[^"]+"? not found|Call to undefined (function|method)/', $e->getMessage())) return 'dependency';
        if ($e instanceof \Error) return 'error';
        return 'exception';
    }

    public static function isDeclarationFatal(int $type, string $message): bool
    {
        if (in_array($type, [E_COMPILE_ERROR, E_PARSE, E_CORE_ERROR], true)) return true;
        return (bool) preg_match('/must be compatible with|Access level to|Cannot (re)?declare|contains \d+ abstract method|Cannot (make|override|inherit)|may not inherit|cannot implement|cannot extend/i', $message);
    }

    private static function levelName(int $type): string
    {
        return match ($type) {
            E_ERROR => 'E_ERROR', E_PARSE => 'E_PARSE', E_CORE_ERROR => 'E_CORE_ERROR',
            E_COMPILE_ERROR => 'E_COMPILE_ERROR', E_USER_ERROR => 'E_USER_ERROR',
            E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR', default => 'E_' . $type,
        };
    }

    /** The app a file belongs to, from its path under content/apps. */
    private function slugForFile(string $file): ?string
    {
        foreach ($this->dirs as $slug => $dir) {
            if ($dir !== '' && str_starts_with($file, $dir . '/')) return $slug;
        }
        $root = defined('BASEHIM_ROOT') ? rtrim(str_replace('\\', '/', (string) BASEHIM_ROOT), '/') : '';
        $prefix = $root . '/content/apps/';
        if ($root !== '' && str_starts_with($file, $prefix)) {
            $slug = explode('/', substr($file, strlen($prefix)))[0] ?? '';
            return HealthStore::safeSlug($slug) ?: null;
        }
        return null;
    }

    /**
     * A message fit for the report: no server paths, nothing that looks like
     * a credential, one line, bounded.
     */
    public function sanitize(string $msg): string
    {
        $root = defined('BASEHIM_ROOT') ? rtrim(str_replace('\\', '/', (string) BASEHIM_ROOT), '/') : '';
        if ($root !== '') $msg = str_replace([$root . '/', $root], ['', ''], $msg);
        $msg = preg_replace('#(?<![\w.])/(?:home|var|srv|usr|opt|tmp|Users)/[^\s:\'"),]+#', '[path]', $msg) ?? $msg;
        $msg = preg_replace('/((?:password|passwd|pwd|secret|token|api[_-]?key|authorization)\s*[=:]\s*)\S+/i', '$1[redacted]', $msg) ?? $msg;
        $msg = preg_replace("/\s+/", ' ', $msg) ?? $msg;
        return mb_substr(trim($msg), 0, 1000);
    }

    /** Frames of a trace as "file:line function", paths relative to the site. */
    private function sanitizeTrace(\Throwable $e): array
    {
        $out = [];
        foreach (array_slice($e->getTrace(), 0, 12) as $f) {
            $where = isset($f['file']) ? $this->relPath((string) $f['file']) . ':' . (int) ($f['line'] ?? 0) : '[internal]';
            $fn = ($f['class'] ?? '') . ($f['type'] ?? '') . ($f['function'] ?? '') . '()';
            $out[] = $where . ' ' . $fn;
        }
        return $out;
    }

    private function relPath(string $file): string
    {
        $file = str_replace('\\', '/', $file);
        $root = defined('BASEHIM_ROOT') ? rtrim(str_replace('\\', '/', (string) BASEHIM_ROOT), '/') : '';
        if ($root !== '' && str_starts_with($file, $root . '/')) return substr($file, strlen($root) + 1);
        return $file === '' ? '' : basename($file);
    }

    private function requestPath(): string
    {
        $uri = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        $base = defined('BASEHIM_BASE') ? (string) BASEHIM_BASE : '';
        if ($base !== '' && str_starts_with($uri, $base)) $uri = (string) substr($uri, strlen($base));
        return $uri === '' ? (PHP_SAPI === 'cli' ? '' : '/') : mb_substr($uri, 0, 300);
    }

    private function fromReport(array $report): array
    {
        $first = $report['errors'][0] ?? ['code' => 'validation', 'message' => 'The app did not pass validation.'];
        $more = count($report['errors']) - 1;
        return [
            'category' => in_array($first['code'] ?? '', ['syntax'], true) ? 'syntax' : (($first['code'] ?? '') === 'manifest-src' || str_starts_with((string) ($first['code'] ?? ''), 'manifest') ? 'manifest' : 'declaration'),
            'error_type' => 'Validation: ' . ($first['code'] ?? 'error'),
            'message' => $this->sanitize((string) ($first['message'] ?? '')) . ($more > 0 ? " (and {$more} more)" : ''),
            'file' => (string) ($first['file'] ?? ''),
            'line' => (int) ($first['line'] ?? 0),
        ];
    }

    // ------------------------------------------------------------------

    private function db(): ?Database
    {
        if ($this->db === null) return null;
        try { return ($this->db)(); } catch (\Throwable) { return null; }
    }

    private function log(string $level, string $message, string $slug, array $context = []): void
    {
        try {
            if ($this->logger !== null) {
                $l = ($this->logger)();
                if ($l instanceof Logger) $l->{$level === 'info' ? 'info' : ($level === 'warning' ? 'warning' : 'error')}($message, ['app' => $slug] + $context);
            }
        } catch (\Throwable) {
            error_log('[basehim] ' . $message);
        }
        try {
            if ($this->appLogger !== null) {
                $al = ($this->appLogger)();
                if ($al instanceof \App\Services\AppLogger) $al->log($slug, $level, $message, $context);
            }
        } catch (\Throwable) {}
    }
}
