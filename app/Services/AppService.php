<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HookRegistry;
use App\Core\Application;
use App\Core\App as BaseApp;
use App\Core\Logger;

/**
 * AppService
 *
 * Manages the app lifecycle: scan, sync, install (from ZIP), activate,
 * deactivate, uninstall, delete. Wires app namespaces into the autoloader
 * and instantiates each active app's entry class.
 *
 *   - Apps live in content/apps/ and nowhere else.
 *   - The manifest is named app.json.
 *   - The registry table is `apps`.
 *   - An app's entry class extends App\Core\App and is named by the manifest's
 *     "entry" key.
 */
class AppService
{
    /** Cached app instances, keyed by slug. */
    private array $instances = [];

    /** Resolved directory per slug, filled by scan(). slug => absolute path. */
    private array $dirCache = [];

    /** Why the last activate() refused, for the controller to surface. */
    private array $lastRequirementProblems = [];

    /** Upgrades already announced this request, keyed "slug:from:to". */
    private array $upgradesFired = [];

    /**
     * @param string $appPath content/apps — where apps are installed.
     */
    public function __construct(
        private Database $db,
        private HookRegistry $hooks,
        private string $appPath,
    ) {
    }

    /** Primary directory — where new apps are installed. */
    public function path(): string
    {
        return $this->appPath;
    }

    /** Every directory an app may live in, primary first. */
    private function searchPaths(): array
    {
        return [$this->appPath];
    }

    /**
     * Absolute directory for an installed app, wherever it lives.
     * Falls back to the primary app path for not-yet-created directories.
     */
    public function dirFor(string $slug): string
    {
        if (isset($this->dirCache[$slug])) return $this->dirCache[$slug];
        foreach ($this->searchPaths() as $base) {
            if ($this->manifestFileIn($base . '/' . $slug) !== null) {
                return $this->dirCache[$slug] = $base . '/' . $slug;
            }
        }
        return $this->appPath . '/' . $slug;
    }

    /** Locate the app.json manifest inside a directory. */
    private function manifestFileIn(string $dir): ?string
    {
        $file = $dir . '/app.json';
        return is_file($file) ? $file : null;
    }

    /**
     * Scan the app directory for manifests.
     *
     * @return array<string, array> slug => manifest (+ _slug, _path, _container, _manifest_file)
     */
    public function scan(): array
    {
        $available = [];
        foreach ($this->searchPaths() as $base) {
            if (!is_dir($base)) continue;
            $container = basename($base);

            foreach (scandir($base) ?: [] as $dir) {
                if ($dir === '.' || $dir === '..') continue;
                // Skip hidden/working directories (.staging, .install_*, backups),
                // which contain a manifest but are not installed apps.
                if ($dir[0] === '.') continue;
                if (isset($available[$dir])) continue;   // primary dir wins

                $manifestFile = $this->manifestFileIn($base . '/' . $dir);
                if ($manifestFile === null) continue;

                $data = json_decode((string) file_get_contents($manifestFile), true);
                if (!is_array($data)) continue;

                $data['_slug']          = $dir;
                $data['_path']          = $base . '/' . $dir;
                $data['_container']     = $container;
                $data['_manifest_file'] = $manifestFile;

                $available[$dir] = $data;
                $this->dirCache[$dir] = $base . '/' . $dir;
            }
        }
        return $available;
    }

    public function installed(): array
    {
        return $this->db->select('SELECT * FROM {apps} ORDER BY name');
    }

    public function active(): array
    {
        return $this->db->select("SELECT * FROM {apps} WHERE status = 'active' ORDER BY name");
    }

    public function find(string $slug): ?array
    {
        return $this->db->selectOne('SELECT * FROM {apps} WHERE slug = :slug', ['slug' => $slug]);
    }

    /**
     * Reconcile the app directories with the `apps` table.
     * Inserts a row for every manifest found on disk. Fires onInstall()
     * for any newly-detected app.
     */
    /**
     * Reconcile manifests on disk with the apps table.
     *
     * Returns a summary of what changed. This was `void` until 1.42.3, which
     * left every caller unable to distinguish a successful no-op from a
     * failure — dev_app_lifecycle reported a bare `null` for a sync that had
     * in fact worked. Adding a return value is backward compatible: the only
     * core caller discards it.
     *
     * @return array{scanned:int,installed:string[],upgraded:array<int,array{slug:string,from:string,to:string}>,unchanged:string[]}
     */
    public function sync(): array
    {
        $summary = ['scanned' => 0, 'installed' => [], 'upgraded' => [], 'unchanged' => []];

        $available = $this->scan();
        $summary['scanned'] = count($available);

        foreach ($available as $slug => $manifest) {
            $row = $this->find($slug);
            if (!$row) {
                $this->db->insert('apps', [
                    'vendor'      => $manifest['vendor'] ?? 'community',
                    'slug'        => $slug,
                    'name'        => $manifest['name'] ?? $slug,
                    'description' => $manifest['description'] ?? null,
                    'version'     => $manifest['version'] ?? '0.0.1',
                    'author'      => $manifest['author'] ?? null,
                    'icon'        => $this->manifestIcon($manifest),
                    'permissions' => $this->manifestPermissions($manifest),
                    'status'      => 'inactive',
                ]);
                $this->fireLifecycle($slug, 'onInstall');
                $summary['installed'][] = $slug;
            } else {
                $oldVersion = (string) ($row['version'] ?? '');
                $newVersion = (string) ($manifest['version'] ?? $oldVersion);

                // Update manifest-derived fields in case the file changed.
                $this->db->update('apps', [
                    'name'        => $manifest['name'] ?? $row['name'],
                    'description' => $manifest['description'] ?? $row['description'],
                    'version'     => $newVersion,
                    'author'      => $manifest['author'] ?? $row['author'],
                    'vendor'      => $manifest['vendor'] ?? $row['vendor'],
                    'icon'        => $this->manifestIcon($manifest),
                    'permissions' => $this->manifestPermissions($manifest),
                ], ['id' => $row['id']]);

                // The app's files changed version underneath it. This is the
                // only moment core knows both numbers, so it is where the app
                // gets told — otherwise an author has no supported place to
                // migrate their own tables and ends up version-checking inside
                // boot() on every single request.
                if ($oldVersion !== '' && $newVersion !== '' && $oldVersion !== $newVersion) {
                    $this->fireUpgrade($slug, $oldVersion, $newVersion);
                    $summary['upgraded'][] = ['slug' => $slug, 'from' => $oldVersion, 'to' => $newVersion];
                } else {
                    $summary['unchanged'][] = $slug;
                }
            }
        }

        return $summary;
    }

