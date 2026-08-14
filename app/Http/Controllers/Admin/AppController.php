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
        $apps->sync();
        $installed = $apps->installed();
        $available = $apps->scan();
        $session = $this->app->make(Session::class);

        return $this->view('apps.index', [
            'title'       => 'Apps',
            'currentUser' => $this->user(),
            'installed'   => $installed,
            'available'   => $available,
            'csrf'        => $session->csrfToken(),
            'canUpload'   => class_exists(\ZipArchive::class),
        ]);
    }

    public function activate(Request $request, string $slug): Response
    {
        if (!$this->verifyCsrf($request)) {
            $this->flash('error', 'Security check failed.');
            return $this->back();
        }

        try {
            $apps = $this->app->make(AppService::class);

            // An app declaring permissions must be approved first. Send the
            // operator to the consent screen rather than reporting a failure
            // they have no way to act on.
            if ($apps->needsConsent($slug)) {
                return $this->redirect('/admin/apps/' . urlencode($slug) . '/consent');
            }

            if ($apps->activate($slug)) {
                $this->flash('success', "App '{$slug}' activated.");
            } else {
                // Requirements are the usual reason, and "could not activate"
                // on its own leaves the operator with nothing to act on.
                $problems = $apps->lastRequirementProblems();
                $this->flash('error', $problems
                    ? "Cannot activate '{$slug}': " . implode(' ', $problems)
                    : "Could not activate '{$slug}'.");
            }
        } catch (\Throwable $e) {
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
            $this->app->make(AppService::class)->deactivate($slug);
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
            $this->app->make(AppService::class)->delete($slug);
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
     * POST /admin/apps/{slug}/consent — record the decision, then activate.
     *
     * Withholding a permission is allowed: the app runs with less. It may
     * misbehave, which is the operator's call to make, and the denial is
     * written to the app's log so the cause is discoverable.
     */
    public function saveConsent(Request $request, string $slug): Response
    {
        if (!$this->verifyCsrf($request)) {
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
                $note = $withheld ? ' ' . count($withheld) . ' permission(s) withheld.' : '';
                $this->flash('success', "App '{$slug}' approved and activated.{$note}");
            } else {
                $problems = $apps->lastRequirementProblems();
                $this->flash('error', $problems
                    ? 'Approved, but cannot activate: ' . implode(' ', $problems)
                    : "Approved, but '{$slug}' could not be activated.");
            }
        } else {
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

    /** GET /admin/apps/marketplace */
    public function marketplace(Request $request): Response
    {
        return $this->view('apps.marketplace', [
            'title'       => 'App Marketplace',
            'currentUser' => $this->user(),
        ]);
    }

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
