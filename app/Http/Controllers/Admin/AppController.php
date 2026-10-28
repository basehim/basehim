<?php
declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Http\Controllers\Controller;
use App\Services\AppService;

/**
 * AppController — the /admin/apps screens.
 */
class AppController extends Controller
{
    public function index(Request $request): Response
    {
        /** @var AppService $apps */
        $apps = $this->app->make(AppService::class);
        // Bring installed apps up to date with their files, and switch off any
        // whose folder was deleted or renamed by hand (1.2.85). Folders that
        // were never installed are only counted for the note.
        $apps->sync();
        $apps->reconcile();
        // Apps installed before the scan existed get it now, a few per visit.
        $apps->scanUnscanned(5);

        return $this->view('apps.index', [
            'title'        => 'Apps',
            'currentUser'  => $this->user(),
            'unregistered' => $apps->unregisteredFolders(),
            'installedCount' => count($apps->installed()),
            'csrf'         => $this->app->make(Session::class)->csrfToken(),
            'canUpload'    => class_exists(\ZipArchive::class),
        ]);
    }

    public function activate(Request $request, string $slug): Response
    {
        // The marketplace activates in place and expects JSON; the Apps screen
        // posts a normal form and expects a redirect with a flash.
        $json = $request->wantsJson();

        if (!$this->verifyCsrf($request)) {
            if ($json) return Response::json(['ok' => false, 'error' => 'Security check failed.'], 419);
            $this->flash('error', 'Security check failed.');
            return $this->back();
        }

        try {
            $apps = $this->app->make(AppService::class);

            // An app declaring permissions must be approved first. The JSON
            // caller opens the consent modal; a browser is sent to the consent
            // screen rather than shown a failure it has no way to act on.
            if ($apps->needsConsent($slug)) {
                if ($json) return Response::json(['ok' => false, 'needs_consent' => true]);
                return $this->redirect('/admin/apps/' . urlencode($slug) . '/consent');
            }

            if ($apps->activate($slug)) {
                if ($json) return Response::json(['ok' => true, 'active' => true]);
                $this->flash('success', "App '{$slug}' activated.");
            } else {
                // Requirements are the usual reason, and "could not activate"
                // on its own leaves the operator with nothing to act on.
                $problems = $apps->lastRequirementProblems();
                $msg = $problems
                    ? "Cannot activate '{$slug}': " . implode(' ', $problems)
                    : "Could not activate '{$slug}'.";
                if ($json) return Response::json(['ok' => false, 'error' => $msg]);
                $this->flash('error', $msg);
            }
        } catch (\Throwable $e) {
            if ($json) return Response::json(['ok' => false, 'error' => 'Activation failed: ' . $e->getMessage()]);
            $this->flash('error', "Activation failed: " . $e->getMessage());
        }
        return $this->redirect('/admin/apps');
    }

    public function deactivate(Request $request, string $slug): Response
    {
        if (!$this->verifyCsrf($request)) {
            $this->flash('error', 'Security check failed.');
            return $this->back();
        }

        try {
            $svc = $this->app->make(AppService::class);
            $svc->assertNotNeeded($slug, 'switch off');
            $svc->deactivate($slug);
            $this->flash('success', "App '{$slug}' deactivated.");
        } catch (\Throwable $e) {
            $this->flash('error', "Deactivation failed: " . $e->getMessage());
        }
        return $this->redirect('/admin/apps');
    }

