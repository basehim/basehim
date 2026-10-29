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

    /**
     * Slugs whose boot() ran in this request — i.e. apps that are active.
     * An app instance loaded only to fire a lifecycle method (onActivate,
     * onUninstall …) is not in here, so registrations that are meant only for
     * running apps (AJAX actions) can tell the difference.
     *
     * @var array<string,true>
     */
    private array $booted = [];

    /** True when the app's boot() ran in this request. */
    public function isBooted(string $slug): bool
    {
        return isset($this->booted[$slug]);
    }

    /** Resolved directory per slug, filled by scan(). slug => absolute path. */
    private array $dirCache = [];

    /** Why the last activate() refused, for the controller to surface. */
    private array $lastRequirementProblems = [];

    /** Upgrades already announced this request, keyed "slug:from:to". */
    private array $upgradesFired = [];

    /** Whether apps.schema_version exists; null until checked this request. */
    private ?bool $schemaColumn = null;

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
     * Since 1.2.85 it no longer registers folders it finds: `installed` stays
     * empty and `unregistered` lists folders that are not installed apps (see
     * register()).
     *
     * @return array{scanned:int,installed:string[],upgraded:array<int,array{slug:string,from:string,to:string}>,failed:array<int,array{slug:string,from:string,to:string,error:string}>,unchanged:string[],unregistered:string[]}
     */
    public function sync(): array
    {
        $summary = ['scanned' => 0, 'installed' => [], 'upgraded' => [], 'failed' => [], 'unchanged' => [], 'unregistered' => []];

        $available = $this->scan();
        $summary['scanned'] = count($available);

        foreach ($available as $slug => $manifest) {
            $row = $this->find($slug);
            if (!$row) {
                // 1.2.85: a folder is not an install. Only an app installed
                // through a ZIP upload, the marketplace or register() (Dev
                // Tools) is listed and can be activated; a folder copied in by
                // hand (FTP, a file manager, a renamed folder) stays inert and
                // is only counted on the Apps screen.
                $summary['unregistered'][] = $slug;
                continue;
            } else {
                $oldVersion = (string) ($row['version'] ?? '');
                $newVersion = (string) ($manifest['version'] ?? $oldVersion);
                $tracked = $this->hasSchemaColumn();

                // Update manifest-derived fields in case the file changed.
                // `version` is the version of the files; `schema_version`,
                // the version the app's data was migrated to, moves only
                // once onUpgrade() has succeeded (1.2.73, BH-024).
                $fields = [
                    'name'        => $manifest['name'] ?? $row['name'],
                    'description' => $manifest['description'] ?? $row['description'],
                    'version'     => $newVersion,
                    'author'      => $this->manifestAuthor($manifest) ?? $row['author'],
                    'vendor'      => $manifest['vendor'] ?? $row['vendor'],
                    'icon'        => $this->manifestIcon($manifest),
                    'permissions' => $this->manifestPermissions($manifest),
                ];
                if ($tracked && ($row['schema_version'] ?? null) === null) {
                    $fields['schema_version'] = $oldVersion;
                }
                $this->db->update('apps', $fields, ['id' => $row['id']]);

                // The app's files changed version underneath it, or an earlier
                // upgrade has not completed. This is where the app gets told,
                // so an author has a supported place to migrate their tables.
                // An app switched off after a failure is upgraded when it is
                // next activated, after its code has been validated again.
                $schema = $tracked ? $this->schemaVersionOf(['schema_version' => $row['schema_version'] ?? null, 'version' => $oldVersion]) : $oldVersion;
                if ($oldVersion !== '' && $newVersion !== '' && $schema !== '' && $schema !== $newVersion) {
                    if (!$tracked) {
                        $this->fireUpgrade($slug, $oldVersion, $newVersion);
                        $summary['upgraded'][] = ['slug' => $slug, 'from' => $oldVersion, 'to' => $newVersion];
                    } elseif (($row['status'] ?? '') !== 'error') {
                        $err = $this->runPendingUpgrade($slug);
                        $entry = ['slug' => $slug, 'from' => $schema, 'to' => $newVersion];
                        if ($err !== null) {
                            $entry['error'] = $err;
                            $summary['failed'][] = $entry;
                        } else {
                            $summary['upgraded'][] = $entry;
                        }
                    } else {
                        $summary['unchanged'][] = $slug;
                    }
                } else {
                    $summary['unchanged'][] = $slug;
                }
            }
        }

        return $summary;
    }

    // ==================================================================
    // Registration (1.2.85)
    //
    // An app is installed when core registered it — from a ZIP upload, the
    // marketplace, or register() called by a trusted tool (Basehim Dev
    // Tools). A folder that appears in content/apps any other way is not
    // listed, loaded or activatable, so a file manager or FTP can't add code
    // that runs, and a renamed folder can't show up as a second app.
    // ==================================================================

    public const SOURCES = ['upload', 'marketplace', 'devtools', 'legacy'];

    /**
     * Register an app whose files are already in place: insert its row,
     * run onInstall(), the security scan and code validation.
     *
     * @param string $source upload | marketplace | devtools
     * @return array{slug:string,registered:bool,scan:array,validation:?array}
     * @throws \RuntimeException when the folder is not a valid app
     */
    public function register(string $slug, string $source): array
    {
        $slug = $this->sanitizeSlug($slug);
        if (!in_array($source, ['upload', 'marketplace', 'devtools'], true)) {
            throw new \RuntimeException("Unknown install source '{$source}'.");
        }
        if ($this->find($slug)) {
            return ['slug' => $slug, 'registered' => false, 'scan' => $this->scanResult($slug) ?? [], 'validation' => null];
        }
        $dir = $this->appPath . '/' . $slug;
        $manifest = $this->readManifest($dir);
        if ($manifest === null) {
            throw new \RuntimeException("content/apps/{$slug} has no readable app.json.");
        }
        $this->validateManifest($manifest);
        if (($manifest['slug'] ?? $slug) !== $slug) {
            throw new \RuntimeException("The folder '{$slug}' holds the app '" . (string) $manifest['slug'] . "': an app's folder must be named after its slug.");
        }
        unset($this->dirCache[$slug]);

        $this->db->insert('apps', [
            'vendor'      => $manifest['vendor'] ?? 'community',
            'slug'        => $slug,
            'name'        => $manifest['name'] ?? $slug,
            'description' => $manifest['description'] ?? null,
            'version'     => $manifest['version'] ?? '0.0.1',
            'author'      => $this->manifestAuthor($manifest),
            'icon'        => $this->manifestIcon($manifest),
            'permissions' => $this->manifestPermissions($manifest),
            'status'      => 'inactive',
        ] + ($this->hasSchemaColumn() ? ['schema_version' => ''] : [])
          + ($this->hasSourceColumn() ? ['source' => $source] : []));

        // schema_version stays '' until onInstall() has run (1.2.73, BH-024):
        // a failure is recorded in the app's report and onInstall() runs
        // again on activation.
        $err = $this->fireLifecycle($slug, 'onInstall');
        if ($err === null) {
            $this->markInstalled($slug, (string) ($manifest['version'] ?? '0.0.1'));
        } elseif (!$err instanceof \App\Services\AppHealth\AppNotLoaded) {
            $this->guard()?->failed($slug, (string) ($manifest['version'] ?? ''), 'lifecycle:onInstall', $err);
        }

        // The security scan and code validation, so any finding is on the
        // Apps screen before anyone activates it. Neither blocks the install.
        $scan = $this->scanApp($slug);
        $validation = null;
        try { $validation = $this->validate($slug); } catch (\Throwable) {}
        $this->logSafe("App '{$slug}' registered ({$source}).");

        return ['slug' => $slug, 'registered' => true, 'scan' => $scan, 'validation' => $validation];
    }

    /**
     * Folders in content/apps with an app.json that are not installed apps:
     * copied in by hand, or renamed. Listed by name for the Apps screen's note.
     *
     * @return string[]
     */
    public function unregisteredFolders(): array
    {
        $out = [];
        foreach (array_keys($this->scan()) as $slug) {
            if (!$this->find((string) $slug)) $out[] = (string) $slug;
        }
        sort($out);
        return $out;
    }

    /**
     * Keep the registry honest about files that changed by hand:
     *
     *  - an installed app whose folder is gone (deleted or renamed) is
     *    switched off — its code can't run — and listed as "Files missing";
     *    its settings and data stay until it is removed;
     *  - a row registered before 1.2.85 for a folder whose app.json names a
     *    different app (a renamed copy that older versions picked up) is
     *    dropped from the list when it isn't running. Files are never touched.
     *
     * @return array{switched_off:string[],dropped:string[]}
     */
    public function reconcile(): array
    {
        $out = ['switched_off' => [], 'dropped' => []];
        try { $rows = $this->installed(); } catch (\Throwable) { return $out; }
        $onDisk = $this->scan();
        foreach ($rows as $row) {
            $slug = (string) $row['slug'];
            $status = (string) ($row['status'] ?? '');
            if (!isset($onDisk[$slug])) {
                if ($status === 'active' || $status === 'error') {
                    $this->db->update('apps', ['status' => 'inactive'], ['slug' => $slug]);
                    unset($this->instances[$slug], $this->booted[$slug]);
                    $out['switched_off'][] = $slug;
                    $this->logSafe("App '{$slug}' was switched off: its folder content/apps/{$slug} is missing.");
                }
                continue;
            }
            $claimed = $onDisk[$slug]['slug'] ?? null;
            if (is_string($claimed) && $claimed !== '' && $claimed !== $slug
                && ($row['source'] ?? 'legacy') === 'legacy' && $status === 'inactive') {
                $this->db->delete('apps', ['slug' => $slug]);
                $out['dropped'][] = $slug;
                $this->logSafe("App record '{$slug}' removed from the list: the folder holds '{$claimed}', so it was a renamed copy, not an install.");
            }
        }
        return $out;
    }

    /**
     * Run the security scan on installed apps that were never scanned (apps
     * from before the scanner, or an older install route), at most $limit
     * per call so a page load stays quick.
     */
    public function scanUnscanned(int $limit = 5): int
    {
        $n = 0;
        try {
            foreach ($this->db->select('SELECT slug FROM {apps} WHERE scanned_at IS NULL ORDER BY id') as $row) {
                if ($n >= $limit) break;
                if ($this->filesMissing((string) $row['slug'])) continue;
                $this->scanApp((string) $row['slug']);
                $n++;
            }
        } catch (\Throwable) {}
        return $n;
    }

    /**
     * What the permission window shows besides the permissions (1.2.85): the
     * app's own reasons for each (app.json `permission_reasons`, plain text),
     * and the checks it passed — where it came from, the security scan and
     * the code check — so the person deciding sees why it is safe to allow.
     *
     * @return array{reasons:array<string,string>,checks:array{source:string,scanned:bool,findings:int,code_ok:bool}}
     */
    public function consentContext(string $slug): array
    {
        $row = $this->find($slug) ?? [];
        $manifest = $this->readManifest($this->appPath . '/' . $slug) ?? [];
        $reasons = [];
        foreach ((array) ($manifest['permission_reasons'] ?? []) as $key => $why) {
            if (!is_string($key) || !is_string($why)) continue;
            $why = trim(preg_replace('/\s+/', ' ', strip_tags($why)) ?? '');
            if ($why !== '') $reasons[$key] = mb_substr($why, 0, 200);
        }
        $scan = $this->scanResult($slug);
        $state = '';
        try { $state = (string) ($this->guard()?->store()->get($slug)['state'] ?? ''); } catch (\Throwable) {}
        return [
            'reasons' => $reasons,
            'checks'  => [
                'source'   => (string) ($row['source'] ?? 'legacy'),
                'scanned'  => is_array($scan),
                'findings' => is_array($scan) ? (int) ($scan['high'] ?? 0) + (int) ($scan['medium'] ?? 0) : 0,
                'code_ok'  => !in_array($state, ['invalid', 'quarantined'], true),
            ],
        ];
    }

    /** Whether an installed app's files are missing (folder gone or renamed). */
    public function filesMissing(string $slug): bool
    {
        return $this->manifestFileIn($this->appPath . '/' . $slug) === null;
    }

    private ?bool $sourceColumn = null;

    /** Whether apps.source exists (migration 021), created on first need. */
    private function hasSourceColumn(): bool
    {
        if ($this->sourceColumn !== null) return $this->sourceColumn;
        try {
            $col = $this->db->selectOne("SHOW COLUMNS FROM {apps} LIKE 'source'");
            if ($col === null) {
                // New files can run before migration 021 (a patch uploaded by
                // hand): add the column here, marking existing rows as legacy.
                $this->db->execute("ALTER TABLE {apps} ADD COLUMN `source` VARCHAR(20) NULL AFTER `status`");
                $this->db->execute("UPDATE {apps} SET `source` = 'legacy' WHERE `source` IS NULL");
            }
            return $this->sourceColumn = true;
        } catch (\Throwable $e) {
            $this->logSafe('apps.source is unavailable: ' . $e->getMessage());
            return $this->sourceColumn = false;
        }
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

        // Validate the app's code before it runs on every request (1.2.71):
        // always afresh, so an app that was switched off after a failure is
        // only turned back on once its current files pass.
        $guard = $this->guard();
        if ($guard !== null) {
            $dir = $this->dirFor($slug);
            $manifest = $this->readManifest($dir) ?? [];
            $report = $guard->validateNow($slug, $dir, $manifest);
            if (!$report['ok']) {
                $msgs = array_map(static fn($e) => (string) $e['message'], array_slice($report['errors'], 0, 3));
                $more = count($report['errors']) - count($msgs);
                $this->lastRequirementProblems = ['Its code did not pass validation: ' . implode(' ', $msgs) . ($more > 0 ? " (and {$more} more)" : '')];
                $this->logSafe("App '{$slug}' cannot activate: it did not pass validation.");
                return false;
            }
            $rec = $guard->record($slug);
            if (($row['status'] ?? '') === 'error' || in_array($rec['state'] ?? '', ['quarantined', 'off', 'invalid'], true)) {
                $guard->beginRetry($slug);
            }
        }

        // The app's own setup runs BEFORE it is marked active, and a failure
        // keeps it off (1.2.73, BH-024). Until 1.2.73 the status was written
        // first and a throwing onActivate() was only logged, so an app whose
        // tables were never created was reported "activated" and left running.
        //   1. an onInstall() that failed earlier runs again;
        //   2. a pending upgrade of its data (schema_version) runs;
        //   3. onActivate().
        if ($this->hasSchemaColumn() && ($this->find($slug)['schema_version'] ?? null) === '') {
            $err = $this->fireLifecycle($slug, 'onInstall');
            if ($err !== null) return $this->lifecycleRefused($slug, $row, 'onInstall', $err);
            $this->markInstalled($slug, (string) ($row['version'] ?? ''));
        }
        $upgradeError = $this->runPendingUpgrade($slug);
        if ($upgradeError !== null) {
            $this->lastRequirementProblems = [$upgradeError . ' The app was not activated; its report has the details.'];
            return false;
        }
        $err = $this->fireLifecycle($slug, 'onActivate');
        if ($err !== null) return $this->lifecycleRefused($slug, $row, 'onActivate', $err);

        $this->db->execute(
            "UPDATE {apps} SET status = 'active', activated_at = NOW() WHERE slug = :slug",
            ['slug' => $slug]
        );

        return true;
    }

    /** onInstall() has run: the app's data is at its files' version. */
    private function markInstalled(string $slug, string $version): void
    {
        if (!$this->hasSchemaColumn()) return;
        try {
            $this->db->execute("UPDATE {apps} SET schema_version = :v WHERE slug = :s AND schema_version = ''", ['v' => $version, 's' => $slug]);
        } catch (\Throwable $e) {
            $this->logSafe("Could not record {$slug}'s install: " . $e->getMessage());
        }
    }

    /** A lifecycle method failed during activation: record it and say why. */
    private function lifecycleRefused(string $slug, array $row, string $method, \Throwable $e): bool
    {
        if (!$e instanceof \App\Services\AppHealth\AppNotLoaded) {
            $this->guard()?->failed($slug, (string) ($row['version'] ?? ''), 'lifecycle:' . $method, $e);
        }
        $this->lastRequirementProblems = [
            "Its {$method}() failed: " . $this->cleanMessage($e) . ' The app was not activated; its report has the details.',
        ];
        return false;
    }

    /**
     * Active apps whose app.json requires $slug (`requires.apps`), as
     * slug => name (1.2.90, BH-059). Admin actions refuse to switch off or
     * delete an app while these depend on it.
     *
     * @return array<string,string>
     */
    public function activeDependents(string $slug): array
    {
        $out = [];
        foreach ($this->installed() as $row) {
            $other = (string) ($row['slug'] ?? '');
            if ($other === '' || $other === $slug || ($row['status'] ?? '') !== 'active') continue;
            $m = $this->readManifest($this->dirFor($other)) ?? [];
            $needs = $m['requires']['apps'] ?? null;
            if (is_array($needs) && array_key_exists($slug, $needs)) {
                $out[$other] = (string) ($row['name'] ?? $other);
            }
        }
        return $out;
    }

    /**
     * Refuse when active apps still need $slug, unless they are switched off
     * in the same action ($alsoGoing).
     *
     * @param string[] $alsoGoing
     * @throws \RuntimeException
     */
    public function assertNotNeeded(string $slug, string $verb, array $alsoGoing = []): void
    {
        $left = array_diff_key($this->activeDependents($slug), array_flip($alsoGoing));
        if (!$left) return;
        $names = array_values($left);
        $list = count($names) === 1 ? $names[0] : implode(', ', array_slice($names, 0, -1)) . ' and ' . end($names);
        throw new \RuntimeException(sprintf(
            '%s %s on it. %s %s first, or select %s too.',
            $list, count($names) === 1 ? 'depends' : 'depend',
            ucfirst($verb), count($names) === 1 ? 'that app' : 'those apps', count($names) === 1 ? 'it' : 'them'
        ));
    }

    public function deactivate(string $slug): bool
    {
        $row = $this->find($slug);
        if (!$row) return false;

        // Fire onDeactivate while the app is still bootable. An app switched
        // off after a failure is not loaded for this (AppGuard refuses), so
        // switching off a broken app cannot break the request doing it.
        $this->fireLifecycle($slug, 'onDeactivate');
        $this->db->execute(
            "UPDATE {apps} SET status = 'inactive', activated_at = NULL WHERE slug = :slug",
            ['slug' => $slug]
        );
        $this->guard()?->released($slug);
        return true;
    }

    /**
     * Uninstall = remove DB row & app-owned settings. Files are kept
     * unless $deleteFiles is true.
     */
    public function uninstall(string $slug, bool $deleteFiles = false): bool
    {
        if ($deleteFiles) $this->forgetCachedUpdate($slug);
        $row = $this->find($slug);
        if (!$row) {
            // No DB row, but we may still need to remove files.
            if ($deleteFiles) $this->deleteFiles($slug);
            return false;
        }

        if (in_array($row['status'], ['active', 'error'], true)) {
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

        // Its scheduled tasks go too (1.2.73, BH-023): rows left behind by an
        // uninstalled app stayed due for ever.
        try {
            \App\Core\Application::getInstance()
                ->make(\App\Services\SchedulerService::class)->forgetApp($slug);
        } catch (\Throwable) {}

        // Remove DB row.
        $this->db->delete('apps', ['slug' => $slug]);
        $this->guard()?->released($slug, forget: true);

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
        $this->forgetCachedUpdate($slug);
        return $this->uninstall($slug, deleteFiles: true);
    }

    /**
     * Install an app from an uploaded ZIP file.
     *
     * @param string $zipPath  Path to the uploaded .zip on disk.
     * @return array{slug:string, manifest:array}
     * @throws \RuntimeException on validation failure.
     */
    public function installFromZip(string $zipPath, string $source = 'upload'): array
    {
        $staged = $this->stageZip($zipPath);
        $slug = $staged['slug'];
        $targetDir = $this->appPath . '/' . $slug;

        if (is_dir($targetDir)) {
            $this->rrmdir($staged['tempDir']);
            if (!$this->find($slug)) {
                throw new \RuntimeException("A folder named '{$slug}' is already in content/apps, but it isn't an installed app. Delete or rename that folder, then install again.");
            }
            throw new \RuntimeException("An app with slug '{$slug}' is already installed. Uninstall it first to reinstall, or use the upgrade flow.");
        }

        if (!@rename($staged['tempDir'], $targetDir)) {
            $this->rrmdir($staged['tempDir']);
            throw new \RuntimeException('Could not move the extracted app into place.');
        }
        // An app deleted moments ago may still be compiled in OPcache under
        // these same paths; without this the first request ran the old code
        // against the new files and the boot guard switched the app off (BH-069).
        $this->forgetCompiled($targetDir);

        // Register it (1.2.85): the row, onInstall(), the security scan and
        // code validation. A row left from an earlier install whose files
        // went missing is reused as it is.
        try {
            $reg = $this->register($slug, $source);
            if (!$reg['registered']) {
                // Its row was already there (the files had gone missing):
                // refresh it from the new manifest and scan the new files.
                $this->sync();
                $reg['scan'] = $this->scanApp($slug);
            }
        } catch (\Throwable $e) {
            $this->rrmdir($targetDir);
            throw $e instanceof \RuntimeException ? $e : new \RuntimeException($e->getMessage(), 0, $e);
        }

        return ['slug' => $slug, 'manifest' => $staged['manifest'], 'scan' => $reg['scan'], 'validation' => $reg['validation']];
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

        // 2c. Validate the new code before any of it runs (1.2.71). An active
        //     app that fails is switched off; its upgrade hooks are then not
        //     run (AppGuard refuses to load it) and the report says why.
        try { $this->validate($slug); } catch (\Throwable) {}
        $this->scanApp($slug);   // the new files, scanned like a fresh install (1.2.85)

        // 3. Refresh DB row from the new manifest. sync() also runs the app's
        //    onUpgrade(from, to) and records the new schema version only if it
        //    succeeded (1.2.73).
        $upgradeError = null;
        try {
            $summary = $this->sync();
            foreach ($summary['failed'] ?? [] as $f) {
                if (($f['slug'] ?? '') === $slug) $upgradeError = (string) $f['error'];
            }
        } catch (\Throwable $e) {
            $this->logSafe("upgrade sync failed for {$slug}: " . $e->getMessage());
            $upgradeError = 'The app list could not be updated: ' . $this->cleanMessage($e);
        }
        if ($upgradeError === null && !$this->hasSchemaColumn()) {
            $e = $this->fireUpgrade($slug, $fromVersion, $toVersion);
            if ($e !== null) $upgradeError = 'Its onUpgrade() failed: ' . $this->cleanMessage($e);
        }

        // 4. Re-run onActivate if it was active, so any activation-only schema
        //    is applied. Not after a failed upgrade: the app is off then.
        $stillActive = ($this->find($slug)['status'] ?? '') === 'active';
        if ($upgradeError === null && $wasActive && $stillActive) {
            $e = $this->fireLifecycle($slug, 'onActivate');
            if ($e !== null) {
                $this->guard()?->failed($slug, $toVersion, 'lifecycle:onActivate', $e);
                $upgradeError = 'Its onActivate() failed: ' . $this->cleanMessage($e);
            }
        }

        // 5. Drop the backup: the new files stay either way. A failed data
        //    upgrade is retried on activation, which needs the new code.
        if (isset($backupDir) && is_dir($backupDir)) $this->rrmdir($backupDir);

        return ['slug' => $slug, 'from' => $fromVersion, 'to' => $toVersion, 'was_active' => $wasActive,
                'error' => $upgradeError];
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
        $guard = $this->guard();
        $outer = $guard?->currentApp() !== null;
        if (!$outer) $guard?->begin($slug, 'lifecycle:' . $method);
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
        } finally {
            if (!$outer) $guard?->end();
        }
    }

    private function logSafe(string $msg): void
    {
        try { Application::getInstance()->make(Logger::class)->warning($msg); } catch (\Throwable) {}
    }

    /**
     * Bootstrap every active app: register its namespace and call boot().
     *
     * Since 1.2.71 each app goes through AppGuard: an app whose files changed
     * is validated before any of its code is included (all such apps at once,
     * in parallel), a quarantined app is skipped, and an app that throws
     * while loading or booting has its partial registrations removed and is
     * switched off — the other apps, the theme and the page carry on.
     */
    public function bootActive(Application $app): void
    {
        try {
            $rows = $this->active();
        } catch (\Throwable $e) {
            // DB might not be ready (e.g., during install). Silently skip.
            return;
        }

        $guard = $this->guard();
        if ($guard === null) {
            // Without the guard (it could not be built), boot as before.
            foreach ($rows as $row) {
                try { $this->loadAndBoot($app, $row['slug']); } catch (\Throwable $e) { $this->logSafe("App '{$row['slug']}' failed to boot: " . $e->getMessage()); }
            }
            return;
        }
        $guard->registerShutdown();
        $this->answerForDisabledApps($app, $guard);

        // Validate, in one go, every active app whose files (or core) changed.
        $apps = [];
        foreach ($rows as $row) {
            $slug = (string) $row['slug'];
            $dir = $this->dirFor($slug);
            $manifest = $this->readManifest($dir);
            if ($manifest === null) {
                // Its folder was deleted or renamed by hand (1.2.85): switch
                // it off so the list says so, instead of "active" with no code.
                try {
                    $this->db->update('apps', ['status' => 'inactive'], ['slug' => $slug]);
                    $this->logSafe("App '{$slug}' was switched off: its folder content/apps/{$slug} is missing.");
                } catch (\Throwable) {}
                continue;
            }
            $apps[$slug] = ['dir' => $dir, 'manifest' => $manifest];
        }
        $this->validatedForBoot = array_fill_keys($guard->preflightActive($apps), true);

        $booted = [];
        foreach ($rows as $row) {
            $slug = (string) $row['slug'];
            if (!isset($this->validatedForBoot[$slug])) continue;
            if ($this->bootGuarded($app, $guard, $slug, (string) ($row['version'] ?? ''))) $booted[] = $slug;
        }
        $this->validatedForBoot = [];

        // An app that didn't start for want of a permission: its admin
        // screens (as remembered from its last start) ask for it (1.2.85).
        if ($this->permissionBlocked) $this->askForBlockedPermissions($guard);

        // Close any open recovery, and remember each app's URL prefixes so
        // its screens can show the report if it is ever switched off.
        if ($booted) {
            $byOwner = [];
            try {
                foreach ($app->make('router')->routes() as $r) {
                    if (is_string($r['owner'] ?? null)) $byOwner[$r['owner']][] = $r;
                }
            } catch (\Throwable) {}
            foreach ($booted as $slug) {
                try { $guard->booted($slug, $byOwner[$slug] ?? []); } catch (\Throwable) {}
            }
        }
    }

    /** Apps that passed validation for this boot (so loadAndBoot need not ask again). @var array<string,true> */
    private array $validatedForBoot = [];

    /**
     * Load and boot one app inside the guard's boundary.
     *
     * @return bool true when it booted
     */
    private function bootGuarded(Application $app, \App\Services\AppHealth\AppGuard $guard, string $slug, string $version): bool
    {
        $guard->begin($slug, 'load', $version);
        $hooks = $this->hooks;
        $prevHookOwner = $hooks->setOwner($slug);
        try {
            $this->loadAndBoot($app, $slug);
            return isset($this->instances[$slug]);
        } catch (\Throwable $e) {
            $stage = $this->bootStage ?? 'load';
            $this->rollback($app, $slug);
            // A permission it hasn't been allowed (1.2.85) is not broken code:
            // don't switch it off. Its screens ask for the permission instead
            // (AppPermissionPage), and it starts normally once it's allowed.
            if (($pm = \App\Core\AppPermissionMissing::in($e)) !== null) {
                $this->permissionBlocked[$slug] = $pm;
                $this->logSafe("App '{$slug}' did not start: it needs the '{$pm->permission}' permission.");
                try { \App\Core\Application::getInstance()->make(\App\Services\AppLogger::class)->log($slug, 'warning', "Not started: it needs the '{$pm->permission}' permission. Allow it under Apps → Permissions.", []); } catch (\Throwable) {}
                return false;
            }
            $guard->failed($slug, $version, $stage, $e);
            return false;
        } finally {
            $hooks->setOwner($prevHookOwner);
            $this->bootStage = null;
            $guard->end();
        }
    }

    /** Apps that didn't start for want of a permission, this request (1.2.85). @var array<string,\App\Core\AppPermissionMissing> */
    private array $permissionBlocked = [];

    /** @return array<string,\App\Core\AppPermissionMissing> */
    public function permissionBlocked(): array { return $this->permissionBlocked; }

    /** Stage of the app being booted, for the failure report. */
    private ?string $bootStage = null;

    private function setStage(string $stage): void
    {
        $this->bootStage = $stage;
        $this->guard()?->stage($stage);
    }

    /**
     * Take back everything an app registered before it failed: routes, hooks
     * (which carry its menu items, assets and head/footer output), AJAX
     * actions, widgets and content types. Other apps' registrations are not
     * touched.
     */
    private function rollback(Application $app, string $slug): void
    {
        $removed = [];
        try { $removed['routes'] = $app->make('router')->forgetOwner($slug); } catch (\Throwable) {}
        try { $removed['hooks'] = $this->hooks->forgetOwner($slug); } catch (\Throwable) {}
        try { $removed['ajax'] = $app->make(\App\Core\Ajax\AjaxRegistry::class)->forgetOwner($slug); } catch (\Throwable) {}
        try { $removed['widgets'] = $app->make(\App\Core\WidgetRegistry::class)->forgetOwner('app:' . $slug); } catch (\Throwable) {}
        try { $removed['post_types'] = $app->make(\App\Services\PostTypeRegistry::class)->forgetOwner($slug); } catch (\Throwable) {}
        try { $removed['search_sources'] = $app->make(\App\Core\Search\SearchManager::class)->forgetOwner($slug); } catch (\Throwable) {}
        if (function_exists('bh_assets')) {
            $reg = &bh_assets();
            foreach (['styles', 'scripts'] as $k) {
                foreach (array_keys($reg[$k] ?? []) as $handle) {
                    if (str_starts_with((string) $handle, $slug . '-')) unset($reg[$k][$handle]);
                }
            }
        }
        unset($this->instances[$slug], $this->booted[$slug]);
        $this->logSafe("App '{$slug}' registrations removed after a failed boot: " . json_encode($removed));
    }

    private function askForBlockedPermissions(\App\Services\AppHealth\AppGuard $guard): void
    {
        $blocked = $this->permissionBlocked;
        $prev = $this->hooks->setOwner(null);
        $this->hooks->addFilter('route.miss', static function ($handled, $request = null, $path = '') use ($guard, $blocked) {
            if ($handled !== null || !is_string($path) || $path === '' || !$request instanceof \App\Core\Request) return $handled;
            $path = '/' . trim($path, '/');
            $routes = $guard->store()->index()['routes'] ?? [];
            foreach ($blocked as $slug => $pm) {
                foreach (['admin', 'public'] as $kind) {
                    foreach ((array) ($routes[$slug][$kind] ?? []) as $prefix) {
                        if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                            return \App\Http\Controllers\Admin\AppPermissionPage::answer($request, $pm) ?? $handled;
                        }
                    }
                }
            }
            return $handled;
        }, 4, 3);
        $this->hooks->setOwner($prev);
    }

    /**
     * Requests for a switched-off app's own URLs: its admin screens send an
     * administrator to its report (core's page, not the app's), and its
     * public pages answer 503 instead of a misleading 404.
     */
    private function answerForDisabledApps(Application $app, \App\Services\AppHealth\AppGuard $guard): void
    {
        $prev = $this->hooks->setOwner(null);
        $this->hooks->addFilter('route.miss', static function ($handled, $request = null, $path = '') use ($guard) {
            if ($handled !== null || !is_string($path) || $path === '') return $handled;
            $owner = $guard->ownerOfPath($path);
            if ($owner === null) return $handled;
            $base = defined('BASEHIM_BASE') ? (string) BASEHIM_BASE : '';
            $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
            if ($owner['admin'] && in_array($method, ['GET', 'HEAD'], true)) {
                return \App\Core\Response::redirect($base . '/admin/apps/' . rawurlencode($owner['slug']) . '/health?from=' . rawurlencode($path));
            }
            $r = \App\Core\Response::view('errors.app-disabled', ['admin' => $owner['admin']], 503);
            $r->header('Retry-After', '3600');
            return $r;
        }, 5, 3);
        $this->hooks->setOwner($prev);
    }

    private function readManifest(string $dir): ?array
    {
        $file = $this->manifestFileIn($dir);
        if ($file === null) return null;
        $m = json_decode((string) @file_get_contents($file), true);
        return is_array($m) ? $m : null;
    }

    /** The fault-isolation guard, or null if it cannot be built. */
    public function guard(): ?\App\Services\AppHealth\AppGuard
    {
        try {
            return Application::getInstance()->make(\App\Services\AppHealth\AppGuard::class);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Validate an app's files now and save the report (Apps screen, after an
     * install or update). An active app that fails is switched off at once,
     * before the next request loads it.
     */
    public function validate(string $slug): ?array
    {
        $guard = $this->guard();
        if ($guard === null) return null;
        $dir = $this->dirFor($slug);
        $manifest = $this->readManifest($dir);
        if ($manifest === null) return null;
        $report = $guard->validateNow($slug, $dir, $manifest);
        $row = $this->find($slug);
        if (!$report['ok'] && ($row['status'] ?? '') === 'active') {
            $guard->safeToLoad($slug, $dir, $manifest, true);
        }
        return $report;
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
                $this->booted[$slug] = true;
                $this->callBoot($instance, $app);
            }
            return $instance;
        }

        $appDir = $this->dirFor($slug);
        $manifestFile = $this->manifestFileIn($appDir);
        if ($manifestFile === null) return null;

        $manifest = json_decode((string)file_get_contents($manifestFile), true);
        if (!is_array($manifest)) return null;

        // No app code is included before the guard has seen it (1.2.71): a
        // quarantined app is not loaded, and one whose files changed is
        // validated first. Boot has validated every active app already.
        if (!isset($this->validatedForBoot[$slug])) {
            $guard = $this->guard();
            if ($guard !== null) {
                $active = (($this->find($slug)['status'] ?? '') === 'active');
                if (!$guard->safeToLoad($slug, $appDir, $manifest, $active, fresh: true)) {
                    $this->logSafe("App '{$slug}' was not loaded: it is switched off or did not pass validation. See Apps → {$slug} → Report.");
                    return null;
                }
            }
        }
        $this->setStage('autoload');

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

        $this->setStage('construct');
        $instance = $this->instantiate($app, $className, $slug, $manifest, $appDir);
        $this->instances[$slug] = $instance;

        if ($callBoot && method_exists($instance, 'boot')) {
            // Marked before boot() runs, because boot() is where an app
            // registers what needs this flag.
            $this->booted[$slug] = true;
            // Routes added during boot() are credited to the app (API reference).
            $router = null;
            try { $router = $app->make('router'); } catch (\Throwable) {}
            $prevOwner = $router instanceof \App\Core\Router ? $router->setOwner($slug) : null;
            try {
                $this->setStage('boot');
                $this->callBoot($instance, $app);
            } finally {
                if ($router instanceof \App\Core\Router) $router->setOwner($prevOwner);
            }
        }

        return $instance;
    }

    /**
     * Call the app's boot(). Since 1.2.71 an exception is not swallowed here:
     * it reaches bootGuarded(), which removes what the app registered and
     * switches it off. Before, a boot() that failed half-way left the app
     * running with whatever it had registered up to that point.
     */
    private function callBoot(object $instance, Application $app): void
    {
        $ref = new \ReflectionMethod($instance, 'boot');
        // Apps extending the base class take no args; a bare boot(Application)
        // implementation is also supported, so honour whichever it declares.
        if ($ref->getNumberOfParameters() === 0) {
            $instance->boot();
        } else {
            $instance->boot($app);
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
    /**
     * Run one lifecycle method of an app.
     *
     * Returns null when it ran (or the app does not define it), else what
     * went wrong: the exception the app threw, or AppNotLoaded when its code
     * could not be loaded (1.2.73). Callers that only notify (onDeactivate,
     * onUninstall) may ignore it; activation and installation must not.
     */
    private function fireLifecycle(string $slug, string $method): ?\Throwable
    {
        $guard = $this->guard();
        $outer = $guard?->currentApp() !== null;
        try {
            $app = Application::getInstance();
            // Credit an engine fatal in this app's code to the app (1.2.71).
            if (!$outer) $guard?->begin($slug, 'lifecycle:' . $method);
            $instance = $this->loadAndBoot($app, $slug, callBoot: false);
            if ($instance === null) {
                return new \App\Services\AppHealth\AppNotLoaded(
                    "The app's code could not be loaded, so {$method}() did not run. Its report says why."
                );
            }
            if (method_exists($instance, $method)) {
                $instance->{$method}();
            }
            return null;
        } catch (\Throwable $e) {
            try {
                Application::getInstance()->make(Logger::class)->warning(
                    "App lifecycle '{$method}' failed",
                    ['app' => $slug, 'error' => $e->getMessage()]
                );
            } catch (\Throwable) {}
            return $e;
        } finally {
            if (!$outer) $guard?->end();
        }
    }

    /**
     * The name stored in the apps table's `author` column: the manifest's
     * "author", or since 1.2.36 its developer's name. Older code and views
     * read `author`, so it keeps meaning "who made this".
     */
    private function manifestAuthor(array $manifest): ?string
    {
        $author = $manifest['author'] ?? null;
        if (is_string($author) && trim($author) !== '') return mb_substr(trim($author), 0, 190);
        $dev = AppMeta::from($manifest, (string) ($manifest['slug'] ?? ''))['developer'];
        return $dev !== null ? mb_substr($dev['name'], 0, 190) : null;
    }

    /**
     * Display metadata for every app on disk, keyed by slug — icon, developer,
     * company, description, and anything wrong with them. See AppMeta.
     *
     * @return array<string,array>
     */
    public function metaAll(): array
    {
        $out = [];
        foreach ($this->scan() as $slug => $manifest) {
            $out[$slug] = AppMeta::from($manifest, (string) $slug, (string) ($manifest['_path'] ?? ''));
        }
        return $out;
    }

    /**
     * Installed app versions as CloudHim's item-updates endpoint wants them:
     * slug => version, for every app with files on disk.
     *
     * @return array<string,string>
     */
    public function installedVersions(): array
    {
        $out = [];
        foreach ($this->scan() as $slug => $manifest) {
            $v = trim((string) ($manifest['version'] ?? ''));
            if ($v !== '' && preg_match('/^[0-9A-Za-z.\-+]{1,32}$/', $v)) $out[(string) $slug] = $v;
        }
        return $out;
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
    private function fireUpgrade(string $slug, string $from, string $to): ?\Throwable
    {
        // Within one request an upgrade is announced once, however many paths
        // reach here. Across requests, runPendingUpgrade() holds a lock and
        // re-reads schema_version, so two requests cannot both run it (1.2.73).
        $key = $slug . ':' . $from . ':' . $to;
        if (isset($this->upgradesFired[$key])) return $this->upgradesFired[$key] === true ? null : $this->upgradesFired[$key];
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
            $guard = $this->guard();
            $outer = $guard?->currentApp() !== null;
            if (!$outer) $guard?->begin($slug, 'lifecycle:onUpgrade', $to);
            try {
                $instance = $this->instance($app, $slug);
                if ($instance === null) {
                    // Nothing ran: not a success (1.2.73). The schema version
                    // stays where it is and the upgrade is tried again.
                    unset($this->upgradesFired[$key]);
                    return new \App\Services\AppHealth\AppNotLoaded(
                        "The app's code could not be loaded, so onUpgrade() did not run. Its report says why."
                    );
                }
                if (!method_exists($instance, 'onUpgrade')) return null;
                $instance->onUpgrade($from, $to);
            } finally {
                if (!$outer) $guard?->end();
            }
            return null;
        } catch (\Throwable $e) {
            $this->logSafe("App '{$slug}' onUpgrade({$from}, {$to}) failed: " . $e->getMessage());
            $this->upgradesFired[$key] = $e;
            return $e;
        }
    }

    // ------------------------------------------------------------------
    // App schema version (1.2.73, BH-024)
    // ------------------------------------------------------------------

    /** Whether apps.schema_version exists (migration 015), created on first need. */
    private function hasSchemaColumn(): bool
    {
        if ($this->schemaColumn !== null) return $this->schemaColumn;
        try {
            $col = $this->db->selectOne("SHOW COLUMNS FROM {apps} LIKE 'schema_version'");
            if ($col === null) {
                // The new files can be live before migration 015 has run (a
                // patch uploaded by hand). Add the column here rather than
                // fall back to recording upgrades that never happened.
                $this->db->execute('ALTER TABLE {apps} ADD COLUMN `schema_version` VARCHAR(30) NULL AFTER `version`');
                $this->db->execute('UPDATE {apps} SET `schema_version` = `version` WHERE `schema_version` IS NULL');
            }
            return $this->schemaColumn = true;
        } catch (\Throwable $e) {
            $this->logSafe('apps.schema_version is unavailable: ' . $e->getMessage());
            return $this->schemaColumn = false;
        }
    }

    /**
     * The version an app's data was last migrated to. NULL (a row from
     * before 1.2.73) counts as its recorded version; '' means onInstall() has
     * not completed yet, which activation finishes first.
     */
    private function schemaVersionOf(array $row): string
    {
        $v = $row['schema_version'] ?? null;
        return is_string($v) ? $v : (string) ($row['version'] ?? '');
    }

    /**
     * Bring an app's data up to the version of its files: onUpgrade(from, to),
     * then record the new schema version, only if it returned (1.2.73, BH-024).
     *
     * Until 1.2.73 the new version was written first and a failed onUpgrade()
     * was only logged, so it was never run again and the new code ran against
     * the old tables. Now:
     *   - schema_version moves only after onUpgrade() succeeds;
     *   - a failure is recorded in the app's report, and an active app is
     *     switched off, since its new code expects the new schema. It is tried
     *     again when the app is next activated or its files change;
     *   - a per-app database lock stops two requests running it together.
     *
     * @return string|null null when the data is current, else what went wrong
     */
    public function runPendingUpgrade(string $slug): ?string
    {
        if (!$this->hasSchemaColumn()) return null;
        $row = $this->find($slug);
        if (!$row) return null;
        $to = (string) ($row['version'] ?? '');
        $current = $this->schemaVersionOf($row);
        if ($to === '' || $current === '' || $current === $to) return null;

        $lock = 'basehim_appup_' . substr(md5((defined('BASEHIM_ROOT') ? BASEHIM_ROOT : '') . '|' . $slug), 0, 24);
        try {
            $got = (int) ($this->db->selectOne('SELECT GET_LOCK(:n, 0) AS l', ['n' => $lock])['l'] ?? 0);
        } catch (\Throwable) {
            $got = 0;
        }
        if ($got !== 1) return 'Another request is upgrading this app. Try again in a moment.';

        try {
            $row = $this->find($slug) ?? $row;                // re-read inside the lock
            $from = $this->schemaVersionOf($row);
            $to = (string) ($row['version'] ?? '');
            if ($from === '' || $from === $to) return null;

            $e = $this->fireUpgrade($slug, $from, $to);
            if ($e === null) {
                $this->db->execute('UPDATE {apps} SET schema_version = :v WHERE slug = :s', ['v' => $to, 's' => $slug]);
                return null;
            }
            if (!$e instanceof \App\Services\AppHealth\AppNotLoaded) {
                $this->guard()?->failed($slug, $to, 'lifecycle:onUpgrade', $e);
            }
            return "Its upgrade from v{$from} to v{$to} did not complete: " . $this->cleanMessage($e);
        } finally {
            try { $this->db->selectOne('SELECT RELEASE_LOCK(:n) AS l', ['n' => $lock]); } catch (\Throwable) {}
        }
    }

    /** An exception message fit for the Apps screen. */
    private function cleanMessage(\Throwable $e): string
    {
        $guard = $this->guard();
        $msg = $guard !== null ? $guard->sanitize($e->getMessage()) : $e->getMessage();
        return mb_substr($msg !== '' ? $msg : get_class($e), 0, 300);
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
                $this->forgetCompiled($real);
                $this->rrmdir($real);
                unset($this->dirCache[$slug]);
                return;
            }
        }
    }

    /**
     * Drop OPcache's compiled copy of every PHP file under $dir (1.2.90), so
     * the next request compiles what is on disk now. Used after a fresh
     * install and before a delete; the in-place upgrade resets the whole
     * cache instead.
     */
    private function forgetCompiled(string $dir): void
    {
        if (!function_exists('opcache_invalidate') || !is_dir($dir)) return;
        try {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
                    @opcache_invalidate($file->getPathname(), true);
                }
            }
        } catch (\Throwable) {
            if (function_exists('opcache_reset')) @opcache_reset();
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
            $p['meta'] = AppMeta::fromHub($p);
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
    public function marketplaceInstall(string $slug, string $expectedSha256 = '', string $version = ''): array
    {
        $conn = $this->cloudhimConn();
        if (!$conn) return ['ok' => false, 'error' => 'This site is not connected to the Basehim marketplace yet — open Updates to connect.'];
        $slug = $this->sanitizeSlug($slug);

        // Pin the download to a specific version when one is given — the Updates
        // page passes the version its (possibly cached) update entry names,
        // along with that version's checksum. Without the pin the hub serves
        // whatever is newest now, so once a second update was published the
        // bytes stopped matching the cached checksum and every update failed
        // with "Checksum mismatch", leaving the app stuck on its old version.
        // With the pin the file, its checksum and the hub's own header all
        // describe the same version. The hub serves the latest when no version
        // is sent, so the marketplace "Install" button (no version) is unchanged.
        $query = ['key' => $conn['key'], 'slug' => $slug];
        $version = trim($version);
        if ($version !== '' && preg_match('/^[0-9A-Za-z.\-+]{1,32}$/', $version)) {
            $query['version'] = $version;
        }
        $url = $conn['url'] . '/api/v1/cloudhim/plugin-download?' . http_build_query($query);
        $res = $this->httpGet($url, 120);
        if ($res['error'] !== null || $res['status'] >= 400 || $res['body'] === '') {
            return ['ok' => false, 'error' => 'Download failed: ' . ($res['error'] ?? ('HTTP ' . $res['status']))];
        }

        $tmp = sys_get_temp_dir() . '/bh-app-' . bin2hex(random_bytes(5)) . '.zip';
        file_put_contents($tmp, $res['body']);

        // The file must match the checksum sent with the download AND, when
        // one was offered, the checksum the update list announced for this
        // version — a redirect cannot swap in a different file with a header
        // to match it.
        $actual = hash_file('sha256', $tmp);
        $bad = false;
        foreach ([(string) ($res['headers']['x-checksum-sha256'] ?? ''), $expectedSha256] as $expected) {
            $expected = strtolower(trim($expected));
            if ($expected !== '' && !hash_equals($expected, $actual)) $bad = true;
        }
        if ($bad) {
            @unlink($tmp);
            return ['ok' => false, 'error' => 'Checksum mismatch — download may be corrupted. Nothing was installed.'];
        }

        try {
            // Installed (a row) → replace its files, which also restores an
            // app whose folder went missing. Otherwise a fresh install, which
            // refuses to overwrite a folder that isn't an installed app.
            if ($this->find($slug)) {
                $result = $this->replaceAppFiles($tmp, $slug); // update in place
            } else {
                $result = $this->installFromZip($tmp, 'marketplace');
            }
            @unlink($tmp);
            $this->forgetCachedUpdate($slug);
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

        // The new files sit at the old paths, so PHP's opcode cache would go
        // on serving the previous version's code until it next revalidates —
        // a minute on many hosts — while the new manifest is already read.
        // Clearing it makes the next request run the version just installed.
        if (function_exists('opcache_reset')) { @opcache_reset(); }

        // Validate the new files first (1.2.71): an active app that no longer
        // loads is switched off now, before sync() runs its upgrade code and
        // before the next request includes it.
        $this->forgetInstance($expectedSlug);
        try { $this->validate($expectedSlug); } catch (\Throwable) {}

        // Refresh the DB row's version/manifest without disturbing active state,
        // and scan the new files (1.2.85; updates weren't scanned before).
        $this->sync();
        $this->scanApp($expectedSlug);
        return ['slug' => $expectedSlug, 'manifest' => $staged['manifest'], 'replaced' => true];
    }

    /** Drop an app from the Updates page's cached list (it is current, or gone). */
    private function forgetCachedUpdate(string $slug): void
    {
        try {
            Application::getInstance()->make(\App\Services\UpdateService::class)->forgetAppUpdate($slug);
        } catch (\Throwable) {}
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
            CURLOPT_HEADER         => true,
        ] + \App\Core\Tls::curlOptions());
        $raw = \App\Core\Tls::exec($ch);
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
