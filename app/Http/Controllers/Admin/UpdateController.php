<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Request;
use App\Core\Response;
use App\Http\Controllers\Controller;
use App\Services\UpdateService;

/**
 * Admin > Updates — connect this site to a CloudHim server, see available
 * releases (sidebar badge shows the count), and apply them.
 *
 * All mutating actions are POST + CSRF; the whole area sits behind the
 * manage_settings capability (admin-only, like System).
 */
class UpdateController extends Controller
{
    /** GET /admin/updates */
    public function index(Request $request): Response
    {
        /** @var UpdateService $svc */
        $svc = $this->app->make(UpdateService::class);
        // Automatic connection: self-register with CloudHim if no key yet.
        $autoConnected = false;
        if (!$svc->isConfigured()) {
            $autoConnected = $svc->ensureConnected();
            if ($autoConnected) $svc->check();
        }
        return $this->view('updates.index', [
            'title'         => 'Updates',
            'currentUser'   => $this->user(),
            'config'        => $svc->config(),
            'configured'    => $svc->isConfigured(),
            'autoConnected' => $autoConnected,
            'connectError'  => $svc->lastConnectError(),
            'updates'       => $svc->cachedUpdates(),
            'lastCheck'     => $svc->lastCheck(),
            'version'       => BASEHIM_VERSION,
        ]);
    }


    /** POST /admin/updates/check — refresh the available-updates list. */
    public function check(Request $request): Response
    {
        if (!$this->verifyCsrf($request)) { $this->flash('error', 'Security check failed.'); return $this->redirect('/admin/updates'); }
        /** @var UpdateService $svc */
        $svc = $this->app->make(UpdateService::class);
        if (!$svc->ensureConnected()) {
            $this->flash('error', 'Could not connect to the Basehim update service: ' . ($svc->lastConnectError() ?: 'unknown error'));
            return $this->redirect('/admin/updates');
        }
        $res = $svc->check();
        if ($res['ok']) {
            $n = count($res['updates']);
            $this->flash($n > 0 ? 'info' : 'success', $n > 0 ? "{$n} update(s) available." : 'You are running the latest version.');
        } else {
            $this->flash('error', $res['error'] ?? 'Check failed.');
        }
        return $this->redirect('/admin/updates');
    }

    /** POST /admin/updates/apply — download, verify, extract, migrate. */
    /**
     * POST /admin/updates/sync.json
     *
     * Background refresh fired by the dashboard once it has rendered. Returns
     * the (cached) badge count so the sidebar can update without a reload.
     * Throttled server-side, so calling it on every dashboard load is safe.
     */
    public function sync(Request $request): Response
    {
        if (!$this->verifyCsrf($request)) {
            return \App\Core\Response::json(['ok' => false, 'error' => 'Security check failed.'], 419);
        }
        /** @var UpdateService $svc */
        $svc = $this->app->make(UpdateService::class);
        $checked = false;
        try {
            $checked = $svc->autoSync();   // hourly throttle by default
        } catch (\Throwable) {
            // Never surface background failures to the user.
        }
        return \App\Core\Response::json([
            'ok'         => true,
            'checked'    => $checked,       // did a fresh check actually run?
            'count'      => $svc->badgeCount(),
            'last_check' => $svc->lastCheck(),
        ]);
    }

    /**
     * POST /admin/updates/check.json — force a check (ignores the throttle).
     * Returns the pending list already grouped for the UI.
     */
    public function checkJson(Request $request): Response
    {
        if (!$this->verifyCsrf($request)) {
            return \App\Core\Response::json(['ok' => false, 'error' => 'Security check failed.'], 419);
        }
        /** @var UpdateService $svc */
        $svc = $this->app->make(UpdateService::class);
        try {
            $res = $svc->check();
            if (empty($res['ok'])) {
                return \App\Core\Response::json(['ok' => false, 'error' => $res['error'] ?? 'Check failed.']);
            }
        } catch (\Throwable $e) {
            return \App\Core\Response::json(['ok' => false, 'error' => 'Could not reach the update service: ' . $e->getMessage()]);
        }
        return \App\Core\Response::json(['ok' => true] + $this->pendingPayload($svc));
    }