    /**
     * Handle an app ZIP upload.
     */
    public function install(Request $request): Response
    {
        if (!$this->verifyCsrf($request)) {
            $this->flash('error', 'Security check failed.');
            return $this->redirect('/admin/apps');
        }

        $file = $request->file('app_zip');
        if (!$file || ($file['error'] ?? \UPLOAD_ERR_NO_FILE) !== \UPLOAD_ERR_OK) {
            $this->flash('error', $this->uploadErrorMessage($file['error'] ?? \UPLOAD_ERR_NO_FILE));
            return $this->redirect('/admin/apps');
        }

        // Validate extension & MIME loosely (ZIP is widely-misreported by browsers).
        $name = (string)($file['name'] ?? '');
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($ext !== 'zip') {
            $this->flash('error', 'Please upload a .zip file.');
            return $this->redirect('/admin/apps');
        }

        // Cap size at 16 MB to keep shared hosts happy.
        $maxBytes = 16 * 1024 * 1024;
        if (($file['size'] ?? 0) > $maxBytes) {
            $this->flash('error', 'App archive is too large (max 16 MB).');
            return $this->redirect('/admin/apps');
        }

        $tmpName = $file['tmp_name'] ?? '';
        if (!is_uploaded_file($tmpName)) {
            $this->flash('error', 'Upload failed (not a valid uploaded file).');
            return $this->redirect('/admin/apps');
        }

        try {
            $apps = $this->app->make(AppService::class);
            $manifest = $apps->peekZipManifest($tmpName);   // read slug without committing
            $slug = $manifest['slug'] ?? null;

            if ($slug && $apps->find($slug)) {
                // Already installed → stage for upgrade and show a comparison
                // page instead of failing the way it used to.
                $staged = $apps->stageForUpgrade($tmpName);
                $session = $this->app->make(Session::class);
                return $this->view('apps.upgrade', [
                    'title'       => 'Upgrade app',
                    'currentUser' => $this->user(),
                    'staged'      => $staged,
                    'csrf'        => $session->csrfToken(),
                ]);
            }

            $result = $apps->installFromZip($tmpName);
            $this->flash('success', "Installed '{$result['manifest']['name']}'. Activate it below to enable.");
        } catch (\Throwable $e) {
            $this->flash('error', 'Install failed: ' . $e->getMessage());
        }

        return $this->redirect('/admin/apps');
    }

    /**
     * Apply a staged upgrade after the user confirmed the version comparison.
     */
    public function upgradeApply(Request $request): Response
    {
        if (!$this->verifyCsrf($request)) {
            $this->flash('error', 'Security check failed.');
            return $this->redirect('/admin/apps');
        }
        $slug  = (string) $request->input('slug', '');
        $token = (string) $request->input('token', '');
        try {
            $apps = $this->app->make(AppService::class);
            $r = $apps->applyStagedUpgrade($slug, $token);
            if (!empty($r['error'])) {
                // The new files are in place, but the app's own upgrade did
                // not complete (1.2.73): say so, never "upgraded".
                $this->flash('error', "'{$r['slug']}' v{$r['to']} is installed, but its upgrade did not complete: {$r['error']} "
                    . ($r['was_active'] ? 'It was switched off so it cannot run against old data. ' : '')
                    . 'Fix the cause, then activate it to try again.');
                return $this->redirect('/admin/apps');
            }
            $note = $r['was_active'] ? ' Migrations ran and the app was reactivated.' : '';
            $this->flash('success', "Upgraded '{$r['slug']}' from v{$r['from']} to v{$r['to']}.{$note}");
        } catch (\Throwable $e) {
            $this->flash('error', 'Upgrade failed: ' . $e->getMessage());
        }
        return $this->redirect('/admin/apps');
    }

    /**
     * Discard a staged upgrade (user cancelled on the confirm page).
     */
    public function upgradeCancel(Request $request): Response
    {
        if ($this->verifyCsrf($request)) {
            $slug  = (string) $request->input('slug', '');
            $token = (string) $request->input('token', '');
            try { $this->app->make(AppService::class)->discardStagedUpgrade($slug, $token); } catch (\Throwable) {}
        }
        $this->flash('info', 'Upgrade cancelled. No changes were made.');
        return $this->redirect('/admin/apps');
    }

