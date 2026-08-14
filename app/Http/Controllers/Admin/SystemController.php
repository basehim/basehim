<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Http\Controllers\Controller;
use App\Services\SystemInfoService;

/**
 * SystemController — the admin System page (diagnostics + maintenance).
 *
 * Tabs: Overview, PHP & Server, Database, Logs, Cache & Maintenance.
 * All read-only data comes from SystemInfoService; the few state-changing
 * actions (clear cache, delete log, run migrations) are POST + CSRF guarded
 * and require manage_settings (enforced by AdminAreaPolicy on /admin/system).
 */
class SystemController extends Controller
{
    private function svc(): SystemInfoService
    {
        return $this->app->make(SystemInfoService::class);
    }

    /** GET /admin/system — the tabbed page. */
    public function index(Request $request): Response
    {
        $svc = $this->svc();
        $session = $this->app->make(Session::class);

        return $this->view('system.index', [
            'title'       => 'System',
            'currentUser' => $this->user(),
            'overview'    => $svc->overview(),
            'phpInfo'     => $svc->phpInfo(),
            'serverInfo'  => $svc->serverInfo(),
            'extensions'  => $svc->extensions(),
            'opcache'     => $svc->opcacheStatus(),
            'dbInfo'      => $svc->databaseInfo(),
            'tableStats'  => $svc->tableStats(),
            'migrations'  => $svc->migrations(),
            'logFiles'    => $svc->logFiles(),
            'cacheInfo'   => $svc->cacheInfo(),
            'csrf'        => $session->csrfToken(),
        ]);
    }

    /** GET /admin/system/log?name=basehim-YYYY-MM-DD.log — tail a log file. */
    public function viewLog(Request $request): Response
    {
        $name = (string) $request->query('name', '');
        $lines = max(50, min(1000, (int) $request->query('lines', 300)));
        return $this->json($this->svc()->readLog($name, $lines));
    }

    /** POST /admin/system/log/delete — remove a log file. */
    public function deleteLog(Request $request): Response
    {
        if (!$this->verifyCsrf($request)) { $this->flash('error', 'Security check failed.'); return $this->back(); }
        $name = (string) $request->input('name', '');
        $ok = $this->svc()->deleteLog($name);
        \App\Services\ActivityLogService::record($this->userId(), 'system.log_deleted', 'log', null,
            ($ok ? 'Deleted log ' : 'Failed to delete log ') . basename($name));
        $this->flash($ok ? 'success' : 'error', $ok ? 'Log deleted.' : 'Could not delete log.');
        return $this->redirect('/admin/system#logs');
    }

    /** POST /admin/system/cache/clear — clear app cache + OPcache. */
    public function clearCache(Request $request): Response
    {
        if (!$this->verifyCsrf($request)) { $this->flash('error', 'Security check failed.'); return $this->back(); }

        $cleared = 0;
        $cacheDir = BASEHIM_ROOT . '/storage/cache';
        if (is_dir($cacheDir)) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($cacheDir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $f) {
                if ($f->isFile() && $f->getExtension() !== 'gitkeep') { @unlink($f->getPathname()); $cleared++; }
            }
        }

        $opcache = false;
        if (function_exists('opcache_reset')) {
            $opcache = @opcache_reset();
        }

        \App\Services\ActivityLogService::record($this->userId(), 'system.cache_cleared', null, null,
            "Cleared {$cleared} cache file(s)" . ($opcache ? ' + OPcache reset' : ''));
        $this->flash('success', "Cache cleared ({$cleared} file(s))" . ($opcache ? ', OPcache reset.' : '.'));
        return $this->redirect('/admin/system#cache');
    }

    /** POST /admin/system/migrate — apply pending migrations. */
    public function runMigrations(Request $request): Response
    {
        if (!$this->verifyCsrf($request)) { $this->flash('error', 'Security check failed.'); return $this->back(); }

        $result = $this->applyPendingMigrations();
        if ($result['error']) {
            $this->flash('error', 'Migration failed: ' . $result['error']);
        } elseif (empty($result['applied'])) {
            $this->flash('info', 'No pending migrations.');
        } else {
            \App\Services\ActivityLogService::record($this->userId(), 'system.migrations_run', null, null,
                'Applied: ' . implode(', ', $result['applied']));
            $this->flash('success', 'Applied ' . count($result['applied']) . ' migration(s): ' . implode(', ', $result['applied']));
        }
        return $this->redirect('/admin/system#database');
    }

    /**
     * Apply any *.sql migrations not yet recorded in the `migrations` table,
     * in filename order, each inside the shared PDO connection. Mirrors
     * database/migrate.php but runs in-process for the admin button.
     */
    private function applyPendingMigrations(): array
    {
        $applied = [];
        try {
            /** @var \App\Core\Database $db */
            $db = $this->app->make(\App\Core\Database::class);
            $pdo = $db->connection();

            /*
             * This runner talks to PDO directly, so Database::query() — which
             * normally expands {table} tokens — is not in the path. Every
             * statement has to be expanded here, including the contents of each
             * migration file, or MySQL receives the literal string "{migrations}"
             * and fails on a syntax error.
             *
             * UpdateService::applyPendingMigrations() does exactly this. This
             * copy was written without it, so the System page's Run migrations
             * button had never worked.
             */
            $px = fn(string $sql): string => $db->expand($sql);

            $pdo->exec($px(
                'CREATE TABLE IF NOT EXISTS {migrations} (
                    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `migration` VARCHAR(255) NOT NULL,
                    `applied_at` DATETIME NOT NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            ));

            $ran = $pdo->query($px('SELECT migration FROM {migrations}'))->fetchAll(\PDO::FETCH_COLUMN);
            $dir = BASEHIM_ROOT . '/database/migrations';
            $files = glob($dir . '/*.sql') ?: [];
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
}
