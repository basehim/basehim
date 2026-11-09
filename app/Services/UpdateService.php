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
 *             required and verified, the publisher's signature checked
 *             (PackageSignature, 1.2.103) and the version inside the zip
 *             matched, then extracted over the install with a protected-path
 *             list (config, uploads, storage, .htaccess are never touched)
 *             and zip-slip protection. Migrations run automatically after.
 *
 * Every request goes through hub(): the site key in a header, redirects
 * kept to the hub's own host over https.
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
            'url' => $this->hubUrl(),
            'key' => (string) $this->settings->get('updates', 'cloudhim_key', ''),
            // What the hub lists this site as: the Site title (1.2.101,
            // BH-108). The old `updates.site_name` held the host name from
            // the first registration and is no longer read.
            'site_name' => $this->siteIdentity()['name'],
        ];
    }

    /**
     * The hub this site talks to. Always cloudhim.com, unless UPDATE_HUB_URL
     * in .env names another — for a developer testing a hub of their own.
     * Only the site owner can edit .env, so this is not something a visitor
     * or an app can redirect.
     */
    private function hubUrl(): string
    {
        try {
            $env = trim((string) (\App\Core\Env::get('UPDATE_HUB_URL', '') ?? ''));
            if ($env !== '' && preg_match('#^https?://[^\s/]+(:\d+)?$#i', rtrim($env, '/'))) return rtrim($env, '/');
        } catch (\Throwable) {}
        return self::DEFAULT_HUB;
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
            'url'         => $this->siteUrl(),
            'version'     => BASEHIM_VERSION,
            'php'         => PHP_VERSION,
        ] + $this->siteIdentity();
        $res = $this->hub('POST', '/api/v1/cloudhim/register', [], $payload, 12, false);
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
            // A changed name, tagline, icon or address is sent at once rather
            // than after the hourly wait (1.2.101, BH-108).
            $identityChanged = $this->identityHash() !== (string) $this->settings->get('updates', 'identity_sent', '');
            if (!$identityChanged && $last !== '' && (time() - (int) strtotime($last)) < $successTtl) return false;
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
        // Apps are checked alongside core. Their check failing never fails
        // this one: a hub that cannot answer for apps still delivers core.
        if ($this->isConfigured()) {
            try { $this->checkApps(); } catch (\Throwable) {}
        }
        return $res;
    }

    // ------------------------------------------------------------------
    // App updates (1.2.36)
    // ------------------------------------------------------------------

    /**
     * Ask CloudHim which installed apps have a newer published version.
     *
     * Uses the hub's item-updates endpoint, which takes every installed app
     * as slug@version and answers with the ones that have moved on, each with
     * its version notes. A hub too old to have the endpoint answers 404; that
     * is recorded as "no app updates" rather than an error, so an older hub
     * leaves the Updates page exactly as it was.
     *
     * @return array{ok:bool, apps?:array, error?:string}
     */
    public function checkApps(): array
    {
        if (!$this->isConfigured()) return ['ok' => false, 'error' => 'Not connected to the Basehim update service yet.'];
        $installed = $this->appService()->installedVersions();
        $list = [];
        if ($installed) {
            $pairs = [];
            foreach ($installed as $slug => $v) $pairs[] = $slug . '@' . $v;
            $res = $this->hub('GET', '/api/v1/cloudhim/item-updates', ['apps' => implode(',', $pairs)], null, 20);
            if ($res['error'] !== null) {
                return ['ok' => false, 'error' => 'Could not reach the Basehim update service: ' . $res['error']];
            }
            if ($res['status'] === 404) {
                $this->storeAppUpdates([]);
                return ['ok' => true, 'apps' => [], 'unsupported' => true];
            }
            $json = json_decode($res['body'], true);
            if (!is_array($json) || empty($json['ok'])) {
                return ['ok' => false, 'error' => (string) ($json['error'] ?? ('The update service returned HTTP ' . $res['status']))];
            }
            foreach ((array) ($json['apps'] ?? []) as $u) {
                $slug = (string) ($u['slug'] ?? '');
                $ver  = (string) ($u['version'] ?? '');
                if ($slug === '' || !isset($installed[$slug]) || !version_compare($ver, $installed[$slug], '>')) continue;
                $list[] = $this->cleanAppUpdate($u, $installed[$slug]);
            }
            usort($list, fn($a, $b) => strcasecmp($a['name'], $b['name']));
        }
        $this->storeAppUpdates($list);
        return ['ok' => true, 'apps' => $list];
    }

    /**
     * One hub entry, reduced to what is stored and shown. Everything that
     * arrives from the hub is treated as text; links are kept only when they
     * are plain http(s) addresses.
     */
    private function cleanAppUpdate(array $u, string $installed): array
    {
        $meta = AppMeta::fromHub($u);
        $log = [];
        foreach (array_slice((array) ($u['changelog'] ?? []), 0, 20) as $entry) {
            if (!is_array($entry)) continue;
            $log[] = [
                'version'      => mb_substr((string) ($entry['version'] ?? ''), 0, 32),
                'notes'        => mb_substr((string) ($entry['notes'] ?? ''), 0, 4000),
                'published_at' => mb_substr((string) ($entry['published_at'] ?? ''), 0, 25),
            ];
        }
        return [
            'slug'         => $meta['slug'],
            'name'         => $meta['name'],
            'installed'    => $installed,
            'version'      => $meta['version'],
            'size'         => (int) ($u['size'] ?? 0),
            'sha256'       => preg_match('/^[a-f0-9]{64}$/i', (string) ($u['sha256'] ?? '')) ? strtolower((string) $u['sha256']) : '',
            // The publisher's signature for this version (1.2.103), checked
            // against the downloaded file before anything is installed.
            'signature'    => PackageSignature::clean($u['signature'] ?? ''),
            'notes'        => mb_substr((string) ($u['notes'] ?? ''), 0, 4000),
            'published_at' => mb_substr((string) ($u['published_at'] ?? ''), 0, 25),
            'changelog'    => $log,
            'icon_url'     => $meta['icon']['type'] === 'image' ? $meta['icon']['value'] : null,
            'developer'    => $meta['developer'],
            'company'      => $meta['company'],
            'description'  => $meta['description'],
        ];
    }

    private function storeAppUpdates(array $list): void
    {
        $this->settings->set('updates', 'apps_available', json_encode(array_values($list), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->settings->set('updates', 'apps_available_count', count($list));
        $this->settings->set('updates', 'apps_last_check', date('Y-m-d H:i:s'));
    }

    /**
     * App updates from the last check, minus any already installed since —
     * an app updated from the marketplace or by hand drops off without a
     * fresh check. Each entry carries the app's own display metadata from its
     * installed manifest, which describes it better than the hub can.
     */
    public function cachedAppUpdates(): array
    {
        $list = json_decode((string) $this->settings->get('updates', 'apps_available', '[]'), true);
        if (!is_array($list) || !$list) return [];
        $apps = $this->appService();
        $installed = $apps->installedVersions();
        $meta = $apps->metaAll();
        $out = [];
        foreach ($list as $u) {
            $slug = (string) ($u['slug'] ?? '');
            if (!isset($installed[$slug]) || !version_compare((string) ($u['version'] ?? '0'), $installed[$slug], '>')) continue;
            $u['installed'] = $installed[$slug];
            $m = $meta[$slug] ?? null;
            if ($m) {
                $u['name'] = $m['name'];
                if ($m['icon']['type'] === 'image') $u['icon_url'] = $m['icon']['value'];
                $u['icon'] = $m['icon'];
                $u['initial'] = $m['initial'];
                $u['developer'] = $m['developer'] ?? ($u['developer'] ?? null);
                $u['company'] = $m['company'] ?? ($u['company'] ?? null);
                // The installed app's own words; the hub's when it has none.
                $u['description'] = $m['description'] !== '' ? $m['description'] : (string) ($u['description'] ?? '');
            }
            $u['initial'] = $u['initial'] ?? (mb_strtoupper(mb_substr((string) ($u['name'] ?? $slug), 0, 1)) ?: '?');
            $out[] = $u;
        }
        // Keep the badge honest when something dropped off.
        if (count($out) !== (int) $this->settings->get('updates', 'apps_available_count', 0)) {
            $this->settings->set('updates', 'apps_available_count', count($out));
        }
        return $out;
    }

    /** A pending app update by slug, or null. */
    public function appUpdate(string $slug): ?array
    {
        foreach ($this->cachedAppUpdates() as $u) {
            if ($u['slug'] === $slug) return $u;
        }
        return null;
    }

    /** Drop one app from the cached list after it has been updated. */
    public function forgetAppUpdate(string $slug): void
    {
        $list = json_decode((string) $this->settings->get('updates', 'apps_available', '[]'), true);
        $list = array_values(array_filter(is_array($list) ? $list : [], fn($u) => ($u['slug'] ?? '') !== $slug));
        $this->settings->set('updates', 'apps_available', json_encode($list, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->settings->set('updates', 'apps_available_count', count($list));
    }

    private function appService(): AppService
    {
        return \App\Core\Application::getInstance()->make(AppService::class);
    }

    private function isInvalidKeyError(array $res): bool
    {
        return stripos((string) ($res['error'] ?? ''), 'invalid site key') !== false;
    }

    /**
     * The check itself, which is also the heartbeat: it tells the hub this
     * site's version, PHP, address, name, tagline and icon.
     *
     * @param string|null $version The installed version to report. Right
     *        after an update the BASEHIM_VERSION constant still holds the old
     *        one for the rest of that request, so apply() passes the new one.
     * @return array{ok: bool, updates?: array, error?: string}
     */
    private function doCheck(?string $version = null, int $timeout = 30): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'error' => 'Not connected to the Basehim update service yet.'];
        }
        $version = $version ?? BASEHIM_VERSION;
        $identity = $this->siteIdentity();
        $res = $this->hub('GET', '/api/v1/cloudhim/updates', [
            'version' => $version,
            'php'     => PHP_VERSION,
            'url'     => $this->siteUrl(),
        ] + $identity, null, $timeout);
        if ($res['error'] !== null) {
            return ['ok' => false, 'error' => 'Could not reach the Basehim update service: ' . $res['error']];
        }
        $json = json_decode($res['body'], true);
        if (!is_array($json) || empty($json['ok'])) {
            return ['ok' => false, 'error' => mb_substr((string) ($json['error'] ?? ('The Basehim update service returned HTTP ' . $res['status'])), 0, 300)];
        }

        // Only the fields the updater uses, each checked (1.2.103): before,
        // the hub's entries were cached exactly as they arrived.
        $updates = [];
        foreach ((array) ($json['updates'] ?? []) as $u) {
            if (!is_array($u)) continue;
            $v = (string) ($u['version'] ?? '');
            if (!preg_match('/^\d+\.\d+\.\d+$/', $v) || !version_compare($v, $version, '>')) continue;
            $updates[] = [
                'version'      => $v,
                'notes'        => mb_substr((string) ($u['notes'] ?? ''), 0, 20000),
                'size'         => max(0, (int) ($u['size'] ?? 0)),
                'sha256'       => preg_match('/^[a-f0-9]{64}$/i', (string) ($u['sha256'] ?? '')) ? strtolower((string) $u['sha256']) : '',
                'signature'    => PackageSignature::clean($u['signature'] ?? ''),
                'is_patch'     => !empty($u['is_patch']),
                'published_at' => mb_substr((string) ($u['published_at'] ?? ''), 0, 25),
            ];
        }
        usort($updates, fn($a, $b) => version_compare((string) $b['version'], (string) $a['version']));

        $this->settings->set('updates', 'available', json_encode($updates, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->settings->set('updates', 'available_count', count($updates));
        $this->settings->set('updates', 'last_check', date('Y-m-d H:i:s'));
        $this->settings->set('updates', 'identity_sent', $this->identityHash($identity));

        return ['ok' => true, 'updates' => $updates];
    }

    /**
     * Report to the hub now, best effort: after an update (with the version
     * just installed, BH-107) and after the site's name, tagline or icon
     * changes (BH-108). A short timeout, and never an error: the next
     * regular check sends the same thing.
     */
    public function heartbeat(?string $version = null): bool
    {
        try {
            if (!$this->isConfigured()) return false;
            $ok = (bool) ($this->doCheck($version, 8)['ok'] ?? false);
            if ($ok) {
                $this->settings->set('updates', 'last_auto_sync', date('Y-m-d H:i:s'));
                $this->settings->set('updates', 'last_auto_fail', '');
            }
            return $ok;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Send the heartbeat after the response has gone out, once per request.
     * SettingService calls this when the site title, tagline, favicon or
     * address changes, so the hub shows the change without waiting for the
     * hourly check, and the person saving never waits for the hub.
     */
    public static function heartbeatAfterResponse(): void
    {
        static $armed = false;
        if ($armed || PHP_SAPI === 'cli') return;
        $armed = true;
        register_shutdown_function(static function (): void {
            try {
                if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
                $svc = \App\Core\Application::getInstance()->make(self::class);
                if ($svc->identityHash() !== (string) $svc->settings->get('updates', 'identity_sent', '')) $svc->heartbeat();
            } catch (\Throwable) {}
        });
    }

    /**
     * How this site introduces itself to the hub (1.2.101, BH-108): its Site
     * title (the host name when there is none), tagline and site icon as a
     * full URL. Read fresh on every check, so a change is never stuck.
     *
     * @return array{name:string, tagline:string, icon:string}
     */
    public function siteIdentity(): array
    {
        $name = trim((string) $this->settings->get('general', 'site_title', ''));
        $icon = trim((string) $this->settings->get('appearance', 'favicon_url', ''));
        if ($icon !== '' && !preg_match('#^https?://#i', $icon)) {
            $icon = preg_match('#^//#', $icon)
                ? (\App\Core\Request::serverIsSecure() ? 'https:' : 'http:') . $icon
                : $this->siteOrigin() . \App\Core\Helpers::link($icon);
        }
        return [
            'name'    => mb_substr($name !== '' ? $name : $this->siteHost(), 0, 190),
            'tagline' => mb_substr(trim((string) $this->settings->get('general', 'tagline', '')), 0, 300),
            'icon'    => mb_substr($icon, 0, 500),
        ];
    }

    /** A fingerprint of what the hub was last told about this site. */
    private function identityHash(?array $identity = null): string
    {
        return sha1(json_encode([$identity ?? $this->siteIdentity(), $this->siteUrl()], JSON_UNESCAPED_SLASHES) ?: '');
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

    /**
     * Badge number for the sidebar — core releases plus app updates, from
     * the cache only, never a remote call.
     */
    public function badgeCount(): int
    {
        return count($this->cachedUpdates()) + count($this->cachedAppUpdates());
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
            $rel = self::entryPath($name, $prefix);
            if ($rel === null) continue;
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

    /**
     * Where a zip entry lands, relative to the install root, or null when it
     * must not be written: traversal, an absolute or drive path, or a
     * protected path (1.2.103).
     *
     * The name is normalised first: back-slashes, empty segments and "."
     * segments are dropped, and protected paths are compared without regard
     * to case. Up to 1.2.102 "./.env", "config//app.php" and "./storage/…"
     * passed the protected-path check and were written.
     */
    public static function entryPath(string $name, string $prefix = ''): ?string
    {
        if (str_contains($name, "\0")) return null;
        $name = str_replace('\\', '/', $name);
        if ($prefix !== '' && str_starts_with($name, $prefix)) $name = substr($name, strlen($prefix));
        if (preg_match('#^([a-zA-Z]:|/)#', $name)) return null;
        $parts = [];
        foreach (explode('/', $name) as $seg) {
            if ($seg === '' || $seg === '.') continue;
            if ($seg === '..') return null;
            $parts[] = $seg;
        }
        if (!$parts) return null;
        $rel = implode('/', $parts);
        $lower = strtolower($rel);
        foreach (self::PROTECTED as $p) {
            if ($lower === $p || (str_ends_with($p, '/') && str_starts_with($lower . '/', $p))) return null;
        }
        return $rel;
    }

    /** BASEHIM_VERSION as written in a release zip's index.php, or null. */
    private function versionInsideZip(string $zipPath): ?string
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) return null;
        $src = $zip->getFromName('index.php');
        if ($src === false) {
            $top = explode('/', trim((string) $zip->getNameIndex(0), '/'))[0] ?? '';
            $src = $top !== '' ? $zip->getFromName($top . '/index.php') : false;
        }
        $zip->close();
        return is_string($src) && preg_match("/define\('BASEHIM_VERSION',\s*'([^']+)'\)/", $src, $m) ? $m[1] : null;
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

        // A release without a checksum is refused (1.2.103): up to 1.2.102 a
        // missing one meant the file was installed without any check.
        $expected = strtolower((string) ($target['sha256'] ?? ''));
        if (!preg_match('/^[a-f0-9]{64}$/', $expected)) {
            return ['ok' => false, 'error' => 'The update service did not give a checksum for v' . $version . ', so it was not installed. Check for updates again; if it persists, the release needs to be published again.'];
        }

        // 1) Download to storage, streamed to disk.
        $dir = BASEHIM_ROOT . '/storage/updates';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        // Local scratch filename only — nothing reads it back by name.
        $zipPath = $dir . '/basehim-' . preg_replace('/[^0-9a-zA-Z._-]/', '', $version) . '-' . bin2hex(random_bytes(4)) . '.zip';
        $res = $this->hub('GET', '/api/v1/cloudhim/download', ['version' => $version], null, 300, true, $zipPath);
        if ($res['status'] === 403 && $res['error'] === null) {
            // Stale key mid-install — re-register and retry the download once.
            $this->settings->set('updates', 'cloudhim_key', '');
            if ($this->ensureConnected()) {
                $res = $this->hub('GET', '/api/v1/cloudhim/download', ['version' => $version], null, 300, true, $zipPath);
            }
        }
        if ($res['error'] !== null || $res['status'] !== 200 || !is_file($zipPath) || filesize($zipPath) === 0) {
            @unlink($zipPath);
            return ['ok' => false, 'error' => 'Download failed: ' . ($res['error'] ?? ('HTTP ' . $res['status']))];
        }

        // 2) Verify the checksum, then the publisher's signature (1.2.103).
        if (!hash_equals($expected, (string) hash_file('sha256', $zipPath))) {
            @unlink($zipPath);
            return ['ok' => false, 'error' => 'Checksum mismatch — the downloaded file does not match the release. Aborted, nothing was changed.'];
        }
        $signature = PackageSignature::clean($target['signature'] ?? '') ?: PackageSignature::clean($res['headers']['x-package-signature'] ?? '');
        $sigError = PackageSignature::check($zipPath, 'core', 'basehim', $version, $signature);
        if ($sigError !== null) {
            @unlink($zipPath);
            try { $this->logger()?->error('Update to v' . $version . ' refused: ' . $sigError); } catch (\Throwable) {}
            return ['ok' => false, 'error' => $sigError . ' Nothing was changed.'];
        }
        $inside = $this->versionInsideZip($zipPath);
        if ($inside !== $version) {
            @unlink($zipPath);
            return ['ok' => false, 'error' => sprintf('The package for v%s says it is %s. It was not installed; nothing was changed.',
                $version, $inside !== null ? 'v' . $inside : 'no version at all')];
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

        // Tell the hub now (BH-107): before 1.2.101 it kept listing the old
        // version until the next hourly check. If this fails, the next
        // admin visit checks in, since the wait is cleared.
        if (!$this->heartbeat($newVersion)) {
            $this->settings->set('updates', 'last_auto_sync', '');
        }

        // A migration that failed is reported, never folded into "ok" (1.2.75,
        // CR-05). The files are installed and stay (re-running migrations is
        // safer than reverting code under a changed schema), so ok stays true,
        // but callers must show migration_error and stop applying further
        // updates: a later release's migrations assume this one's ran.
        $migError = $mig['error'] ?? null;
        if ($migError !== null) {
            try { $this->logger()?->error('Update to v' . $newVersion . ': a database migration failed: ' . $migError); } catch (\Throwable) {}
        }
        return [
            'ok'              => true,
            'version'         => $newVersion,
            'is_patch'        => !empty($target['is_patch']),
            'migrations'      => $mig['applied'] ?? [],
            'migration_error' => $migError,
            'remaining'       => count($remaining),
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
            if (str_ends_with($name, '/')) continue;
            // Normalised and checked (zip-slip, protected paths) in one place.
            $rel = self::entryPath($name, $prefix);
            if ($rel === null) continue;

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

    /**
     * Apply pending migrations after an update. The runner itself lives in
     * MigrationService, shared with the System page.
     */
    public function applyPendingMigrations(): array
    {
        return (new MigrationService($this->db))->run();
    }

    private function clearCaches(): void
    {
        // Every entry, sub-folders included (1.2.73, BH-025): the old glob only
        // reached top-level files, and every cached value lives in a
        // sub-folder, so nothing cached survived an update by design but all
        // of it did in practice.
        try {
            (new \App\Core\Cache(BASEHIM_ROOT . '/storage/cache'))->flush();
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

    /**
     * One request to the update hub (1.2.103). Used for core updates and, via
     * AppService and ThemeService, for the marketplaces.
     *
     *  - The site key travels in an X-Site-Key header, not the address, so it
     *    stays out of the hub's access logs. A hub older than CloudHim 1.7.0
     *    only reads ?key=; when such a hub refuses the header, the request is
     *    repeated once with the key in the address.
     *  - Redirects are followed only to the hub's own host (or its www.
     *    twin) and never from https to http. Up to 1.2.102 curl followed up
     *    to five redirects anywhere, carrying the request with it.
     *  - $sink: a file the body is written to as it arrives, so a large
     *    package never sits in memory.
     *
     * @return array{body:string, status:int, error:?string, headers:array<string,string>}
     */
    public function hub(string $method, string $path, array $query = [], ?array $payload = null, int $timeout = 30, bool $auth = true, ?string $sink = null): array
    {
        $c = $this->config();
        $key = $auth ? (string) $c['key'] : '';
        $res = $this->hubOnce($method, $c['url'], $path, $query, $payload, $timeout, $key, false, $sink);
        if ($key !== '' && $res['status'] === 403 && !isset($res['headers']['x-cloudhim-key-header'])) {
            // An older hub that only reads the key from the address.
            $res = $this->hubOnce($method, $c['url'], $path, $query, $payload, $timeout, $key, true, $sink);
        }
        return $res;
    }

    private function hubOnce(string $method, string $hubUrl, string $path, array $query, ?array $payload, int $timeout, string $key, bool $keyInQuery, ?string $sink): array
    {
        if ($keyInQuery && $key !== '') $query = ['key' => $key] + $query;
        $url = rtrim($hubUrl, '/') . '/' . ltrim($path, '/') . ($query ? '?' . http_build_query($query) : '');
        $origin = parse_url($hubUrl);
        $hubHost = strtolower((string) ($origin['host'] ?? ''));
        $hubScheme = strtolower((string) ($origin['scheme'] ?? 'https'));

        for ($hop = 0; $hop <= 3; $hop++) {
            $res = $this->httpRaw($method, $url, $payload, $timeout, ($key !== '' && !$keyInQuery) ? $key : '', $sink);
            if ($res['error'] !== null || !in_array($res['status'], [301, 302, 303, 307, 308], true)) return $res;

            $loc = (string) ($res['headers']['location'] ?? '');
            $next = $loc !== '' && !preg_match('#^[a-z][a-z0-9+.-]*://#i', $loc)
                ? $hubScheme . '://' . $hubHost . (str_starts_with($loc, '/') ? '' : '/') . $loc
                : $loc;
            $p = parse_url($next);
            $host = strtolower((string) ($p['host'] ?? ''));
            $scheme = strtolower((string) ($p['scheme'] ?? ''));
            $sameHost = $host !== '' && preg_replace('/^www\./', '', $host) === preg_replace('/^www\./', '', $hubHost);
            $schemeOk = $scheme === 'https' || ($scheme === 'http' && $hubScheme === 'http');
            if (!$sameHost || !$schemeOk) {
                return ['body' => '', 'status' => $res['status'], 'headers' => $res['headers'],
                        'error' => 'The update service redirected to another address (' . mb_substr($host ?: $loc, 0, 100) . '), which is not followed.'];
            }
            // A 303 always becomes a GET; 301/302 keep the method, as the
            // registration request needs to survive a www rewrite.
            if ($res['status'] === 303) { $method = 'GET'; $payload = null; }
            $url = $next;
        }
        return ['body' => '', 'status' => 0, 'headers' => [], 'error' => 'The update service redirected too many times.'];
    }

    /** A single HTTP exchange, no redirects followed. */
    private function httpRaw(string $method, string $url, ?array $payload, int $timeout, string $siteKey, ?string $sink): array
    {
        $headers = [];
        $out = null;
        if ($sink !== null) {
            $out = @fopen($sink, 'wb');
            if ($out === false) return ['body' => '', 'status' => 0, 'headers' => [], 'error' => 'Could not write the download to ' . basename($sink) . '.'];
        }
        $ch = curl_init($url);
        $send = ['Accept: application/json, application/zip'];
        if ($siteKey !== '') $send[] = 'X-Site-Key: ' . $siteKey;
        $opts = [
            CURLOPT_RETURNTRANSFER => $out === null,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
            CURLOPT_USERAGENT      => 'Basehim-Updater/' . BASEHIM_VERSION,
            CURLOPT_HEADERFUNCTION => static function ($h, string $line) use (&$headers): int {
                if (str_contains($line, ':')) {
                    [$k, $v] = explode(':', $line, 2);
                    $headers[strtolower(trim($k))] = trim($v);
                }
                return strlen($line);
            },
        ];
        if (defined('CURLOPT_PROTOCOLS_STR')) {
            $opts[CURLOPT_PROTOCOLS_STR] = 'https,http';
        } elseif (defined('CURLOPT_PROTOCOLS')) {
            $opts[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS | CURLPROTO_HTTP;
        }
        if ($out !== null) $opts[CURLOPT_FILE] = $out;
        if ($method === 'POST') {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = json_encode($payload ?? [], JSON_UNESCAPED_SLASHES);
            $send[] = 'Content-Type: application/json';
        }
        $opts[CURLOPT_HTTPHEADER] = $send;
        curl_setopt_array($ch, $opts + \App\Core\Tls::curlOptions());
        $body = \App\Core\Tls::exec($ch);
        $err = curl_errno($ch) !== 0 ? curl_error($ch) : null;
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        unset($ch);   // frees the handle; the old explicit close is a no-op since PHP 8.0 and deprecated in 8.5 (BH-106)
        if ($out !== null) fclose($out);
        return ['body' => $out === null ? (string) $body : '', 'status' => $status, 'error' => $err, 'headers' => $headers];
    }

    private function siteUrl(): string
    {
        $scheme = \App\Core\Request::serverIsSecure() ? 'https' : 'http';
        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $base = defined('BASEHIM_BASE') ? (string) BASEHIM_BASE : '';
        return $scheme . '://' . $host . $base;
    }

    private function siteOrigin(): string
    {
        $scheme = \App\Core\Request::serverIsSecure() ? 'https' : 'http';
        return $scheme . '://' . (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    }

    private function siteHost(): string
    {
        return (string) ($_SERVER['HTTP_HOST'] ?? 'this site');
    }

    /** The core logger, when the container can provide it. */
    private function logger(): ?\App\Core\Logger
    {
        try {
            return \App\Core\Application::getInstance()->make(\App\Core\Logger::class);
        } catch (\Throwable) {
            return null;
        }
    }
}