    /**
     * Uninstall an app (drops the DB row; keeps files on disk).
     */
    public function uninstall(Request $request, string $slug): Response
    {
        if (!$this->verifyCsrf($request)) {
            $this->flash('error', 'Security check failed.');
            return $this->redirect('/admin/apps');
        }

        try {
            $this->app->make(AppService::class)->uninstall($slug, deleteFiles: false);
            $this->flash('success', "App '{$slug}' uninstalled (files kept on disk).");
        } catch (\Throwable $e) {
            $this->flash('error', 'Uninstall failed: ' . $e->getMessage());
        }
        return $this->redirect('/admin/apps');
    }

    /**
     * Permanently delete an app (uninstall + remove files from disk).
     */
    public function delete(Request $request, string $slug): Response
    {
        if (!$this->verifyCsrf($request)) {
            $this->flash('error', 'Security check failed.');
            return $this->redirect('/admin/apps');
        }

        try {
            $svc = $this->app->make(AppService::class);
            $svc->assertNotNeeded($slug, 'delete');
            $svc->delete($slug);
            $this->flash('success', "App '{$slug}' deleted.");
        } catch (\Throwable $e) {
            $this->flash('error', 'Delete failed: ' . $e->getMessage());
        }
        return $this->redirect('/admin/apps');
    }

    // ==================================================================
    // Permissions, logs and scanning
    // ==================================================================

    /**
     * GET /admin/apps/{slug}/consent — review what an app is asking for.
     *
     * Shown before first activation, and again whenever an update adds a
     * permission, so an update cannot silently widen what an app can do.
     */
    public function consent(Request $request, string $slug): Response
    {
        /** @var AppService $apps */
        $apps = $this->app->make(AppService::class);
        $row = $apps->find($slug);
        if (!$row) {
            $this->flash('error', "App '{$slug}' is not installed.");
            return $this->redirect('/admin/apps');
        }

        /** @var \App\Services\PermissionBroker $broker */
        $broker = $this->app->make(\App\Services\PermissionBroker::class);
        $session = $this->app->make(Session::class);

        return $this->view('apps.consent', [
            'title'       => 'Approve ' . ($row['name'] ?? $slug),
            'currentUser' => $this->user(),
            'app'         => $row,
            'permissions' => $broker->describeAll($broker->declared($slug)),
            'granted'     => $broker->grantedFor($slug),
            'unknown'     => $broker->unknownDeclared($slug),
            'consented'   => $broker->hasConsented($slug),
            'scan'        => $apps->scanResult($slug),
            'csrf'        => $session->csrfToken(),
        ]);
    }

    /**
     * GET /admin/apps/{slug}/consent.json — the consent screen as data, for the
     * marketplace's in-page "Activate" flow. The browser opens a modal from
     * this and posts the decision back to saveConsent() with Accept: JSON.
     */
    public function consentJson(Request $request, string $slug): Response
    {
        /** @var AppService $apps */
        $apps = $this->app->make(AppService::class);
        $row = $apps->find($slug);
        if (!$row) {
            return Response::json(['ok' => false, 'error' => "App '{$slug}' is not installed."], 404);
        }

        /** @var \App\Services\PermissionBroker $broker */
        $broker = $this->app->make(\App\Services\PermissionBroker::class);
        $declared = $broker->declared($slug);

        return Response::json([
            'ok'            => true,
            'slug'          => $slug,
            'name'          => $row['name'] ?? $slug,
            'needs_consent' => $apps->needsConsent($slug),
            'consented'     => $broker->hasConsented($slug),
            'permissions'   => $broker->describeAll($declared),
            'unknown'       => $broker->unknownDeclared($slug),
            'granted'       => $broker->grantedFor($slug),
        ]);
    }

