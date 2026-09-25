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
     * Apply pending migrations. The runner lives in MigrationService, shared
     * with the updater; this copy used to be a separate duplicate of it.
     */
    private function applyPendingMigrations(): array
    {
        return $this->app->make(\App\Services\MigrationService::class)->run();
    }

}
