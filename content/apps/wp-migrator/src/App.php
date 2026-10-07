<?php
declare(strict_types=1);

namespace Basehim\WpMigrator;

use App\Core\App as BaseApp;
use App\Core\Request;
use App\Core\Response;

/**
 * WordPress Migrator — main app entry.
 *
 * Lifecycle:
 *   onActivate()   - creates app-owned tables (idmap, jobs, redirects)
 *   onUninstall()  - drops them
 *
 * Routes:
 *   GET  /admin/wp-migrator                  - wizard landing
 *   POST /admin/wp-migrator/start            - validate source + persist job
 *   POST /admin/wp-migrator/run              - run one batch, return JSON
 *   POST /admin/wp-migrator/cancel           - abort current job
 *   GET  /admin/wp-migrator/status           - poll JSON status
 *
 * Public-facing:
 *   - Registers a hook to apply 301 redirects (for old WordPress URLs).
 */
class App extends BaseApp
{
    // ------------------------------------------------------------------
    // Lifecycle
    // ------------------------------------------------------------------

    public function onActivate(): void
    {
        // ID mapping table — maps (entity_type, old_wp_id) to new Basehim id.
        // Critical for re-running migrations idempotently.
        $this->schema("
            CREATE TABLE IF NOT EXISTS `app_wpmig_idmap` (
                `id`           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `entity_type`  VARCHAR(32) NOT NULL,
                `old_id`       VARCHAR(64) NOT NULL,
                `new_id`       BIGINT UNSIGNED NOT NULL,
                `created_at`   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY `uq_type_old` (`entity_type`, `old_id`),
                KEY `idx_new` (`entity_type`, `new_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Job state — one row per migration run, holds source config, options,
        // current step, batch cursor, counts.
        $this->schema("
            CREATE TABLE IF NOT EXISTS `app_wpmig_jobs` (
                `id`         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `status`     ENUM('pending','running','completed','failed','cancelled') NOT NULL DEFAULT 'pending',
                `source`     ENUM('wxr','mysql') NOT NULL,
                `config`     JSON NOT NULL,
                `options`    JSON NOT NULL,
                `step`       VARCHAR(32) NOT NULL DEFAULT 'users',
                `cursor`     INT UNSIGNED NOT NULL DEFAULT 0,
                `totals`     JSON NULL,
                `counts`     JSON NULL,
                `log`        LONGTEXT NULL,
                `started_at` TIMESTAMP NULL,
                `finished_at` TIMESTAMP NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Redirects table — old WP URL paths -> new Basehim paths, 301s.
        $this->schema("
            CREATE TABLE IF NOT EXISTS `app_wpmig_redirects` (
                `id`          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `from_path`   VARCHAR(500) NOT NULL,
                `to_path`     VARCHAR(500) NOT NULL,
                `status_code` SMALLINT UNSIGNED NOT NULL DEFAULT 301,
                `hits`        INT UNSIGNED NOT NULL DEFAULT 0,
                `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY `uq_from` (`from_path`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->log('activated');
    }

    public function onUninstall(): void
    {
        try { $this->schema('DROP TABLE IF EXISTS `app_wpmig_redirects`'); } catch (\Throwable) {}
        try { $this->schema('DROP TABLE IF EXISTS `app_wpmig_jobs`'); } catch (\Throwable) {}
        try { $this->schema('DROP TABLE IF EXISTS `app_wpmig_idmap`'); } catch (\Throwable) {}
    }

    // ------------------------------------------------------------------
    // Boot
    // ------------------------------------------------------------------

    public function boot(): void
    {
        // Apply WordPress -> Basehim redirects very early in the request
        // lifecycle. We hook into Basehim's standard 404 path by checking
        // the redirect table when a slug doesn't resolve.
        $this->addFilter('post.content', [$this, 'noop'], 5, 2); // touchpoint

        // Add admin menu item. Core >= 1.20 renders Heroicons (outline);
        // legacy `fa-*` names are still accepted and mapped automatically.
        $this->addAdminMenu([
            'url'   => '/admin/wp-migrator',
            'label' => 'WP Migrator',
            'icon'  => 'arrow-down-on-square',
        ]);

        // Admin routes (auth-protected).
        $this->adminGet('/wp-migrator',              [$this, 'showWizard']);
        $this->adminPost('/wp-migrator/upload/init',  [$this, 'uploadInit']);
        $this->adminPost('/wp-migrator/upload/chunk', [$this, 'uploadChunk']);
        $this->adminPost('/wp-migrator/start',   [$this, 'startJob']);
        $this->adminPost('/wp-migrator/run',     [$this, 'runBatch']);
        $this->adminGet('/wp-migrator/status',   [$this, 'jobStatus']);
        $this->adminPost('/wp-migrator/cancel',  [$this, 'cancelJob']);
        $this->adminPost('/wp-migrator/reset',   [$this, 'resetAll']);
        $this->adminPost('/wp-migrator/repair',  [$this, 'repairLinks']);

        // 301 redirect dispatch — runs before catch-all routes resolve.
        $this->registerRedirectMiddleware();
    }

    /** Filter no-op (keeps the touchpoint for future content rewriting). */
    public function noop(string $content, ?array $post = null): string
    {
        return $content;
    }

    // ------------------------------------------------------------------
    // Redirect handling
    //
    // We can't easily hook into the router's miss path, so we attach a
    // global pre-request check that runs on every page load. If the
    // current path matches an entry in app_wpmig_redirects, send a 301
    // immediately. App assets and admin routes are skipped.
    // ------------------------------------------------------------------

    private function registerRedirectMiddleware(): void
    {
        $path = $_SERVER['REQUEST_URI'] ?? '';
        $path = parse_url($path, PHP_URL_PATH) ?: '/';

        // Skip admin, API, app-asset paths, and the homepage itself —
        // the homepage is handled by HomeController; a redirect rule for '/'
        // imported from WordPress would hijack it and send users to the old site.
        if ($path === '/' ||
            str_starts_with($path, '/admin') ||
            str_starts_with($path, '/api') ||
            str_starts_with($path, '/uploads') ||
            str_starts_with($path, '/content/apps')) {
            return;
        }

        // An old WordPress upload — an image in a feed, a link from another
        // site, anything the content step could not rewrite: send it to the
        // imported file. Resized copies go to their full image.
        if (stripos($path, '/wp-content/uploads/') === 0) {
            try {
                $mapper = new UrlMapper($this->db(), new IdMap($this->db()), [], '', false, true);
                $to = $mapper->mapPath(rawurldecode(substr($path, strlen('/wp-content/uploads/'))));
                if ($to !== null) {
                    $base = defined('BASEHIM_BASE') ? (string) BASEHIM_BASE : '';
                    header('Location: ' . $base . $to, true, 301);
                    exit;
                }
            } catch (\Throwable) {}
            return;
        }

        try {
            // WordPress addresses end in a slash; visitors often leave it off.
            $norm = static fn(string $p): string => rtrim($p, '/') === '' ? '/' : rtrim($p, '/');
            $alt = str_ends_with($path, '/') ? rtrim($path, '/') : $path . '/';
            $row = $this->db()->selectOne(
                'SELECT to_path, status_code FROM app_wpmig_redirects
                  WHERE from_path IN (:p, :a) ORDER BY from_path = :p2 DESC LIMIT 1',
                ['p' => $path, 'a' => $alt, 'p2' => $path]
            );
            if (!$row) return;

            /*
             * A migration redirect never overrides a live address.
             *
             * Rows are written once, with the permalink structure the site had
             * at the time. Change the structure afterwards and a row can point
             * at a URL that core now redirects straight back — "too many
             * redirects". Under Flat, /my-post/ -> /my-post also matched the
             * post's own address once the trailing slash was made optional
             * (1.3.0) and sent it to itself. So:
             *   1. a request for the current address of published content is
             *      left to core;
             *   2. the stored target is re-resolved to its content's current
             *      address, so an old row still lands in one hop;
             *   3. nothing is ever redirected to the address it came from.
             */
            // Compare without the install's base path: core's addresses omit it.
            $base = defined('BASEHIM_BASE') ? (string) BASEHIM_BASE : '';
            $here = $base !== '' && str_starts_with($path, $base . '/') ? substr($path, strlen($base)) : $path;

            $current = $this->liveAddress($norm($here));
            if ($current !== null && $norm($current) === $norm($here)) return;

            $to = (string) $row['to_path'];
            $target = $this->liveAddress($norm((string) (parse_url($to, PHP_URL_PATH) ?: '/')));
            if ($target !== null) {
                $q = (string) (parse_url($to, PHP_URL_QUERY) ?? '');
                $to = $target . ($q !== '' ? '?' . $q : '');
            }
            if ($norm((string) (parse_url($to, PHP_URL_PATH) ?: '/')) === $norm($here)) return;

            // Bump hit count (best-effort).
            try {
                $this->db()->execute(
                    'UPDATE app_wpmig_redirects SET hits = hits + 1 WHERE from_path IN (:p, :a)',
                    ['p' => $path, 'a' => $alt]
                );
            } catch (\Throwable) {}

            $code = (int)($row['status_code'] ?: 301);
            if ($to !== '' && $to[0] === '/' && $base !== '' && !str_starts_with($to, $base . '/')) $to = $base . $to;

            header('Location: ' . $to, true, $code);
            exit;
        } catch (\Throwable) {
            // Table may not exist yet, or DB is down — fall through.
        }
    }

    /**
     * The current address of the published post or page a path names (by its
     * last segment), as core builds it for the site's permalink setting — or
     * null when the path names no published content.
     */
    private function liveAddress(string $path): ?string
    {
        $seg = array_values(array_filter(explode('/', trim($path, '/')), static fn($x) => $x !== ''));
        if (!$seg) return null;
        $slug = rawurldecode((string) end($seg));
        try {
            $rows = $this->db()->select(
                "SELECT * FROM {posts} WHERE slug = :s AND type IN ('post', 'page')
                  AND status = 'published' AND deleted_at IS NULL",
                ['s' => $slug]
            );
        } catch (\Throwable) {
            return null;
        }
        if (!$rows) return null;
        // /{slug} means the page when a page and a post share a slug (core's order).
        usort($rows, static fn($a, $b) => ($a['type'] === 'page' ? 0 : 1) <=> ($b['type'] === 'page' ? 0 : 1));
        try {
            return $rows[0]['type'] === 'page'
                ? \App\Core\Helpers::pageUrl($rows[0])
                : \App\Core\Helpers::postUrl($rows[0]);
        } catch (\Throwable) {
            return null;
        }
    }

    // ------------------------------------------------------------------
    // Route handlers (delegate to Wizard)
    // ------------------------------------------------------------------

    public function showWizard(Request $request): Response
    {
        return $this->wizard()->render($request);
    }

    public function uploadInit(Request $request): Response
    {
        return $this->safeJson(fn() => $this->wizard()->uploadInit($request));
    }

    public function uploadChunk(Request $request): Response
    {
        return $this->safeJson(fn() => $this->wizard()->uploadChunk($request));
    }

    public function startJob(Request $request): Response
    {
        return $this->safeJson(fn() => $this->wizard()->start($request));
    }

    public function runBatch(Request $request): Response
    {
        return $this->safeJson(fn() => $this->wizard()->run($request));
    }

    public function jobStatus(Request $request): Response
    {
        return $this->safeJson(fn() => $this->wizard()->status($request));
    }

    public function cancelJob(Request $request): Response
    {
        return $this->safeJson(fn() => $this->wizard()->cancel($request));
    }

    public function repairLinks(Request $request): Response
    {
        return $this->safeJson(fn() => $this->wizard()->repair($request));
    }

    public function resetAll(Request $request): Response
    {
        return $this->safeJson(fn() => $this->wizard()->reset($request));
    }

    private function wizard(): Wizard
    {
        return new Wizard($this);
    }

    /**
     * Wrap an action handler so that any uncaught throwable is converted to a
     * JSON error response. Without this wrapper, the Basehim ErrorHandler only
     * returns JSON for /api/ URLs — admin routes get an HTML page which the
     * wizard JS can't parse, causing "Unexpected token '<'" errors instead of
     * useful diagnostics.
     */
    private function safeJson(callable $fn): Response
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            // Log so we have a server-side record.
            try { $this->log('action failed: ' . $e->getMessage(), [
                'file' => $e->getFile(), 'line' => $e->getLine(),
            ], 'error'); } catch (\Throwable) {}

            $debug = filter_var(\App\Core\Env::get('APP_DEBUG', 'false'), FILTER_VALIDATE_BOOLEAN);
            $body = [
                'ok'    => false,
                'error' => $debug
                    ? $e->getMessage() . ' (in ' . basename($e->getFile()) . ':' . $e->getLine() . ')'
                    : $e->getMessage(),
            ];
            $response = new Response(json_encode($body, JSON_UNESCAPED_SLASHES), 500);
            $response->header('Content-Type', 'application/json');
            return $response;
        }
    }

    // ------------------------------------------------------------------
    // Public accessors so internal classes can reach app services.
    // ------------------------------------------------------------------

    public function app() { return $this->app; }
    public function dbPublic() { return $this->db(); }
    public function appPath(): string { return $this->path; }
    public function appSlug(): string { return $this->slug; }
    public function appLog(string $msg, array $ctx = [], string $level = 'info'): void
    {
        $this->log($msg, $ctx, $level);
    }
}