    /**
     * POST /admin/apps/{slug}/consent — record the decision, then activate.
     *
     * Withholding a permission is allowed: the app runs with less. It may
     * misbehave, which is the operator's call to make, and the denial is
     * written to the app's log so the cause is discoverable.
     */
    public function saveConsent(Request $request, string $slug): Response
    {
        $json = $request->wantsJson();

        if (!$this->verifyCsrf($request)) {
            if ($json) return Response::json(['ok' => false, 'error' => 'Security check failed.'], 419);
            $this->flash('error', 'Security check failed.');
            return $this->redirect('/admin/apps');
        }

        /** @var \App\Services\PermissionBroker $broker */
        $broker = $this->app->make(\App\Services\PermissionBroker::class);
        $granted = (array) $request->input('permissions', []);
        $broker->grant($slug, array_map('strval', $granted));

        /** @var AppService $apps */
        $apps = $this->app->make(AppService::class);

        if ($request->input('activate')) {
            if ($apps->activate($slug)) {
                $withheld = $broker->withheld($slug);
                if ($json) return Response::json(['ok' => true, 'active' => true, 'withheld_count' => count($withheld)]);
                $note = $withheld ? ' ' . count($withheld) . ' permission(s) withheld.' : '';
                $this->flash('success', "App '{$slug}' approved and activated.{$note}");
            } else {
                $problems = $apps->lastRequirementProblems();
                $msg = $problems
                    ? 'Approved, but cannot activate: ' . implode(' ', $problems)
                    : "Approved, but '{$slug}' could not be activated.";
                if ($json) return Response::json(['ok' => false, 'error' => $msg]);
                $this->flash('error', $msg);
            }
        } else {
            if ($json) return Response::json(['ok' => true, 'active' => false]);
            $this->flash('success', "Permissions saved for '{$slug}'.");
        }
        return $this->redirect('/admin/apps');
    }

    /** GET /admin/apps/{slug}/logs — this app's own log file. */
    public function logs(Request $request, string $slug): Response
    {
        /** @var AppService $apps */
        $apps = $this->app->make(AppService::class);
        $row = $apps->find($slug);
        if (!$row) {
            $this->flash('error', "App '{$slug}' is not installed.");
            return $this->redirect('/admin/apps');
        }

        /** @var \App\Services\AppLogger $logger */
        $logger = $this->app->make(\App\Services\AppLogger::class);
        $dates = $logger->availableDates($slug);
        $date = (string) $request->query('date', '');
        if ($date === '' || !in_array($date, $dates, true)) {
            $date = $dates[0] ?? date('Y-m-d');
        }

        return $this->view('apps.logs', [
            'title'       => ($row['name'] ?? $slug) . ' — logs',
            'currentUser' => $this->user(),
            'app'         => $row,
            'lines'       => $logger->tail($slug, 500, $date),
            'dates'       => $dates,
            'date'        => $date,
        ]);
    }

    /**
     * GET /admin/apps/{slug}/health — the app's failure report (1.2.71).
     *
     * Rendered by core, never by the app, so it opens even when the app
     * cannot load. Behind manage_apps (AdminAreaPolicy, /admin/apps).
     */
    public function health(Request $request, string $slug): Response
    {
        /** @var AppService $apps */
        $apps = $this->app->make(AppService::class);
        $row = $apps->find($slug);
        $guard = $apps->guard();
        $record = $guard?->record($slug);
        if (!$row && !$record) {
            $this->flash('error', "App '{$slug}' is not installed.");
            return $this->redirect('/admin/apps');
        }
        $from = (string) $request->query('from', '');
        if ($from !== '' && !preg_match('#^/[A-Za-z0-9/_\-.]{0,200}$#', $from)) $from = '';

        return $this->view('apps.health', [
            'routes'      => $this->appRoutes($slug),
            'title'       => (($row['name'] ?? null) ?: $slug) . ' — report',
            'currentUser' => $this->user(),
            'app'         => $row ?: ['slug' => $slug, 'name' => $slug, 'status' => 'missing', 'version' => $record['version'] ?? ''],
            'record'      => $record,
            'isolation'   => $guard?->preflight()->isolation(),
            'from'        => $from,
            'csrf'        => $this->app->make(Session::class)->csrfToken(),
        ]);
    }