    /**
     * Activate an app.
     *
     * An app that declares permissions must have been consented to first —
     * activation is the moment its code starts running on every request, so it
     * is the right gate. Apps declaring nothing activate exactly as before;
     * that is what keeps every pre-1.36 app working.
     *
     * Callers should use needsConsent() to route the operator to the consent
     * screen rather than treating the false return as an error.
     */
    public function activate(string $slug): bool
    {
        $row = $this->find($slug);
        if (!$row) return false;

        // Requirements are checked here as well as at install: a dependency can
        // be deactivated, and core can be patched, long after an app was
        // installed. Activation is when the app's code starts running, so it is
        // the last moment this can be caught before a fatal.
        $problems = $this->checkRequirementsFor($slug);
        if ($problems !== []) {
            $this->lastRequirementProblems = $problems;
            $this->logSafe("App '{$slug}' cannot activate: " . implode(' ', $problems));
            return false;
        }

        if ($this->needsConsent($slug)) {
            $this->logSafe("App '{$slug}' declares permissions and has not been approved yet.");
            return false;
        }

        $this->db->execute(
            "UPDATE {apps} SET status = 'active', activated_at = NOW() WHERE slug = :slug",
            ['slug' => $slug]
        );
        $this->fireLifecycle($slug, 'onActivate');

        return true;
    }

    public function deactivate(string $slug): bool
    {
        $row = $this->find($slug);
        if (!$row) return false;

        // Fire onDeactivate while the app is still bootable.
        $this->fireLifecycle($slug, 'onDeactivate');
        $this->db->execute(
            "UPDATE {apps} SET status = 'inactive', activated_at = NULL WHERE slug = :slug",
            ['slug' => $slug]
        );
        return true;
    }

    /**
     * Uninstall = remove DB row & app-owned settings. Files are kept
     * unless $deleteFiles is true.
     */
    public function uninstall(string $slug, bool $deleteFiles = false): bool
    {
        $row = $this->find($slug);
        if (!$row) {
            // No DB row, but we may still need to remove files.
            if ($deleteFiles) $this->deleteFiles($slug);
            return false;
        }

        if ($row['status'] === 'active') {
            $this->deactivate($slug);
        }

        // Fire onUninstall (give the app a chance to clean up).
        $this->fireLifecycle($slug, 'onUninstall');

        // Drop any settings owned by the app.
        // Through the service so the cached `settings.all` snapshot is dropped.
        // A raw DELETE here left an uninstalled app's settings readable for up
        // to an hour, which a reinstall of the same slug would then inherit.
        try {
            \App\Core\Application::getInstance()
                ->make(\App\Services\SettingService::class)
                ->deleteGroups(['app:' . $slug]);
        } catch (\Throwable) {
            // Fall back to the direct delete, then flush the cache by hand, so
            // an uninstall still cleans up if the service cannot be resolved.
            $this->db->execute(
                'DELETE FROM {settings} WHERE setting_group = :g',
                ['g' => 'app:' . $slug]
            );
            try {
                \App\Core\Application::getInstance()
                    ->make(\App\Core\Cache::class)->delete('settings.all');
            } catch (\Throwable) {}
        }

        // Drop permission grants and per-app logs. Deactivation deliberately
        // keeps both — an operator toggling an app off and on again should not
        // have to re-approve it, nor lose the logs explaining why they toggled
        // it off. Uninstall is the point at which the app is really going.
        try {
            \App\Core\Application::getInstance()
                ->make(\App\Services\PermissionBroker::class)->revokeAll($slug);
        } catch (\Throwable) {}
        try {
            \App\Core\Application::getInstance()
                ->make(\App\Services\AppLogger::class)->purge($slug);
        } catch (\Throwable) {}

        // Remove DB row.
        $this->db->delete('apps', ['slug' => $slug]);

        if ($deleteFiles) {
            $this->deleteFiles($slug);
        }

        unset($this->instances[$slug]);
        return true;
    }

    /**
     * Permanently delete an app: uninstall + remove files from disk.
     */
    public function delete(string $slug): bool
    {
        return $this->uninstall($slug, deleteFiles: true);
    }

    /**
     * Install an app from an uploaded ZIP file.
     *
     * @param string $zipPath  Path to the uploaded .zip on disk.
     * @return array{slug:string, manifest:array}
     * @throws \RuntimeException on validation failure.
     */
    public function installFromZip(string $zipPath): array
    {
        $staged = $this->stageZip($zipPath);
        $slug = $staged['slug'];
        $targetDir = $this->appPath . '/' . $slug;

        if (is_dir($targetDir)) {
            $this->rrmdir($staged['tempDir']);
            throw new \RuntimeException("An app with slug '{$slug}' is already installed. Uninstall it first to reinstall, or use the upgrade flow.");
        }

        if (!@rename($staged['tempDir'], $targetDir)) {
            $this->rrmdir($staged['tempDir']);
            throw new \RuntimeException('Could not move the extracted app into place.');
        }

        // Insert DB row & fire onInstall.
        $this->sync();

        // Scan at install time so the operator sees any flags before deciding
        // whether to activate. Never blocks the install.
        $this->scanApp($slug);

        return ['slug' => $slug, 'manifest' => $staged['manifest']];
    }