    /**
     * POST /admin/updates/install-step.json
     *
     * Installs exactly ONE pending update (the oldest) and reports what's left.
     * The browser calls this repeatedly, which keeps each request short enough
     * for shared hosting and lets the UI show real progress. Applying in order
     * matters: a patch only contains its own changed files.
     */
    public function installStep(Request $request): Response
    {
        if (!$this->verifyCsrf($request)) {
            return \App\Core\Response::json(['ok' => false, 'error' => 'Security check failed.'], 419);
        }
        /** @var UpdateService $svc */
        $svc = $this->app->make(UpdateService::class);

        $next = $svc->nextPending();
        if ($next === null) {
            return \App\Core\Response::json(['ok' => true, 'done' => true] + $this->pendingPayload($svc));
        }

        try {
            $res = $svc->apply((string) $next['version']);
        } catch (\Throwable $e) {
            return \App\Core\Response::json([
                'ok' => false, 'done' => true,
                'error' => 'Install of v' . $next['version'] . ' failed: ' . $e->getMessage(),
                'failed_version' => $next['version'],
            ] + $this->pendingPayload($svc));
        }

        if (empty($res['ok'])) {
            return \App\Core\Response::json([
                'ok' => false, 'done' => true,
                'error' => $res['error'] ?? 'Install failed.',
                'rolled_back' => (bool) ($res['rolled_back'] ?? false),
                'failed_version' => $next['version'],
            ] + $this->pendingPayload($svc));
        }

        try {
            \App\Services\ActivityLogService::record($this->userId(), 'update.installed', 'system', null,
                'Installed v' . ($res['version'] ?? $next['version']) . (!empty($res['is_patch']) ? ' (patch)' : ''));
        } catch (\Throwable) {}

        $payload = $this->pendingPayload($svc);
        return \App\Core\Response::json([
            'ok'        => true,
            'installed' => $res['version'] ?? $next['version'],
            'is_patch'  => (bool) ($res['is_patch'] ?? false),
            'done'      => ($payload['pending_count'] ?? 0) === 0,
        ] + $payload);
    }

    /** Shared shape: what is still pending, grouped for display. */
    private function pendingPayload(UpdateService $svc): array
    {
        $pending = $svc->pendingInOrder();
        $patches = array_values(array_filter($pending, fn($u) => !empty($u['is_patch'])));
        $full    = array_values(array_filter($pending, fn($u) => empty($u['is_patch'])));
        return [
            'current'       => $svc->installedVersionFromFile() ?: (defined('BASEHIM_VERSION') ? BASEHIM_VERSION : ''),
            'pending'       => $pending,
            'pending_count' => count($pending),
            'patch_count'   => count($patches),
            'full_count'    => count($full),
            'latest'        => $pending ? (string) end($pending)['version'] : null,
            'last_check'    => $svc->lastCheck(),
        ];
    }

    public function apply(Request $request): Response
    {
        if (!$this->verifyCsrf($request)) { $this->flash('error', 'Security check failed.'); return $this->redirect('/admin/updates'); }
        $version = trim((string) $request->input('version', ''));
        if ($version === '') { $this->flash('error', 'No version given.'); return $this->redirect('/admin/updates'); }

        @set_time_limit(0);
        /** @var UpdateService $svc */
        $svc = $this->app->make(UpdateService::class);
        $res = $svc->apply($version);
        if ($res['ok']) {
            \App\Services\ActivityLogService::record($this->userId(), 'system.updated', null, null,
                'Updated Basehim to v' . ($res['version'] ?? $version));
            $msg = 'Updated to v' . ($res['version'] ?? $version) . '.';
            if (!empty($res['migrations'])) $msg .= ' Migrations applied: ' . implode(', ', $res['migrations']) . '.';
            $msg .= ' If anything looks stale, clear OPcache and hard-refresh.';
            $this->flash('success', $msg);
        } else {
            $this->flash('error', $res['error'] ?? 'Update failed.');
        }
        return $this->redirect('/admin/updates');
    }
}