    /**
     * The app's routes as registered in this request (1.2.72): those in use,
     * those refused (and why), and published content a public route hides.
     *
     * @return array{active: array, refused: array, hides: array}
     */
    private function appRoutes(string $slug): array
    {
        $out = ['active' => [], 'refused' => [], 'hides' => []];
        try {
            $router = $this->app->make(\App\Core\Router::class);
            foreach ($router->table()['routes'] as $r) {
                if (($r['owner'] ?? null) !== $slug) continue;
                $out['active'][] = [
                    'methods' => array_values(array_diff($r['methods'], ['HEAD'])) ?: $r['methods'],
                    'pattern' => $r['pattern'], 'area' => $r['area'] ?? '', 'name' => $r['options']['name'] ?? null,
                    'auth' => !empty($r['options']['auth']), 'csrf' => !empty($r['options']['csrf']),
                ];
            }
            foreach ($router->rejected($slug) as $r) {
                $out['refused'][] = ['route' => implode('|', $r['methods']) . ' ' . $r['pattern'], 'reason' => $r['detail']];
            }
            foreach (\App\Core\Routing\SiteRoutes::refused($slug) as $r) {
                $out['refused'][] = ['route' => $r['route'], 'reason' => $r['reason']];
            }
            // A published page or post at /{slug} is hidden by an app route
            // with that first segment (app routes are tried before the page lookup).
            $firsts = [];
            foreach ($out['active'] as $r) {
                if ($r['area'] === 'admin' || !in_array('GET', $r['methods'], true)) continue;
                $seg = explode('/', trim($r['pattern'], '/'))[0] ?? '';
                if ($seg !== '' && $seg[0] !== '{') $firsts[$seg] = '/' . $seg;
            }
            if ($firsts) {
                $db = $this->app->make(\App\Core\Database::class);
                $params = [];
                $in = [];
                foreach (array_keys($firsts) as $i => $seg) { $params['s' . $i] = $seg; $in[] = ':s' . $i; }
                $rows = $db->select("SELECT title, slug, type FROM {posts} WHERE status = 'published' AND deleted_at IS NULL AND slug IN (" . implode(',', $in) . ')', $params);
                foreach ($rows as $row) {
                    $out['hides'][] = ['path' => $firsts[$row['slug']] ?? '/' . $row['slug'], 'title' => $row['title'], 'type' => $row['type']];
                }
            }
        } catch (\Throwable) {}
        return $out;
    }

    /**
     * POST /admin/apps/{slug}/validate — check the app's code again now,
     * without activating it. With "activate=1", activate it if it passes —
     * the recovery path for an app that was switched off.
     */
    public function validate(Request $request, string $slug): Response
    {
        $back = '/admin/apps/' . urlencode($slug) . '/health';
        if (!$this->verifyCsrf($request)) {
            $this->flash('error', 'Security check failed.');
            return $this->redirect($back);
        }
        /** @var AppService $apps */
        $apps = $this->app->make(AppService::class);
        if (!$apps->find($slug)) {
            $this->flash('error', "App '{$slug}' is not installed.");
            return $this->redirect('/admin/apps');
        }

        if ((string) $request->input('activate', '') === '1') {
            if ($apps->needsConsent($slug)) {
                return $this->redirect('/admin/apps/' . urlencode($slug) . '/consent');
            }
            try {
                if ($apps->activate($slug)) {
                    $this->flash('success', "The app passed validation and was activated. If it fails while starting, it is switched off again and this page shows why.");
                } else {
                    $problems = $apps->lastRequirementProblems();
                    $this->flash('error', $problems ? implode(' ', $problems) : 'The app could not be activated.');
                }
            } catch (\Throwable $e) {
                $this->flash('error', 'Activation failed: ' . $e->getMessage());
            }
            return $this->redirect($back);
        }

        $report = $apps->validate($slug);
        if ($report === null) {
            $this->flash('error', 'The app could not be checked: its app.json is missing or unreadable.');
        } elseif ($report['ok']) {
            $this->flash('success', 'The app passed validation' . ($report['isolated'] ? ', including the isolated load test.' : ' (the isolated load test is not available on this server).'));
        } else {
            $this->flash('error', 'The app did not pass validation: ' . count($report['errors']) . ' problem(s). See below.');
        }
        return $this->redirect($back);
    }