    /**
     * Read an app ZIP's manifest WITHOUT extracting anything. Lets a caller
     * check the slug/version up front (e.g. to decide install vs upgrade).
     */
    public function peekZipManifest(string $zipPath): array
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('PHP ZipArchive extension is not available on this server.');
        }
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new \RuntimeException('Could not open uploaded ZIP.');
        }
        $raw = false;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i) ?: '';
            if (preg_match('#^(?:[^/]+/)?app\.json$#', $name)) {
                $raw = $zip->getFromIndex($i);
                break;
            }
        }
        $zip->close();
        if ($raw === false) {
            throw new \RuntimeException('app.json not found in archive root or first folder.');
        }
        $manifest = json_decode($raw, true);
        if (!is_array($manifest)) {
            throw new \RuntimeException('The app manifest is not valid JSON.');
        }
        return $manifest;
    }

    /**
     * Extract an uploaded app ZIP to a temp directory WITHOUT committing it.
     * Returns ['slug', 'manifest', 'tempDir', 'topFolder']. The caller is
     * responsible for moving tempDir into place or cleaning it up. This is the
     * shared core behind both fresh installs and upgrades.
     */
    public function stageZip(string $zipPath): array
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('PHP ZipArchive extension is not available on this server.');
        }
        if (!is_file($zipPath)) {
            throw new \RuntimeException('App upload not found on disk.');
        }

        $zip = new \ZipArchive();
        $opened = $zip->open($zipPath);
        if ($opened !== true) {
            throw new \RuntimeException('Could not open uploaded ZIP (error code ' . (int)$opened . ').');
        }

        // -- 1. locate app.json in the archive -----------------------------
        $manifestIndex = false;
        $manifestPath = null;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i) ?: '';
            if (str_contains($name, '..') || str_starts_with($name, '/') || str_contains($name, "\0")) {
                $zip->close();
                throw new \RuntimeException('Archive contains an unsafe path; refusing to extract.');
            }
            if (preg_match('#^(?:[^/]+/)?app\.json$#', $name)) {
                $manifestIndex = $i;
                $manifestPath = $name;
                break;
            }
        }

        if ($manifestIndex === false) {
            $zip->close();
            throw new \RuntimeException('app.json not found in archive root or first folder.');
        }

        // -- 2. read & validate manifest -----------------------------------
        $raw = $zip->getFromIndex($manifestIndex);
        if ($raw === false) {
            $zip->close();
            throw new \RuntimeException('Could not read the app manifest from archive.');
        }
        $manifest = json_decode($raw, true);
        if (!is_array($manifest)) {
            $zip->close();
            throw new \RuntimeException('The app manifest is not valid JSON.');
        }
        $this->validateManifest($manifest);

        $slug = $this->sanitizeSlug($manifest['slug']);

        // -- 3. extract to temp --------------------------------------------
        if (!is_dir($this->appPath)) {
            if (!@mkdir($this->appPath, 0755, true) && !is_dir($this->appPath)) {
                $zip->close();
                throw new \RuntimeException('Cannot create apps directory: ' . $this->appPath);
            }
        }

        $tempDir = $this->appPath . '/.install_' . bin2hex(random_bytes(6));
        if (!@mkdir($tempDir, 0755, true)) {
            $zip->close();
            throw new \RuntimeException('Could not create temp directory for extraction.');
        }

        $topFolder = '';
        if (str_contains($manifestPath, '/')) {
            $topFolder = strstr($manifestPath, '/', true);
        }

        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = $zip->getNameIndex($i) ?: '';
                if ($entry === '' || str_ends_with($entry, '/')) continue;
                if (str_contains($entry, '..') || str_starts_with($entry, '/')) {
                    throw new \RuntimeException("Unsafe path in archive: {$entry}");
                }

                $relative = $entry;
                if ($topFolder !== '' && str_starts_with($entry, $topFolder . '/')) {
                    $relative = substr($entry, strlen($topFolder) + 1);
                }
                if ($relative === '') continue;

                $dest = $tempDir . '/' . $relative;
                $destDir = dirname($dest);
                if (!is_dir($destDir) && !@mkdir($destDir, 0755, true) && !is_dir($destDir)) {
                    throw new \RuntimeException("Could not create directory: {$destDir}");
                }

                $stream = $zip->getStream($entry);
                if (!$stream) {
                    throw new \RuntimeException("Could not read entry: {$entry}");
                }
                $out = @fopen($dest, 'wb');
                if (!$out) {
                    fclose($stream);
                    throw new \RuntimeException("Could not write file: {$dest}");
                }
                stream_copy_to_stream($stream, $out);
                fclose($stream);
                fclose($out);
            }
            $zip->close();

            if ($this->manifestFileIn($tempDir) === null) {
                throw new \RuntimeException('Extracted archive is missing app.json at its root.');
            }
        } catch (\Throwable $e) {
            $this->rrmdir($tempDir);
            if (isset($zip) && $zip instanceof \ZipArchive) {
                @$zip->close();
            }
            throw $e;
        }

        return ['slug' => $slug, 'manifest' => $manifest, 'tempDir' => $tempDir, 'topFolder' => $topFolder];
    }

    /**
     * Stage an uploaded ZIP for a potential UPGRADE of an already-installed
     * app. Extracts to a persistent staging dir (survives the redirect to
     * the confirm page) and returns a comparison of installed vs incoming.
     * Does NOT modify the live app.
     */
    public function stageForUpgrade(string $zipPath): array
    {
        $this->sweepStaleStaging();
        $staged = $this->stageZip($zipPath);
        $slug = $staged['slug'];
        $row = $this->find($slug);
        if (!$row) {
            $this->rrmdir($staged['tempDir']);
            throw new \RuntimeException("App '{$slug}' is not installed; nothing to upgrade.");
        }

        $token = bin2hex(random_bytes(8));
        $stageRoot = $this->stagingPath();
        if (!is_dir($stageRoot) && !@mkdir($stageRoot, 0755, true) && !is_dir($stageRoot)) {
            $this->rrmdir($staged['tempDir']);
            throw new \RuntimeException('Could not create staging directory.');
        }
        $stageDir = $stageRoot . '/' . $slug . '__' . $token;
        if (!@rename($staged['tempDir'], $stageDir)) {
            $this->rrmdir($staged['tempDir']);
            throw new \RuntimeException('Could not stage the upgrade.');
        }

        $installedVer = (string) ($row['version'] ?? '0.0.0');
        $incomingVer  = (string) ($staged['manifest']['version'] ?? '0.0.0');

        return [
            'slug'       => $slug,
            'token'      => $token,
            'installed'  => [
                'name'        => $row['name'] ?? $slug,
                'version'     => $installedVer,
                'author'      => $row['author'] ?? null,
                'description' => $row['description'] ?? null,
                'status'      => $row['status'] ?? 'inactive',
            ],
            'incoming'   => [
                'name'        => $staged['manifest']['name'] ?? $slug,
                'version'     => $incomingVer,
                'author'      => $staged['manifest']['author'] ?? null,
                'description' => $staged['manifest']['description'] ?? null,
            ],
            'comparison' => $this->compareVersions($installedVer, $incomingVer),
        ];
    }

    /**
     * Apply a previously-staged upgrade. Backs up the current app dir, swaps
     * in the staged files, refreshes the DB row, and fires onUpgrade(from,to)
     * (and re-runs onActivate if it was active) so migrations run.
     */
    public function applyStagedUpgrade(string $slug, string $token): array
    {
        $slug  = $this->sanitizeSlug($slug);
        $token = preg_replace('/[^a-f0-9]/', '', $token) ?: '';
        if ($token === '') throw new \RuntimeException('Invalid upgrade token.');

        $stageDir = $this->stagingPath() . '/' . $slug . '__' . $token;
        if (!is_dir($stageDir) || $this->manifestFileIn($stageDir) === null) {
            throw new \RuntimeException('The staged upgrade was not found or has expired. Please upload again.');
        }

        $row = $this->find($slug);
        $fromVersion = (string) ($row['version'] ?? '0.0.0');
        $wasActive = ($row['status'] ?? '') === 'active';

        $incoming = json_decode((string) @file_get_contents((string) $this->manifestFileIn($stageDir)), true) ?: [];
        $toVersion = (string) ($incoming['version'] ?? $fromVersion);

        $targetDir = $this->appPath . '/' . $slug;
        $backupDir = $this->stagingPath() . '/' . $slug . '__backup_' . bin2hex(random_bytes(4));

        // 1. Back up the current install (atomic rename).
        if (is_dir($targetDir)) {
            if (!@rename($targetDir, $backupDir)) {
                $this->rrmdir($stageDir);
                throw new \RuntimeException('Could not back up the current app before upgrading.');
            }
        }

        // 2. Move the staged upgrade into place.
        if (!@rename($stageDir, $targetDir)) {
            if (is_dir($backupDir)) @rename($backupDir, $targetDir);  // roll back
            $this->rrmdir($stageDir);
            throw new \RuntimeException('Could not install the upgraded files; the previous version was restored.');
        }

        // 2b. The files on disk are now the new version, so anything cached
        //     from the old one is stale. Evict before sync(), which is what
        //     fires the lifecycle hooks.
        $this->forgetInstance($slug);

        // 3. Refresh DB row from the new manifest.
        try { $this->sync(); } catch (\Throwable $e) { $this->logSafe("upgrade sync failed for {$slug}: " . $e->getMessage()); }

        // 4. Fire onUpgrade(from,to); re-run onActivate if it was active so any
        //    activation-only schema is applied.
        // Routed through fireUpgrade() so the sync() above and this call cannot
        // both announce the same upgrade.
        $this->fireUpgrade($slug, $fromVersion, $toVersion);
        if ($wasActive) $this->fireLifecycle($slug, 'onActivate');

        // 5. Drop the backup now that we've succeeded.
        if (isset($backupDir) && is_dir($backupDir)) $this->rrmdir($backupDir);

        return ['slug' => $slug, 'from' => $fromVersion, 'to' => $toVersion, 'was_active' => $wasActive];
    }

    /** Discard a staged upgrade (user cancelled). */
    public function discardStagedUpgrade(string $slug, string $token): void
    {
        $slug  = $this->sanitizeSlug($slug);
        $token = preg_replace('/[^a-f0-9]/', '', $token) ?: '';
        if ($token === '') return;
        $stageDir = $this->stagingPath() . '/' . $slug . '__' . $token;
        if (is_dir($stageDir)) $this->rrmdir($stageDir);
    }

    private function stagingPath(): string
    {
        return $this->appPath . '/.staging';
    }

    /** Remove staged/backup dirs older than an hour (abandoned upgrades). */
    private function sweepStaleStaging(): void
    {
        $root = $this->stagingPath();
        if (!is_dir($root)) return;
        $cutoff = time() - 3600;
        foreach (scandir($root) as $d) {
            if ($d === '.' || $d === '..') continue;
            $path = $root . '/' . $d;
            if (is_dir($path) && @filemtime($path) < $cutoff) {
                $this->rrmdir($path);
            }
        }
    }

    /**
     * Compare two semver-ish versions from the incoming's perspective:
     * 'newer' means the upload is newer than what's installed. 'unknown' if
     * either can't be parsed.
     */
    private function compareVersions(string $installed, string $incoming): string
    {
        $norm = static function (string $v): ?array {
            $v = ltrim(trim($v), 'vV');
            $core = preg_replace('/[-+].*$/', '', $v);   // drop pre-release/build
            if (!preg_match('/^\d+(\.\d+)*$/', $core)) return null;
            return array_map('intval', explode('.', $core));
        };
        $a = $norm($installed);
        $b = $norm($incoming);
        if ($a === null || $b === null) return 'unknown';
        $len = max(count($a), count($b));
        for ($i = 0; $i < $len; $i++) {
            $ai = $a[$i] ?? 0;
            $bi = $b[$i] ?? 0;
            if ($bi > $ai) return 'newer';
            if ($bi < $ai) return 'older';
        }
        return 'same';
    }

    /** Like fireLifecycle but passes arguments (e.g. onUpgrade(from,to)). */
    private function fireLifecycleArgs(string $slug, string $method, array $args): void
    {
        try {
            $app = Application::getInstance();
            // Resolve fresh: callers reach here after the app's files may have
            // changed, and a stale instance would hide the new method.
            $instance = $this->instances[$slug] ?? $this->loadAndBoot($app, $slug, callBoot: false);
            if ($instance && method_exists($instance, $method)) {
                $instance->{$method}(...$args);
            }
        } catch (\Throwable $e) {
            $this->logSafe("App lifecycle '{$method}' failed for {$slug}: " . $e->getMessage());
        }
    }

    private function logSafe(string $msg): void
    {
        try { Application::getInstance()->make(Logger::class)->warning($msg); } catch (\Throwable) {}
    }

    /**
     * Bootstrap every active app: register its namespace and call boot().
     */
    public function bootActive(Application $app): void
    {
        try {
            $rows = $this->active();
        } catch (\Throwable $e) {
            // DB might not be ready (e.g., during install). Silently skip.
            return;
        }

        foreach ($rows as $row) {
            try {
                $this->loadAndBoot($app, $row['slug']);
            } catch (\Throwable $e) {
                try {
                    $app->make(Logger::class)->error(
                        'App failed to boot',
                        ['app' => $row['slug'], 'error' => $e->getMessage()]
                    );
                } catch (\Throwable) {}
            }
        }
    }

    /**
     * Resolve a single app instance without booting it as if active.
     * Useful for firing lifecycle methods (onActivate, etc.) on demand.
     */
    /**
     * Drop an app's cached instance so the next resolve re-reads the class.
     *
     * Needed whenever an app's FILES change under a running request. The
     * instance cache is populated at boot, so after upgradeApply() swaps the
     * directory the cached object is still the OLD version's class — and a
     * lifecycle method that only exists in the new version is invisible to
     * method_exists(). onUpgrade() was therefore skipped in silence for every
     * app that was active at the moment it was upgraded, which is most of them.
     *
     * Note this cannot un-declare the old PHP class; that is loaded for the
     * life of the process. What it does is force a fresh instantiation using
     * the new manifest's entry class, which is what the lifecycle call needs.
     */
    public function forgetInstance(string $slug): void
    {
        unset($this->instances[$slug]);
    }

    public function instance(Application $app, string $slug): ?object
    {
        if (isset($this->instances[$slug])) {
            return $this->instances[$slug];
        }
        return $this->loadAndBoot($app, $slug, callBoot: false);
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * Load an app's class file and instantiate it. If $callBoot is true,
     * also call boot().
     */
    private function loadAndBoot(Application $app, string $slug, bool $callBoot = true): ?object
    {
        if (isset($this->instances[$slug])) {
            $instance = $this->instances[$slug];
            if ($callBoot && method_exists($instance, 'boot')) {
                $this->callBoot($instance, $app);
            }
            return $instance;
        }

        $appDir = $this->dirFor($slug);
        $manifestFile = $this->manifestFileIn($appDir);
        if ($manifestFile === null) return null;

        $manifest = json_decode((string)file_get_contents($manifestFile), true);
        if (!is_array($manifest)) return null;

        $namespace = $manifest['namespace'] ?? null;
        $srcDir = $manifest['src'] ?? 'src';
        // Entry class: an explicit manifest value wins, otherwise "App".
        $entryCandidates = !empty($manifest['entry'])
            ? [(string) $manifest['entry']]
            : ['App'];

        if ($namespace) {
            $rel = ltrim(str_replace(BASEHIM_ROOT, '', $appDir . '/' . $srcDir), '/');
            \App\Core\Autoloader::addNamespace($namespace, $rel);
        }

        $prefix = $namespace ? rtrim($namespace, '\\') . '\\' : '';
        $className = null;
        foreach ($entryCandidates as $candidate) {
            if (class_exists($prefix . $candidate)) {
                $className = $prefix . $candidate;
                break;
            }
        }
        if ($className === null) {
            return null;
        }

        $instance = $this->instantiate($app, $className, $slug, $manifest, $appDir);
        $this->instances[$slug] = $instance;

        if ($callBoot && method_exists($instance, 'boot')) {
            $this->callBoot($instance, $app);
        }

        return $instance;
    }

    private function callBoot(object $instance, Application $app): void
    {
        try {
            $ref = new \ReflectionMethod($instance, 'boot');
            // Apps extending the base class take no args; a bare boot(Application)
            // implementation is also supported, so honour whichever it declares.
            if ($ref->getNumberOfParameters() === 0) {
                $instance->boot();
            } else {
                $instance->boot($app);
            }
        } catch (\Throwable $e) {
            try {
                $app->make(Logger::class)->error(
                    'App boot() threw',
                    ['class' => $instance::class, 'error' => $e->getMessage()]
                );
            } catch (\Throwable) {}
        }
    }

    private function instantiate(Application $app, string $className, string $slug, array $manifest, string $path): object
    {
        // App subclasses get the rich constructor signature.
        if (is_subclass_of($className, BaseApp::class)) {
            return new $className($app, $slug, $manifest, $path);
        }

        // Standalone entry classes: try (Application), fall back to no args.
        $ref = new \ReflectionClass($className);
        $ctor = $ref->getConstructor();
        if (!$ctor || $ctor->getNumberOfParameters() === 0) {
            return new $className();
        }
        return new $className($app);
    }

    /**
     * Fire a lifecycle method on an app without booting it.
     */
    private function fireLifecycle(string $slug, string $method): void
    {
        try {
            $app = Application::getInstance();
            $instance = $this->loadAndBoot($app, $slug, callBoot: false);
            if ($instance && method_exists($instance, $method)) {
                $instance->{$method}();
            }
        } catch (\Throwable $e) {
            try {
                Application::getInstance()->make(Logger::class)->warning(
                    "App lifecycle '{$method}' failed",
                    ['app' => $slug, 'error' => $e->getMessage()]
                );
            } catch (\Throwable) {}
        }
    }

    /** Normalised icon string from a manifest (empty when unset). */
    private function manifestIcon(array $manifest): ?string
    {
        $icon = trim((string) ($manifest['icon'] ?? ''));
        return $icon !== '' ? mb_substr($icon, 0, 190) : null;
    }

    /**
     * Declared permissions, stored as a JSON array on the app row so the admin
     * UI can show what an app intends to touch.
     *
     * Declaration only — enforcement arrives with the permission broker. An
     * app that declares nothing is recorded as an empty list, not NULL, so the
     * UI can distinguish "declares nothing" from "predates the field".
     */
    private function manifestPermissions(array $manifest): ?string
    {
        $perms = $manifest['permissions'] ?? null;
        if (!is_array($perms)) return null;
        $clean = array_values(array_unique(array_filter(array_map(
            fn($p) => is_string($p) ? mb_substr(trim($p), 0, 64) : '',
            $perms
        ), fn($p) => $p !== '')));
        return json_encode($clean, JSON_UNESCAPED_SLASHES);
    }

    // ------------------------------------------------------------------
    // Upgrades
    // ------------------------------------------------------------------

    /**
     * Tell an app it changed version, passing the old and new numbers.
     *
     * Previously this only fired on the marketplace/ZIP upgrade path. It now
     * also fires from sync(), so it lands however the new files arrived — the
     * upgrade flow, a manual FTP drop, or a core patch that bundled them.
     * Both paths funnel through here, and the dedupe below keeps that safe.
     *
     * Deliberately fired for DOWNgrades too. An author rolling back may need to
     * undo a schema change, and only they know whether that is safe; giving
     * them both numbers lets them decide. Compare with version_compare().
     *
     * Failures are logged and swallowed: a broken onUpgrade() must not leave
     * the apps table unsynced or take down the admin screen the operator needs
     * in order to deactivate the offending app.
     */
    private function fireUpgrade(string $slug, string $from, string $to): void
    {
        // upgradeApply() calls sync() and then announces the upgrade itself, so
        // without this an app upgraded through the marketplace would get
        // onUpgrade() twice — and a handler that adds a column or seeds a row
        // is rarely safe to run twice.
        $key = $slug . ':' . $from . ':' . $to;
        if (isset($this->upgradesFired[$key])) return;
        $this->upgradesFired[$key] = true;

        $this->logSafe("App '{$slug}' upgraded: {$from} -> {$to}");

        try {
            $app = \App\Core\Application::getInstance();

            // Evict first. The cached object is the pre-upgrade class when the
            // app was active, and it will not have any lifecycle method the new
            // version introduced.
            $this->forgetInstance($slug);

            // instance() resolves without calling boot(), which is what the
            // other lifecycle hooks use too.
            $instance = $this->instance($app, $slug);
            if ($instance === null || !method_exists($instance, 'onUpgrade')) return;
            $instance->onUpgrade($from, $to);
        } catch (\Throwable $e) {
            $this->logSafe("App '{$slug}' onUpgrade({$from}, {$to}) failed: " . $e->getMessage());
        }
    }

    // ------------------------------------------------------------------
    // Requirements
    // ------------------------------------------------------------------

    /**
     * Check an app's manifest "requires" block against this installation.
     *
     * Understood keys:
     *   php      ">=8.1"      PHP version constraint
     *   basehim  ">=1.36.0"   core version constraint
     *   apps     {"slug": ">=1.0"}   other apps that must be installed & active
     *
     * Returns a list of human-readable failures; empty means satisfied. An
     * unrecognised key is ignored rather than treated as a failure, so a
     * manifest written for a future Basehim still installs on this one.
     *
     * @return array<int,string>
     */
    public function checkRequirements(array $manifest): array
    {
        $requires = $manifest['requires'] ?? null;
        if (!is_array($requires)) return [];

        $problems = [];

        if (!empty($requires['php']) && is_string($requires['php'])) {
            if (!$this->satisfies(PHP_VERSION, $requires['php'])) {
                $problems[] = sprintf(
                    'Needs PHP %s — this server runs %s.',
                    $requires['php'], PHP_VERSION
                );
            }
        }

        $coreConstraint = $requires['basehim'] ?? $requires['core'] ?? null;
        if (!empty($coreConstraint) && is_string($coreConstraint)) {
            $core = defined('BASEHIM_VERSION') ? BASEHIM_VERSION : '0';
            if (!$this->satisfies($core, $coreConstraint)) {
                $problems[] = sprintf(
                    'Needs Basehim %s — this site runs %s.',
                    $coreConstraint, $core
                );
            }
        }

        if (!empty($requires['apps']) && is_array($requires['apps'])) {
            foreach ($requires['apps'] as $depSlug => $constraint) {
                $depSlug = (string) $depSlug;
                $dep = $this->find($depSlug);

                if (!$dep) {
                    $problems[] = "Needs the '{$depSlug}' app, which is not installed.";
                    continue;
                }
                if (($dep['status'] ?? '') !== 'active') {
                    $problems[] = "Needs the '{$depSlug}' app, which is installed but not active.";
                    continue;
                }
                if (is_string($constraint) && $constraint !== ''
                    && !$this->satisfies((string) ($dep['version'] ?? '0'), $constraint)) {
                    $problems[] = sprintf(
                        "Needs '%s' %s — version %s is installed.",
                        $depSlug, $constraint, $dep['version'] ?? '?'
                    );
                }
            }
        }

        return $problems;
    }

    /** Unmet requirements from the most recent failed activate(). */
    public function lastRequirementProblems(): array
    {
        return $this->lastRequirementProblems;
    }

    /** Requirement check for an installed app, read from its manifest on disk. */
    public function checkRequirementsFor(string $slug): array
    {
        $manifestFile = $this->manifestFileIn($this->dirFor($slug));
        if ($manifestFile === null) return [];

        $manifest = json_decode((string) @file_get_contents($manifestFile), true);
        return is_array($manifest) ? $this->checkRequirements($manifest) : [];
    }

    /**
     * Test a version against a constraint like ">=8.1", "^1.2", "1.4.*" or "1.0".
     *
     * Intentionally small — this is not Composer. It covers the forms that
     * actually appear in manifests, and anything it cannot parse is treated as
     * satisfied rather than blocking an install on a syntax it does not know.
     */
    private function satisfies(string $version, string $constraint): bool
    {
        $constraint = trim($constraint);
        if ($constraint === '' || $constraint === '*') return true;

        // Comma- or space-separated constraints must all hold: ">=1.0, <2.0"
        if (preg_match('/[,]/', $constraint)) {
            foreach (preg_split('/\s*,\s*/', $constraint) ?: [] as $part) {
                if (!$this->satisfies($version, $part)) return false;
            }
            return true;
        }

        // Caret: ^1.2 means >=1.2 and <2.0
        if (str_starts_with($constraint, '^')) {
            $base = ltrim($constraint, '^');
            $major = (int) strtok($base, '.');
            return version_compare($version, $base, '>=')
                && version_compare($version, (string) ($major + 1), '<');
        }

        // Wildcard: 1.4.* means >=1.4 and <1.5
        if (str_contains($constraint, '*')) {
            $prefix = rtrim(str_replace('*', '', $constraint), '.');
            if ($prefix === '') return true;
            $parts = explode('.', $prefix);
            $parts[count($parts) - 1] = (string) ((int) end($parts) + 1);
            return version_compare($version, $prefix, '>=')
                && version_compare($version, implode('.', $parts), '<');
        }

        if (preg_match('/^(>=|<=|>|<|!=|=)?\s*(.+)$/', $constraint, $m)) {
            $operator = $m[1] ?: '>=';   // a bare "8.1" reads as a minimum
            return version_compare($version, trim($m[2]), $operator);
        }

        return true;
    }

    // ------------------------------------------------------------------
    // Consent & scanning
    // ------------------------------------------------------------------

    /**
     * True when the app declares permissions the operator has not yet approved.
     *
     * Also true when an update ADDS a permission: an app that shipped asking
     * for posts.read and now also wants mail.send must be re-approved, or an
     * update would silently widen what it can do.
     */
    public function needsConsent(string $slug): bool
    {
        try {
            /** @var \App\Services\PermissionBroker $broker */
            $broker = \App\Core\Application::getInstance()
                ->make(\App\Services\PermissionBroker::class);

            $declared = $broker->declared($slug);
            if ($declared === []) return false;              // unrestricted app
            if (!$broker->hasConsented($slug)) return true;  // never approved

            // Anything newly declared since the last approval.
            $known = array_merge($broker->grantedFor($slug), $broker->withheld($slug));
            return array_diff($declared, $known) !== [];
        } catch (\Throwable) {
            // Broker unavailable (migration not yet run): don't block activation.
            return false;
        }
    }

    /**
     * Run the static scanner over an app's files and cache the result.
     *
     * Advisory only — a finding never blocks anything. Stored on the row so the
     * admin list doesn't have to re-scan on every page load.
     */
    public function scanApp(string $slug): array
    {
        try {
            /** @var \App\Services\AppScanner $scanner */
            $scanner = \App\Core\Application::getInstance()
                ->make(\App\Services\AppScanner::class);

            $result = $scanner->scan($this->dirFor($slug));
            $this->db->update('apps', [
                'scan_result'   => json_encode($result, JSON_UNESCAPED_SLASHES),
                'scanned_at'    => date('Y-m-d H:i:s'),
            ], ['slug' => $slug]);
            return $result;
        } catch (\Throwable $e) {
            $this->logSafe("Scan of '{$slug}' failed: " . $e->getMessage());
            return ['findings' => [], 'files_scanned' => 0, 'high' => 0, 'medium' => 0, 'skipped' => true];
        }
    }

    /** The cached scan result for an app, or null if never scanned. */
    public function scanResult(string $slug): ?array
    {
        try {
            $row = $this->db->selectOne('SELECT scan_result FROM {apps} WHERE slug = :s', ['s' => $slug]);
            $decoded = json_decode((string) ($row['scan_result'] ?? ''), true);
            return is_array($decoded) ? $decoded : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function validateManifest(array $manifest): void
    {
        foreach (['name', 'slug', 'version'] as $required) {
            if (empty($manifest[$required]) || !is_string($manifest[$required])) {
                throw new \RuntimeException("The app manifest is missing required field '{$required}'.");
            }
        }
        if (!preg_match('/^[a-z0-9][a-z0-9\-_]{1,80}$/i', $manifest['slug'])) {
            throw new \RuntimeException("The app manifest 'slug' is invalid (use letters, digits, dash, underscore; 2-80 chars).");
        }
    }

    private function sanitizeSlug(string $slug): string
    {
        $slug = strtolower(preg_replace('/[^a-z0-9\-_]/i', '-', $slug) ?? '');
        return trim($slug, '-_') ?: 'app';
    }

    private function deleteFiles(string $slug): void
    {
        $slug = $this->sanitizeSlug($slug);
        $real = realpath($this->dirFor($slug));
        if (!$real) return;
        // Refuse to delete anything outside a known app container.
        foreach ($this->searchPaths() as $container) {
            $base = realpath($container);
            if ($base && str_starts_with($real, $base . DIRECTORY_SEPARATOR)) {
                $this->rrmdir($real);
                unset($this->dirCache[$slug]);
                return;
            }
        }
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $p = $dir . '/' . $entry;
            is_dir($p) ? $this->rrmdir($p) : @unlink($p);
        }
        @rmdir($dir);
    }

    // ==================================================================
    // ===== Marketplace client (browse + install from CloudHim) =======
    //
    // NOTE: the remote endpoint paths belong to the CloudHim hub's public API
    // and are fixed by that contract, not by our own naming. They are spelled
    // exactly as the hub publishes them and must not be renamed here.
    // ==================================================================

    /** @return array{ok:bool, apps?:array, meta?:array, error?:string} */
    public function marketplaceBrowse(array $query = []): array
    {
        $conn = $this->cloudhimConn();
        if (!$conn) return ['ok' => false, 'error' => 'not_connected'];

        $qs = http_build_query(array_merge(['key' => $conn['key']], array_filter([
            'q'        => (string) ($query['q'] ?? ''),
            'category' => (string) ($query['category'] ?? ''),
            'tag'      => (string) ($query['tag'] ?? ''),
            'sort'     => (string) ($query['sort'] ?? ''),
            'page'     => (string) ($query['page'] ?? ''),
        ], fn($v) => $v !== '')));

        $res = $this->httpGet($conn['url'] . '/api/v1/cloudhim/plugins?' . $qs);
        if ($res['error'] !== null) return ['ok' => false, 'error' => 'Could not reach the Basehim marketplace: ' . $res['error']];
        $json = json_decode($res['body'], true);
        if (!is_array($json) || empty($json['ok'])) {
            return ['ok' => false, 'error' => (string) ($json['error'] ?? ('The Basehim marketplace returned HTTP ' . $res['status']))];
        }
        // Flag which are installed locally + whether active.
        //
        // The hub returns its result set under 'apps'; older hub builds used
        // 'plugins'. Neither is guaranteed to be present — an empty result set
        // omits the key entirely, which used to reach the loop as null and emit
        // "foreach() argument must be of type array|object".
        $listKey = isset($json['apps']) && is_array($json['apps']) ? 'apps'
                 : (isset($json['plugins']) && is_array($json['plugins']) ? 'plugins' : null);
        if ($listKey === null) {
            return $json;
        }
        $installed = $this->scan();
        foreach ($json[$listKey] as &$p) {
            $local = $installed[$p['slug']] ?? null;
            $p['installed'] = $local !== null;
            $p['installed_version'] = $local['version'] ?? null;
            if ($local !== null) {
                $row = $this->find($p['slug']);
                $p['active'] = $row !== null && ($row['status'] ?? '') === 'active';
            } else {
                $p['active'] = false;
            }
        }
        unset($p);
        return $json;
    }

    public function marketplaceFacets(): array
    {
        $conn = $this->cloudhimConn();
        if (!$conn) return ['ok' => false, 'error' => 'not_connected'];
        $res = $this->httpGet($conn['url'] . '/api/v1/cloudhim/plugins/facets?key=' . urlencode($conn['key']));
        $json = json_decode($res['body'], true);
        return is_array($json) ? $json : ['ok' => false, 'categories' => [], 'tags' => []];
    }

    /**
     * Download an app from CloudHim by slug and install it (SHA-256 verified).
     * If already installed, the files are replaced in-place (an update) while
     * the app's DB row / active state are preserved; a new install inserts
     * the row and fires onInstall via sync().
     */
    public function marketplaceInstall(string $slug): array
    {
        $conn = $this->cloudhimConn();
        if (!$conn) return ['ok' => false, 'error' => 'This site is not connected to the Basehim marketplace yet — open Updates to connect.'];
        $slug = $this->sanitizeSlug($slug);

        $url = $conn['url'] . '/api/v1/cloudhim/plugin-download?' . http_build_query(['key' => $conn['key'], 'slug' => $slug]);
        $res = $this->httpGet($url, 120);
        if ($res['error'] !== null || $res['status'] >= 400 || $res['body'] === '') {
            return ['ok' => false, 'error' => 'Download failed: ' . ($res['error'] ?? ('HTTP ' . $res['status']))];
        }

        $tmp = sys_get_temp_dir() . '/bh-app-' . bin2hex(random_bytes(5)) . '.zip';
        file_put_contents($tmp, $res['body']);

        $expected = strtolower(trim((string) ($res['headers']['x-checksum-sha256'] ?? '')));
        if ($expected !== '' && !hash_equals($expected, hash_file('sha256', $tmp))) {
            @unlink($tmp);
            return ['ok' => false, 'error' => 'Checksum mismatch — download may be corrupted. Nothing was installed.'];
        }

        try {
            $existing = $this->scan()[$slug] ?? null;
            if ($existing) {
                $result = $this->replaceAppFiles($tmp, $slug); // update in place
            } else {
                $result = $this->installFromZip($tmp);            // fresh install + sync()
            }
            @unlink($tmp);
            return ['ok' => true] + $result;
        } catch (\Throwable $e) {
            @unlink($tmp);
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Replace an installed app's files with a new zip (marketplace update),
     * keeping its DB row and active state. Old files are moved aside and
     * restored if the swap fails.
     */
    private function replaceAppFiles(string $zipPath, string $expectedSlug): array
    {
        $staged = $this->stageZip($zipPath);
        if ($staged['slug'] !== $expectedSlug) {
            $this->rrmdir($staged['tempDir']);
            throw new \RuntimeException("The downloaded app's slug ('{$staged['slug']}') does not match '{$expectedSlug}'.");
        }
        $target = $this->dirFor($expectedSlug);
        $aside = dirname($target) . '/.old_' . bin2hex(random_bytes(5));

        if (is_dir($target) && !@rename($target, $aside)) {
            $this->rrmdir($staged['tempDir']);
            throw new \RuntimeException('Could not move the existing app aside (permissions?).');
        }
        if (!@rename($staged['tempDir'], $target)) {
            if (is_dir($aside)) @rename($aside, $target); // restore
            $this->rrmdir($staged['tempDir']);
            throw new \RuntimeException('Could not move the updated app into place; the existing version was restored.');
        }
        if (is_dir($aside)) $this->rrmdir($aside);

        // Refresh the DB row's version/manifest without disturbing active state.
        $this->sync();
        return ['slug' => $expectedSlug, 'manifest' => $staged['manifest'], 'replaced' => true];
    }

    private function cloudhimConn(): ?array
    {
        try {
            $update = Application::getInstance()->make(\App\Services\UpdateService::class);
            if (!$update->isConfigured() && !$update->ensureConnected()) return null;
            $c = $update->config();
            if (($c['url'] ?? '') === '' || ($c['key'] ?? '') === '') return null;
            return ['url' => rtrim($c['url'], '/'), 'key' => $c['key']];
        } catch (\Throwable) {
            return null;
        }
    }

    private function httpGet(string $url, int $timeout = 30): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT      => 'Basehim-Marketplace/' . (defined('BASEHIM_VERSION') ? BASEHIM_VERSION : '1'),
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_HEADER         => true,
        ]);
        $raw = curl_exec($ch);
        $err = curl_errno($ch) !== 0 ? curl_error($ch) : null;
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $headers = [];
        $body = '';
        if (is_string($raw)) {
            foreach (explode("\r\n", substr($raw, 0, $headerSize)) as $line) {
                if (str_contains($line, ':')) {
                    [$k, $v] = explode(':', $line, 2);
                    $headers[strtolower(trim($k))] = trim($v);
                }
            }
            $body = substr($raw, $headerSize);
        }
        return ['body' => $body, 'status' => $status, 'error' => $err, 'headers' => $headers];
    }
}
