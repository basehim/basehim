<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * UpdateService — remote updates from a CloudHim server.
 *
 * Basehim connects to a CloudHim installation (a Basehim running the
 * CloudHim app on another host) using a per-site key. From there:
 *
 *   check()   GET  {cloudhim}/api/v1/cloudhim/updates    → newer releases;
 *             doubles as the heartbeat (sends this site's URL + versions so
 *             CloudHim can list who runs what, where).
 *   apply()   GET  {cloudhim}/api/v1/cloudhim/download   → zip; SHA-256
 *             verified, then extracted over the install with a protected-path
 *             list (config, uploads, storage, .htaccess are never touched)
 *             and zip-slip protection. Migrations run automatically after.
 *
 * The available-update count is cached in settings (group "updates"), which
 * is what the sidebar badge reads — no remote calls during page renders.
 */
class UpdateService
{
    /** Paths inside the install that an update must NEVER overwrite. */
    private const PROTECTED = [
        '.env',
        '.htaccess',
        'config/app.php',
        'content/uploads/',
        'storage/',
    ];

    /**
     * Auto-connect defaults. Every Basehim build knows its CloudHim home and
     * carries the network key that authorises self-registration there. Both
     * can still be overridden on the Updates page (or by changing the key in
     * CloudHim's settings and here for a private network).
     */
    private const DEFAULT_HUB = 'https://www.cloudhim.com';
    /**
     * Shared secret that lets a fresh site register with the update hub without
     * anyone typing anything.
     *
     * Sent only on first contact, or if a site loses its settings — once
     * registered, a site authenticates with its own site key instead.
     */
    private const DEFAULT_NETWORK_KEY = 'bh-3bb67e9aed0e51f8b4ef87a1e3f1abc5721d8a75';

    public function __construct(
        private Database $db,
        private SettingService $settings,
    ) {
    }

    // ------------------------------------------------------------------
    // Connection settings
    // ------------------------------------------------------------------

    public function config(): array
    {
        // The hub is baked in — connection is fully automatic, so stored URLs
        // from the old manual-connection era are deliberately ignored.
        return [
            'url' => self::DEFAULT_HUB,
            'key' => (string) $this->settings->get('updates', 'cloudhim_key', ''),
            'site_name' => (string) $this->settings->get('updates', 'site_name', ''),
        ];
    }

    /**
     * Automatic connection: if this site has no key yet, self-register with
     * CloudHim (network-key authorised) and store the returned site key. Safe
     * to call repeatedly; returns true when the site is connected.
     */
    public function ensureConnected(): bool
    {
        if ($this->isConfigured()) return true;
        $c = $this->config();
        $payload = [
            'network_key' => self::DEFAULT_NETWORK_KEY,
            'name'        => $c['site_name'] !== '' ? $c['site_name'] : $this->siteHost(),
            'url'         => $this->siteUrl(),
            'version'     => BASEHIM_VERSION,
            'php'         => PHP_VERSION,
        ];
        $res = $this->httpPost($c['url'] . '/api/v1/cloudhim/register', $payload, 12);
        if ($res['error'] !== null) {
            $this->rememberConnectError('Cannot reach ' . $c['url'] . ' — ' . $res['error']);
            return false;
        }
        $json = json_decode($res['body'], true);
        if (!is_array($json)) {
            // NOTE: this string is surfaced to site owners on Admin > Updates,
            // so it stays vendor-neutral — no CloudHim internals, and no asking
            // them to fix a server they don't operate.
            $this->rememberConnectError('The update service returned HTTP ' . $res['status']
                . ' with an unexpected response.');
            return false;
        }
        if (empty($json['ok']) || empty($json['site_key'])) {
            $this->rememberConnectError('Registration refused: ' . (string) ($json['error'] ?? ('HTTP ' . $res['status'])));
            return false;
        }

        $this->settings->set('updates', 'cloudhim_url', $c['url']);
        $this->settings->set('updates', 'cloudhim_key', (string) $json['site_key']);
        if ($c['site_name'] === '') $this->settings->set('updates', 'site_name', $this->siteHost());
        $this->settings->set('updates', 'last_connect_error', '');
        return true;
    }

    private function rememberConnectError(string $msg): void
    {
        try { $this->settings->set('updates', 'last_connect_error', mb_substr($msg, 0, 500)); } catch (\Throwable) {}
    }

    public function lastConnectError(): string
    {
        return (string) $this->settings->get('updates', 'last_connect_error', '');
    }