    /** POST /admin/apps/{slug}/rescan — re-run the static scanner. */
    public function rescan(Request $request, string $slug): Response
    {
        if (!$this->verifyCsrf($request)) {
            $this->flash('error', 'Security check failed.');
            return $this->redirect('/admin/apps');
        }

        /** @var AppService $apps */
        $apps = $this->app->make(AppService::class);
        $result = $apps->scanApp($slug);

        $high = (int) ($result['high'] ?? 0);
        $medium = (int) ($result['medium'] ?? 0);
        $this->flash(
            $high > 0 ? 'error' : 'success',
            sprintf(
                'Scanned %d file(s) in %s: %d high, %d to review.',
                (int) ($result['files_scanned'] ?? 0), $slug, $high, $medium
            )
        );
        return $this->redirect('/admin/apps/' . urlencode($slug) . '/consent');
    }

    private function uploadErrorMessage(int $code): string
    {
        return match ($code) {
            \UPLOAD_ERR_INI_SIZE, \UPLOAD_ERR_FORM_SIZE => 'The uploaded file exceeds the size limit.',
            \UPLOAD_ERR_PARTIAL    => 'The upload was interrupted. Please try again.',
            \UPLOAD_ERR_NO_FILE    => 'No file was uploaded.',
            \UPLOAD_ERR_NO_TMP_DIR => 'Server is missing a temporary upload directory.',
            \UPLOAD_ERR_CANT_WRITE => 'Server failed to write the uploaded file.',
            \UPLOAD_ERR_EXTENSION  => 'A PHP extension blocked the upload.',
            default                => 'Upload failed.',
        };
    }

    // ==================================================================
    // Marketplace (browse + install apps from CloudHim)
    // ==================================================================

    // The storefront pages these used to render redirect to /admin/marketplace
    // (routes/admin.php); their views were removed in 1.2.84.

    /** GET /admin/apps/marketplace/browse.json */
    public function marketplaceBrowse(Request $request): Response
    {
        $apps = $this->app->make(AppService::class);
        return \App\Core\Response::json($apps->marketplaceBrowse([
            'q'        => (string) $request->input('q', ''),
            'category' => (string) $request->input('category', ''),
            'tag'      => (string) $request->input('tag', ''),
            'sort'     => (string) $request->input('sort', ''),
            'page'     => (string) $request->input('page', ''),
        ]));
    }

    /** GET /admin/apps/marketplace/facets.json */
    public function marketplaceFacets(Request $request): Response
    {
        $apps = $this->app->make(AppService::class);
        return \App\Core\Response::json($apps->marketplaceFacets());
    }

    /** POST /admin/apps/marketplace/install */
    public function marketplaceInstall(Request $request): Response
    {
        if (!$this->verifyCsrf($request)) {
            return \App\Core\Response::json(['ok' => false, 'error' => 'Security check failed.'], 419);
        }
        $slug = (string) $request->input('slug', '');
        $apps = $this->app->make(AppService::class);
        $result = $apps->marketplaceInstall($slug);
        if (!empty($result['ok'])) {
            \App\Services\ActivityLogService::record($this->userId(), 'app.installed', 'app', null,
                "Installed '{$slug}' from the marketplace");
        }
        return \App\Core\Response::json($result);
    }
}
