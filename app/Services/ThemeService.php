<?php
declare(strict_types=1);

namespace App\Services;

class ThemeService
{
    public function __construct(
        private SettingService $settings,
        private string $themePath
    ) {}

    /**
     * Load widgets defined by the active theme. A theme may ship a
     * `widgets.php` at its root that RETURNS an array:
     *   return [
     *     'featured-posts' => [
     *        'title' => 'Featured Posts',
     *        'icon'  => 'fa-star',
     *        'surfaces' => ['frontend','editor'],
     *        'render' => fn(array $s, string $surface) => '...html...',
     *     ],
     *   ];
     * Keys are auto-namespaced to 'theme.{slug}.{key}'.
     */
    public function bootWidgets(\App\Core\Application $app): void
    {
        $slug = $this->activeSlug();
        $file = $this->themePath . '/' . $slug . '/widgets.php';
        if (!is_file($file)) return;

        $defs = require $file;
        if (!is_array($defs)) return;

        /** @var \App\Core\WidgetRegistry $registry */
        $registry = $app->make(\App\Core\WidgetRegistry::class);
        foreach ($defs as $key => $def) {
            if (!is_array($def)) continue;
            $fullKey = str_contains((string) $key, '.') ? (string) $key : 'theme.' . $slug . '.' . $key;
            $def['source'] = 'theme:' . $slug;
            $registry->register($fullKey, $def);
        }
    }

    public function activeSlug(): string
    {
        return (string)($this->settings->get('appearance', 'active_theme') ?: 'default');
    }

    /**
     * Register the widget areas the active theme declares in its theme.json:
     *   "widget_areas": {
     *     "sidebar":  { "name": "Sidebar", "description": "Main sidebar" },
     *     "footer-1": { "name": "Footer Column 1" }
     *   }
     * Each value may also carry before_widget/after_widget/before_title/
     * after_title/class wrappers (see WidgetAreaRegistry). A bare string value is
     * treated as the area's display name.
     */
    public function bootAreas(\App\Core\Application $app): void
    {
        $manifest = $this->activeManifest();
        $areas = $manifest['widget_areas'] ?? null;
        if (!is_array($areas)) return;

        /** @var \App\Core\WidgetAreaRegistry $registry */
        $registry = $app->make(\App\Core\WidgetAreaRegistry::class);
        $slug = $this->activeSlug();
        foreach ($areas as $key => $def) {
            if (is_string($def)) $def = ['name' => $def];
            if (!is_array($def)) continue;
            $def['source'] = 'theme:' . $slug;
            $registry->register((string) $key, $def);
        }
    }

    public function activePath(): string
    {
        return $this->themePath . '/' . $this->activeSlug();
    }

    public function activeManifest(): array
    {
        $file = $this->activePath() . '/theme.json';
        if (!is_file($file)) {
            return ['name' => 'Default', 'version' => '1.0.0'];
        }
        $data = json_decode(file_get_contents($file), true);
        return is_array($data) ? $data : [];
    }

    public function scan(): array
    {
        $themes = [];
        if (!is_dir($this->themePath)) return $themes;
        foreach (scandir($this->themePath) as $dir) {
            if ($dir === '.' || $dir === '..' || str_starts_with($dir, '.')) continue;
            $manifest = $this->themePath . '/' . $dir . '/theme.json';
            if (!is_file($manifest)) continue;
            $data = json_decode(file_get_contents($manifest), true);
            if (!is_array($data)) continue;
            $data['_slug'] = $dir;
            // Auto-detect a screenshot for the theme card when the manifest
            // doesn't point at one (screenshot.png/jpg in the theme root).
            if (empty($data['preview'])) {
                $base = defined('BASEHIM_BASE') ? BASEHIM_BASE : '';
                foreach (['screenshot.png', 'screenshot.jpg', 'screenshot.webp'] as $shot) {
                    if (is_file($this->themePath . '/' . $dir . '/' . $shot)) {
                        $data['preview'] = $base . '/content/themes/' . rawurlencode($dir) . '/' . $shot;
                        break;
                    }
                }
            }
            $themes[$dir] = $data;
        }
        return $themes;
    }

    // ==================================================================
    // ===== Installation (upload a theme zip, like apps) ===========
    // ==================================================================