    /**
     * Quiet background sync: at most once per 12h, make sure we're connected
     * and refresh the available-updates cache (which feeds the sidebar badge).
     * Best-effort — short timeouts, never throws, never blocks the page long.
     */
    /**
     * Refresh the connection + available-updates cache, subject to a throttle.
     *
     * Called in the background from the dashboard (see UpdateController::sync),
     * so it never adds latency to a page render. Because it's off the render
     * path it can run hourly rather than the old 12-hour window, while staying
     * gentle on the update service.
     *
     * @param int $successTtl Seconds to wait after a successful check.
     * @param int $failTtl    Seconds to back off after a failure.
     * @return bool True if a fresh check actually ran and succeeded.
     */
    public function autoSync(int $successTtl = 3600, int $failTtl = 900): bool
    {
        try {
            $last = (string) $this->settings->get('updates', 'last_auto_sync', '');
            if ($last !== '' && (time() - (int) strtotime($last)) < $successTtl) return false;
            $lastFail = (string) $this->settings->get('updates', 'last_auto_fail', '');
            if ($lastFail !== '' && (time() - (int) strtotime($lastFail)) < $failTtl) return false;

            $ok = $this->ensureConnected();
            if ($ok) {
                $ok = (bool) ($this->check()['ok'] ?? false);
            }
            // Success → wait $successTtl. Failure → back off $failTtl, so a
            // recovered service is picked up quickly without hammering it.
            if ($ok) {
                $this->settings->set('updates', 'last_auto_sync', date('Y-m-d H:i:s'));
                $this->settings->set('updates', 'last_auto_fail', '');
            } else {
                $this->settings->set('updates', 'last_auto_fail', date('Y-m-d H:i:s'));
            }
            return $ok;
        } catch (\Throwable) {
            // Silence: a background refresh must never surface as an error.
            return false;
        }
    }


    public function isConfigured(): bool
    {
        $c = $this->config();
        return $c['url'] !== '' && $c['key'] !== '';
    }

    // ------------------------------------------------------------------
    // Check (also the heartbeat)
    // ------------------------------------------------------------------

    /** @return array{ok: bool, updates?: array, error?: string} */
    public function check(): array
    {
        // A fresh check means the catalogue may have been fixed — unpark anything
        // we previously refused to retry.
        $this->settings->set('updates', 'blocked_version', '');
        $res = $this->doCheck();
        if (!$res['ok'] && $this->isInvalidKeyError($res)) {
            // The stored site key is stale — e.g. left over from an old manual
            // connection, or its site row was deleted in CloudHim. Self-heal:
            // drop the key, register fresh (automatic), retry once.
            $this->settings->set('updates', 'cloudhim_key', '');
            if ($this->ensureConnected()) {
                $res = $this->doCheck();
            } else {
                $res['error'] = 'The stored site key was invalid and automatic re-registration failed: '
                    . ($this->lastConnectError() ?: 'unknown error');
            }
        }
        return $res;
    }

    private function isInvalidKeyError(array $res): bool
    {
        return stripos((string) ($res['error'] ?? ''), 'invalid site key') !== false;
    }

    /** @return array{ok: bool, updates?: array, error?: string} */
    private function doCheck(): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'error' => 'Not connected to the Basehim update service yet.'];
        }
        $c = $this->config();
        $query = http_build_query([
            'key'     => $c['key'],
            'version' => BASEHIM_VERSION,
            'php'     => PHP_VERSION,
            'url'     => $this->siteUrl(),
            'name'    => $c['site_name'] !== '' ? $c['site_name'] : $this->siteHost(),
        ]);
        $res = $this->httpGet($c['url'] . '/api/v1/cloudhim/updates?' . $query);
        if ($res['error'] !== null) {
            return ['ok' => false, 'error' => 'Could not reach the Basehim update service: ' . $res['error']];
        }
        $json = json_decode($res['body'], true);
        if (!is_array($json) || empty($json['ok'])) {
            return ['ok' => false, 'error' => (string) ($json['error'] ?? ('The Basehim update service returned HTTP ' . $res['status']))];
        }

        $updates = array_values(array_filter((array) ($json['updates'] ?? []), function ($u) {
            return isset($u['version']) && version_compare((string) $u['version'], BASEHIM_VERSION, '>');
        }));
        usort($updates, fn($a, $b) => version_compare((string) $b['version'], (string) $a['version']));

        $this->settings->set('updates', 'available', json_encode($updates, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->settings->set('updates', 'available_count', count($updates));
        $this->settings->set('updates', 'last_check', date('Y-m-d H:i:s'));

        return ['ok' => true, 'updates' => $updates];
    }

    /** Cached list from the last check (used by the page render). */
    public function cachedUpdates(): array
    {
        $raw = (string) $this->settings->get('updates', 'available', '[]');
        $list = json_decode($raw, true);
        if (!is_array($list)) return [];
        // Drop anything we've since caught up with (e.g. right after an update).
        return array_values(array_filter($list, fn($u) =>
            isset($u['version']) && version_compare((string) $u['version'], BASEHIM_VERSION, '>')));
    }

    /** Badge number for the sidebar — cached only, never a remote call. */
    public function badgeCount(): int
    {
        return count($this->cachedUpdates());
    }

    public function lastCheck(): string
    {
        return (string) $this->settings->get('updates', 'last_check', '');
    }

    // ------------------------------------------------------------------
    // Apply
    // ------------------------------------------------------------------

    /** @return array{ok: bool, version?: string, migrations?: array, error?: string} */
    /**
     * Every pending update, oldest first.
     *
     * Order matters: a patch only contains the files that changed in ITS
     * version, so applying 1.3 without 1.2 leaves the install inconsistent.
     * The installer always walks this list from the bottom.
     *
     * @return array<int,array>
     */
    public function pendingInOrder(): array
    {
        $installed = $this->installedVersionFromFile() ?: (defined('BASEHIM_VERSION') ? BASEHIM_VERSION : '0');
        // A version that failed to advance the install is parked until the next
        // check — otherwise the installer would immediately offer it again.
        $blocked = (string) $this->settings->get('updates', 'blocked_version', '');
        $list = array_values(array_filter(
            $this->cachedUpdates(),
            fn($u) => version_compare((string) ($u['version'] ?? '0'), $installed, '>')
                   && ($blocked === '' || (string) ($u['version'] ?? '') !== $blocked)
        ));
        usort($list, fn($a, $b) => version_compare((string) $a['version'], (string) $b['version']));
        return $list;
    }

    /**
     * The next update to install, or null when up to date.
     * Callers apply one step at a time (see UpdateController::installStep) so a
     * long chain can't hit a PHP timeout, and the browser can show progress.
     */
    public function nextPending(): ?array
    {
        $p = $this->pendingInOrder();
        return $p[0] ?? null;
    }

    /**
     * Snapshot the files a zip is about to overwrite, so a failed extract can
     * be undone. Only files present in the archive are copied, which keeps a
     * patch backup tiny.
     *
     * @return string|null Backup directory, or null if nothing to back up.
     */
    private function backupFor(string $zipPath): ?string
    {
        if (!class_exists(\ZipArchive::class)) return null;
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) return null;

        $dir = BASEHIM_ROOT . '/storage/updates/backup-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3));
        if (!@mkdir($dir, 0755, true)) { $zip->close(); return null; }

        // Mirror extractOver()'s wrapper-folder detection so paths line up.
        $prefix = $this->wrapperPrefix($zip);
        $saved = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (str_ends_with($name, '/')) continue;
            $rel = $prefix !== '' && str_starts_with($name, $prefix) ? substr($name, strlen($prefix)) : $name;
            if ($rel === '' || str_contains($rel, '..')) continue;
            $live = BASEHIM_ROOT . '/' . $rel;
            if (!is_file($live)) continue;                 // new file — nothing to restore
            $dest = $dir . '/' . $rel;
            $destDir = dirname($dest);
            if (!is_dir($destDir)) @mkdir($destDir, 0755, true);
            if (@copy($live, $dest)) $saved++;
        }
        $zip->close();
        if ($saved === 0) { $this->rrmdirSafe($dir); return null; }
        return $dir;
    }

    /** Put a backup back over the install root. */
    private function restoreBackup(string $dir): bool
    {
        if (!is_dir($dir)) return false;
        $ok = true;
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            /** @var \SplFileInfo $file */
            if (!$file->isFile()) continue;
            $rel = ltrim(str_replace($dir, '', $file->getPathname()), '/\\');
            $dest = BASEHIM_ROOT . '/' . $rel;
            $destDir = dirname($dest);
            if (!is_dir($destDir)) @mkdir($destDir, 0755, true);
            if (!@copy($file->getPathname(), $dest)) $ok = false;
        }
        return $ok;
    }

    /** Detect a single top-level wrapper folder inside a release zip. */
    private function wrapperPrefix(\ZipArchive $zip): string
    {
        $tops = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $n = (string) $zip->getNameIndex($i);
            $slash = strpos($n, '/');
            $tops[$slash === false ? $n : substr($n, 0, $slash)] = true;
            if (count($tops) > 1) break;
        }
        if (count($tops) !== 1) return '';
        $only = (string) array_key_first($tops);
        // A single root FILE (e.g. index.php) means there is no wrapper folder.
        return $zip->locateName($only) !== false ? '' : $only . '/';
    }

    private function rrmdirSafe(string $dir): void
    {
        if (!is_dir($dir)) return;
        $real = realpath($dir);
        $base = realpath(BASEHIM_ROOT . '/storage/updates');
        // Never delete outside the updates workspace.
        if (!$real || !$base || !str_starts_with($real, $base)) return;
        foreach (scandir($real) ?: [] as $e) {
            if ($e === '.' || $e === '..') continue;
            $p = $real . '/' . $e;
            is_dir($p) ? $this->rrmdirSafe($p) : @unlink($p);
        }
        @rmdir($real);
    }

    /** Remove backups older than a day — they are only a safety net. */
    public function pruneBackups(int $maxAgeSeconds = 86400): void
    {
        $base = BASEHIM_ROOT . '/storage/updates';
        foreach (glob($base . '/backup-*') ?: [] as $dir) {
            if (!is_dir($dir)) continue;
            if (time() - (int) @filemtime($dir) > $maxAgeSeconds) $this->rrmdirSafe($dir);
        }
    }

    public function apply(string $version): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'error' => 'Not connected to the Basehim update service.'];
        }
        $available = $this->cachedUpdates();
        $target = null;
        foreach ($available as $u) {
            if ((string) $u['version'] === $version) { $target = $u; break; }
        }
        if (!$target) return ['ok' => false, 'error' => 'That version is not in the available-updates list. Check for updates first.'];

        // Guard against skipping. A patch only carries the files that changed in
        // its own version, so installing 1.3 while 1.2 is still pending would
        // leave the install half-updated. Always apply the oldest first.
        $next = $this->nextPending();
        if ($next !== null && version_compare((string) $next['version'], $version, '<')) {
            return ['ok' => false, 'error' => sprintf(
                'Install v%s first — updates must be applied in order (v%s is a %s).',
                $next['version'], $next['version'], !empty($next['is_patch']) ? 'patch' : 'release'
            )];
        }

        if (!class_exists(\ZipArchive::class)) {
            return ['ok' => false, 'error' => 'PHP zip extension is not available on this server.'];
        }

        // 1) Download to storage.
        $c = $this->config();
        $dir = BASEHIM_ROOT . '/storage/updates';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        // Local scratch filename only — nothing reads it back by name.
        $zipPath = $dir . '/basehim-' . preg_replace('/[^0-9a-zA-Z._-]/', '', $version) . '.zip';
        $url = $c['url'] . '/api/v1/cloudhim/download?' . http_build_query(['key' => $c['key'], 'version' => $version]);
        $res = $this->httpGet($url, 300);
        if ($res['status'] === 403) {
            // Stale key mid-install — re-register and retry the download once.
            $this->settings->set('updates', 'cloudhim_key', '');
            if ($this->ensureConnected()) {
                $c = $this->config();
                $url = $c['url'] . '/api/v1/cloudhim/download?' . http_build_query(['key' => $c['key'], 'version' => $version]);
                $res = $this->httpGet($url, 300);
            }
        }
        if ($res['error'] !== null || $res['status'] >= 400 || $res['body'] === '') {
            return ['ok' => false, 'error' => 'Download failed: ' . ($res['error'] ?? ('HTTP ' . $res['status']))];
        }
        file_put_contents($zipPath, $res['body']);

        // 2) Verify checksum when the release carries one.
        $expected = strtolower((string) ($target['sha256'] ?? ''));
        if ($expected !== '' && !hash_equals($expected, hash_file('sha256', $zipPath))) {
            @unlink($zipPath);
            return ['ok' => false, 'error' => 'Checksum mismatch — the downloaded file does not match the release. Aborted, nothing was changed.'];
        }

        // 3) Snapshot what we're about to overwrite, then extract. If the
        //    extract fails part-way the install would otherwise be left in a
        //    mixed state, so restore the snapshot before returning.
        $versionBefore = $this->installedVersionFromFile();
        $backup = $this->backupFor($zipPath);
        $result = $this->extractOver($zipPath);
        @unlink($zipPath);
        if ($result !== null) {
            $restored = false;
            if ($backup !== null) {
                $restored = $this->restoreBackup($backup);
                $this->rrmdirSafe($backup);
            }
            $this->clearCaches();
            return [
                'ok' => false,
                'error' => $result . ($backup === null
                    ? ' Nothing was changed.'
                    : ($restored ? ' The previous files were restored.' : ' WARNING: automatic rollback did not fully succeed — restore from your host backup.')),
                'rolled_back' => $restored,
            ];
        }

        // 3b) Prove the package actually advanced the version.
        //
        //     Everything downstream — "is this still pending?", and therefore the
        //     installer loop's exit condition — is derived from the version in
        //     index.php. If a package is built without its version bump (zip cut
        //     from the wrong tree, or the version typed at publish time doesn't
        //     match the file), the update stays pending forever and the installer
        //     re-downloads it in a loop. Fail loudly and roll back instead.
        $versionAfter = $this->installedVersionFromFile();
        if ($versionAfter === '' || version_compare($versionAfter, $versionBefore, '<=')) {
            $restored = false;
            if ($backup !== null) {
                $restored = $this->restoreBackup($backup);
                $this->rrmdirSafe($backup);
            }
            $this->clearCaches();
            // Park it: offering it again would fail identically.
            $this->settings->set('updates', 'blocked_version', $version);
            return [
                'ok' => false,
                'rolled_back' => $restored,
                'error' => sprintf(
                    'The package for v%s did not update the installed version (still v%s). '
                    . 'It was most likely built without the version bump in index.php, or the version '
                    . 'entered when publishing does not match the file. %s',
                    $version,
                    $versionBefore !== '' ? $versionBefore : 'unknown',
                    $restored ? 'The previous files were restored.' : 'Nothing further was applied.'
                ),
            ];
        }

        // 4) Migrations + caches. A migration failure is reported but not rolled
        //    back automatically: the new files are already live and re-running
        //    migrations is safer than reverting code underneath a changed schema.
        $mig = $this->applyPendingMigrations();
        $this->clearCaches();
        if ($backup !== null) $this->rrmdirSafe($backup);   // success — drop the snapshot
        $this->pruneBackups();

        // 5) The available list is now stale; recount against the NEW version
        //    (constant still holds the old value this request, so read the file).
        $newVersion = $this->installedVersionFromFile() ?: $version;
        $remaining = array_values(array_filter($available, fn($u) =>
            version_compare((string) $u['version'], $newVersion, '>')));
        $this->settings->set('updates', 'available', json_encode($remaining, JSON_UNESCAPED_SLASHES));
        $this->settings->set('updates', 'available_count', count($remaining));

        return [
            'ok'         => true,
            'version'    => $newVersion,
            'is_patch'   => !empty($target['is_patch']),
            'migrations' => $mig['applied'] ?? [],
            'remaining'  => count($remaining),
        ];
    }

    /**
     * Extract a release zip over the install root.
     * Handles both bare zips (index.php at root) and single-wrapper-folder
     * zips ("basehim/index.php"). Returns an error string or null on success.
     */
    private function extractOver(string $zipPath): ?string
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) return 'Could not open the update package.';

        // Detect a single top-level wrapper folder.
        $prefix = '';
        if ($zip->locateName('index.php') === false) {
            $first = (string) $zip->getNameIndex(0);
            $top = explode('/', trim($first, '/'))[0] ?? '';
            if ($top !== '' && $zip->locateName($top . '/index.php') !== false) {
                $prefix = $top . '/';
            } else {
                $zip->close();
                return 'The package does not look like a Basehim release (no index.php found).';
            }
        }

        $root = rtrim(BASEHIM_ROOT, '/');
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            $rel = $prefix !== '' && str_starts_with($name, $prefix) ? substr($name, strlen($prefix)) : $name;
            $rel = ltrim(str_replace('\\', '/', $rel), '/');
            if ($rel === '' || str_ends_with($name, '/')) continue;

            // Zip-slip protection: no traversal, no absolute paths.
            if (str_contains($rel, '..') || preg_match('#^([a-zA-Z]:|/)#', $rel)) continue;

            // Protected paths are never overwritten.
            foreach (self::PROTECTED as $p) {
                if ($rel === $p || (str_ends_with($p, '/') && str_starts_with($rel, $p))) {
                    continue 2;
                }
            }

            $destPath = $root . '/' . $rel;
            $destDir = dirname($destPath);
            if (!is_dir($destDir) && !@mkdir($destDir, 0755, true)) {
                $zip->close();
                return "Could not create directory: " . substr($rel, 0, 200);
            }
            $stream = $zip->getStream($name);
            if ($stream === false) continue;
            $out = @fopen($destPath, 'wb');
            if ($out === false) {
                fclose($stream);
                $zip->close();
                return "Could not write file (permissions?): " . substr($rel, 0, 200);
            }
            stream_copy_to_stream($stream, $out);
            fclose($stream);
            fclose($out);
        }
        $zip->close();
        return null;
    }

    /** Same behaviour as the System page's migration runner. */
    public function applyPendingMigrations(): array
    {
        $applied = [];
        try {
            $pdo = $this->db->connection();
            // The runner talks to PDO directly, so {table} tokens have to be
            // expanded here — Database::query() is not in the path.
            $px  = fn(string $sql): string => $this->db->expand($sql);
            $pdo->exec($px(
                'CREATE TABLE IF NOT EXISTS {migrations} (
                    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `migration` VARCHAR(255) NOT NULL,
                    `applied_at` DATETIME NOT NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            ));
            $ran = $pdo->query($px('SELECT migration FROM {migrations}'))->fetchAll(\PDO::FETCH_COLUMN);
            $files = glob(BASEHIM_ROOT . '/database/migrations/*.sql') ?: [];
            sort($files);
            foreach ($files as $file) {
                $key = preg_replace('/\.sql$/', '', basename($file));
                if (in_array($key, $ran, true)) continue;
                $sql = file_get_contents($file);
                if ($sql === false || trim($sql) === '') continue;
                $pdo->exec($px($sql));
                $stmt = $pdo->prepare($px('INSERT INTO {migrations} (migration, applied_at) VALUES (?, ?)'));
                $stmt->execute([$key, date('Y-m-d H:i:s')]);
                $applied[] = $key;
            }
            return ['applied' => $applied, 'error' => null];
        } catch (\Throwable $e) {
            return ['applied' => $applied, 'error' => $e->getMessage()];
        }
    }

    private function clearCaches(): void
    {
        try {
            $cacheDir = BASEHIM_ROOT . '/storage/cache';
            foreach (glob($cacheDir . '/*') ?: [] as $f) {
                if (is_file($f)) @unlink($f);
            }
        } catch (\Throwable) {}
        if (function_exists('opcache_reset')) @opcache_reset();
    }

    /** Read BASEHIM_VERSION from the freshly-written index.php. */
    /**
     * Read the version straight from index.php.
     *
     * Public because callers outside the service need the version AFTER an
     * update has been written — BASEHIM_VERSION still holds the old value for
     * the rest of the request, so the constant can't be trusted post-install.
     */
    public function installedVersionFromFile(): string
    {
        $src = @file_get_contents(BASEHIM_ROOT . '/index.php') ?: '';
        return preg_match("/define\('BASEHIM_VERSION',\s*'([^']+)'\)/", $src, $m) ? $m[1] : '';
    }

    // ------------------------------------------------------------------
    // HTTP + site identity
    // ------------------------------------------------------------------

    private function httpGet(string $url, int $timeout = 30): array
    {
        return $this->http('GET', $url, null, $timeout);
    }

    private function httpPost(string $url, array $payload, int $timeout = 30): array
    {
        return $this->http('POST', $url, $payload, $timeout);
    }

    private function http(string $method, string $url, ?array $payload, int $timeout): array
    {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
            CURLOPT_USERAGENT      => 'Basehim-Updater/' . BASEHIM_VERSION,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ];
        if ($method === 'POST') {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = json_encode($payload ?? [], JSON_UNESCAPED_SLASHES);
            $opts[CURLOPT_HTTPHEADER] = ['Content-Type: application/json'];
            // Keep POST as POST across 301/302 redirects (e.g. www rewrites) —
            // curl's default downgrades to GET, which breaks registration.
            $opts[CURLOPT_POSTREDIR] = 7;
        }
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $err = curl_errno($ch) !== 0 ? curl_error($ch) : null;
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return ['body' => (string) $body, 'status' => $status, 'error' => $err];
    }

    private function siteUrl(): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $base = defined('BASEHIM_BASE') ? (string) BASEHIM_BASE : '';
        return $scheme . '://' . $host . $base;
    }

    private function siteHost(): string
    {
        return (string) ($_SERVER['HTTP_HOST'] ?? 'this site');
    }
}