    /** Read a theme ZIP's manifest without extracting (slug/version peek). */
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
            if (preg_match('#^(?:[^/]+/)?theme\.json$#', $name)) {
                $raw = $zip->getFromIndex($i);
                break;
            }
        }
        $zip->close();
        if ($raw === false) {
            throw new \RuntimeException('theme.json not found in the archive root or its first folder.');
        }
        $manifest = json_decode((string) $raw, true);
        if (!is_array($manifest)) {
            throw new \RuntimeException('theme.json is not valid JSON.');
        }
        $this->validateManifest($manifest);
        $manifest['slug'] = $this->sanitizeSlug((string) ($manifest['slug'] ?? $manifest['name']));
        return $manifest;
    }

    /**
     * Install a theme from an uploaded ZIP into content/themes/{slug}.
     * With $overwrite, an already-installed theme of the same slug is
     * replaced in a swap (old moved aside first, restored on failure) —
     * that's the theme "update" path; themes have no DB state to migrate.
     */
    public function installFromZip(string $zipPath, bool $overwrite = false): array
    {
        $staged = $this->stageZip($zipPath);
        $slug = $staged['slug'];
        $target = $this->themePath . '/' . $slug;

        if (is_dir($target)) {
            if (!$overwrite) {
                $this->rrmdir($staged['tempDir']);
                throw new \RuntimeException("Theme '{$slug}' is already installed. Tick \"Replace existing\" to update it with this zip.");
            }
            $aside = $this->themePath . '/.old_' . bin2hex(random_bytes(5));
            if (!@rename($target, $aside)) {
                $this->rrmdir($staged['tempDir']);
                throw new \RuntimeException('Could not move the existing theme aside (file permissions?).');
            }
            if (!@rename($staged['tempDir'], $target)) {
                @rename($aside, $target); // restore the original
                $this->rrmdir($staged['tempDir']);
                throw new \RuntimeException('Could not move the new theme into place; the existing theme was left untouched.');
            }
            $this->rrmdir($aside);
            return ['slug' => $slug, 'manifest' => $staged['manifest'], 'replaced' => true];
        }

        if (!@rename($staged['tempDir'], $target)) {
            $this->rrmdir($staged['tempDir']);
            throw new \RuntimeException('Could not move the extracted theme into place.');
        }
        return ['slug' => $slug, 'manifest' => $staged['manifest'], 'replaced' => false];
    }

    /** Delete an installed theme's files. The active theme and the bundled
     *  'default' fallback are protected. */
    public function delete(string $slug): void
    {
        $slug = $this->sanitizeSlug($slug);
        if ($slug === 'default') {
            throw new \RuntimeException("The bundled 'default' theme can't be deleted — it's the fallback.");
        }
        if ($slug === $this->activeSlug()) {
            throw new \RuntimeException('That theme is currently active. Activate another theme first, then delete it.');
        }
        $dir = $this->themePath . '/' . $slug;
        if (!is_dir($dir)) {
            throw new \RuntimeException('Theme not found.');
        }
        $this->rrmdir($dir);
    }

    /** Extract a theme zip to a temp dir inside content/themes (zip-slip
     *  safe, single wrapper folder stripped), validating it looks like a
     *  real Basehim theme (manifest + at least one template). */
    private function stageZip(string $zipPath): array
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('PHP ZipArchive extension is not available on this server.');
        }
        if (!is_file($zipPath)) {
            throw new \RuntimeException('Theme upload not found on disk.');
        }

        $zip = new \ZipArchive();
        $opened = $zip->open($zipPath);
        if ($opened !== true) {
            throw new \RuntimeException('Could not open uploaded ZIP (error code ' . (int) $opened . ').');
        }

        // -- 1. locate theme.json + scan for unsafe paths -------------------
        $manifestIndex = false;
        $manifestPath = null;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i) ?: '';
            if (str_contains($name, '..') || str_starts_with($name, '/') || str_contains($name, "\0")) {
                $zip->close();
                throw new \RuntimeException('Archive contains an unsafe path; refusing to extract.');
            }
            if ($manifestIndex === false && preg_match('#^(?:[^/]+/)?theme\.json$#', $name)) {
                $manifestIndex = $i;
                $manifestPath = $name;
            }
        }
        if ($manifestIndex === false) {
            $zip->close();
            throw new \RuntimeException('theme.json not found in the archive root or its first folder — this does not look like a Basehim theme.');
        }

        // -- 2. read & validate manifest ------------------------------------
        $raw = $zip->getFromIndex($manifestIndex);
        if ($raw === false) {
            $zip->close();
            throw new \RuntimeException('Could not read theme.json from the archive.');
        }
        $manifest = json_decode((string) $raw, true);
        if (!is_array($manifest)) {
            $zip->close();
            throw new \RuntimeException('theme.json is not valid JSON.');
        }
        $this->validateManifest($manifest);
        $slug = $this->sanitizeSlug((string) ($manifest['slug'] ?? $manifest['name']));

        $topFolder = '';
        if (str_contains((string) $manifestPath, '/')) {
            $topFolder = strstr((string) $manifestPath, '/', true);
        }

        // A theme must actually contain templates.
        $prefix = $topFolder !== '' ? $topFolder . '/' : '';
        $hasTemplate = false;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i) ?: '';
            if (str_starts_with($name, $prefix . 'templates/') && str_ends_with($name, '.php')) {
                $hasTemplate = true;
                break;
            }
        }
        if (!$hasTemplate) {
            $zip->close();
            throw new \RuntimeException('The archive has no templates/*.php files — this does not look like a Basehim theme.');
        }

        // -- 3. extract to temp ----------------------------------------------
        if (!is_dir($this->themePath) && !@mkdir($this->themePath, 0755, true) && !is_dir($this->themePath)) {
            $zip->close();
            throw new \RuntimeException('Cannot create the themes directory: ' . $this->themePath);
        }
        $tempDir = $this->themePath . '/.install_' . bin2hex(random_bytes(6));
        if (!@mkdir($tempDir, 0755, true)) {
            $zip->close();
            throw new \RuntimeException('Could not create a temp directory for extraction.');
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

            if (!is_file($tempDir . '/theme.json')) {
                throw new \RuntimeException('Extracted archive is missing theme.json at its root.');
            }
        } catch (\Throwable $e) {
            $this->rrmdir($tempDir);
            if (isset($zip) && $zip instanceof \ZipArchive) {
                @$zip->close();
            }
            throw $e;
        }

        return ['slug' => $slug, 'manifest' => $manifest, 'tempDir' => $tempDir];
    }

    private function validateManifest(array $manifest): void
    {
        if (empty($manifest['name']) || !is_string($manifest['name'])) {
            throw new \RuntimeException('theme.json must declare a "name".');
        }
    }

    // ==================================================================
    // ===== Marketplace client (browse + install from CloudHim) =======
    // ==================================================================

    /**
     * Browse the CloudHim theme marketplace. Reuses the site's existing
     * CloudHim connection (URL + site key) from the updates settings, so no
     * extra configuration is needed — if the site is connected for updates,
     * the marketplace just works.
     *
     * @return array{ok:bool, themes?:array, meta?:array, error?:string}
     */
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

        $res = $this->httpGet($conn['url'] . '/api/v1/cloudhim/themes?' . $qs);
        if ($res['error'] !== null) return ['ok' => false, 'error' => 'Could not reach the Basehim marketplace: ' . $res['error']];
        $json = json_decode($res['body'], true);
        if (!is_array($json) || empty($json['ok'])) {
            return ['ok' => false, 'error' => (string) ($json['error'] ?? ('The Basehim marketplace returned HTTP ' . $res['status']))];
        }
        // Flag which are already installed locally.
        $installed = $this->scan();
        foreach ($json['themes'] as &$t) {
            $t['installed'] = isset($installed[$t['slug']]);
            $t['installed_version'] = $installed[$t['slug']]['version'] ?? null;
        }
        unset($t);
        return $json;
    }

    /** Categories + popular tags for the marketplace filter rail. */
    public function marketplaceFacets(): array
    {
        $conn = $this->cloudhimConn();
        if (!$conn) return ['ok' => false, 'error' => 'not_connected'];
        $res = $this->httpGet($conn['url'] . '/api/v1/cloudhim/themes/facets?key=' . urlencode($conn['key']));
        $json = json_decode($res['body'], true);
        return is_array($json) ? $json : ['ok' => false, 'categories' => [], 'tags' => []];
    }

    /**
     * Download a theme from CloudHim by slug and install it (SHA-256 verified
     * against the header CloudHim sends), reusing the local zip installer.
     */
    public function marketplaceInstall(string $slug): array
    {
        $conn = $this->cloudhimConn();
        if (!$conn) return ['ok' => false, 'error' => 'This site is not connected to the Basehim marketplace yet — open Updates to connect.'];
        $slug = $this->sanitizeSlug($slug);

        $url = $conn['url'] . '/api/v1/cloudhim/theme-download?' . http_build_query(['key' => $conn['key'], 'slug' => $slug]);
        $res = $this->httpGet($url, 120);
        if ($res['error'] !== null || $res['status'] >= 400 || $res['body'] === '') {
            return ['ok' => false, 'error' => 'Download failed: ' . ($res['error'] ?? ('HTTP ' . $res['status']))];
        }

        $tmp = sys_get_temp_dir() . '/bh-theme-' . bin2hex(random_bytes(5)) . '.zip';
        file_put_contents($tmp, $res['body']);

        // Verify checksum from the response header when present.
        $expected = strtolower(trim((string) ($res['headers']['x-checksum-sha256'] ?? '')));
        if ($expected !== '' && !hash_equals($expected, hash_file('sha256', $tmp))) {
            @unlink($tmp);
            return ['ok' => false, 'error' => 'Checksum mismatch — download may be corrupted. Nothing was installed.'];
        }

        try {
            $already = isset($this->scan()[$slug]);
            $result = $this->installFromZip($tmp, $already); // overwrite if updating
            @unlink($tmp);
            return ['ok' => true] + $result;
        } catch (\Throwable $e) {
            @unlink($tmp);
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /** Pull the CloudHim connection the updater already stores. */
    private function cloudhimConn(): ?array
    {
        try {
            $update = \App\Core\Application::getInstance()->make(\App\Services\UpdateService::class);
            if (!$update->isConfigured()) {
                // Try to self-register once (same automatic flow as updates).
                if (!$update->ensureConnected()) return null;
            }
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
            // Verified TLS: this call downloads code that is extracted into
            // content/, so an intercepted response is arbitrary code on the
            // server. The x-checksum-sha256 header cannot help — it travels on
            // the same connection an attacker would control.
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
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
            $headerBlob = substr($raw, 0, $headerSize);
            $body = substr($raw, $headerSize);
            foreach (explode("\r\n", $headerBlob) as $line) {
                if (str_contains($line, ':')) {
                    [$k, $v] = explode(':', $line, 2);
                    $headers[strtolower(trim($k))] = trim($v);
                }
            }
        }
        return ['body' => $body, 'status' => $status, 'error' => $err, 'headers' => $headers];
    }

    private function sanitizeSlug(string $slug): string
    {
        $slug = strtolower(preg_replace('/[^a-z0-9\-_]/i', '-', $slug) ?? '');
        $slug = trim($slug, '-_');
        if ($slug === '' || str_starts_with($slug, '.')) {
            throw new \RuntimeException('Theme slug is invalid.');
        }
        return $slug;
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

    public function activate(string $slug): bool
    {
        if (!is_dir($this->themePath . '/' . $slug)) return false;
        $this->settings->set('appearance', 'active_theme', $slug);
        return true;
    }

    /**
     * Resolve a template file within the active theme.
     */
    public function templatePath(string $name): ?string
    {
        $path = $this->activePath() . '/templates/' . $name . '.php';
        return is_file($path) ? $path : null;
    }

    /**
     * Render a theme template with given data. Returns the rendered HTML.
     */
    public function render(string $template, array $data = []): string
    {
        $path = $this->templatePath($template);
        if (!$path) {
            // fallback to 404 if missing
            $path = $this->templatePath('404');
        }
        if (!$path) {
            return '<h1>Template Not Found: ' . htmlspecialchars($template) . '</h1>';
        }

        // Inject install base path for subdirectory installs.
        if (!isset($data['base'])) {
            $data['base'] = defined('BASEHIM_BASE') ? BASEHIM_BASE : '';
        }

        extract($data, EXTR_SKIP);
        $themeService = $this;
        // Closure echoes the partial so templates can use <php> $partial('header'); </php>
        $partial = function (string $name, array $localData = []) use ($themeService, $data) {
            echo $themeService->renderPartial($name, array_merge($data, $localData));
        };

        ob_start();
        try {
            require $path;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        return ob_get_clean();
    }

    public function renderPartial(string $name, array $data = []): string
    {
        $path = $this->activePath() . '/templates/partials/' . $name . '.php';
        if (!is_file($path)) return '';
        if (!isset($data['base'])) {
            $data['base'] = defined('BASEHIM_BASE') ? BASEHIM_BASE : '';
        }
        extract($data, EXTR_SKIP);
        ob_start();
        require $path;
        return ob_get_clean();
    }

    public function assetUrl(string $relative): string
    {
        $base = defined('BASEHIM_BASE') ? BASEHIM_BASE : '';
        $slug = $this->activeSlug();
        return $base . '/content/themes/' . $slug . '/assets/' . ltrim($relative, '/');
    }
}
