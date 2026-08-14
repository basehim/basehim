<?php
declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Http\Controllers\Controller;
use App\Services\ThemeService;

class ThemeController extends Controller
{
    public function index(Request $request): Response
    {
        /** @var ThemeService $themes */
        $themes = $this->app->make(ThemeService::class);
        $session = $this->app->make(Session::class);

        return $this->view('themes.index', [
            'title' => 'Themes',
            'currentUser' => $this->user(),
            'themes' => $themes->scan(),
            'activeSlug' => $themes->activeSlug(),
            'csrf' => $session->csrfToken(),
        ]);
    }

    public function activate(Request $request, string $slug): Response
    {
        if (!$this->verifyCsrf($request)) { $this->flash('error', 'Security check failed.'); return $this->back(); }
        $ok = $this->app->make(ThemeService::class)->activate($slug);
        $this->flash($ok ? 'success' : 'error', $ok ? "Theme '{$slug}' activated." : 'Theme not found.');
        return $this->redirect('/admin/themes');
    }

    /** POST /admin/themes/install — upload a theme zip (like apps). */
    public function install(Request $request): Response
    {
        if (!$this->verifyCsrf($request)) {
            $this->flash('error', 'Security check failed.');
            return $this->redirect('/admin/themes');
        }

        $file = $request->file('theme_zip');
        if (!$file || ($file['error'] ?? \UPLOAD_ERR_NO_FILE) !== \UPLOAD_ERR_OK) {
            $this->flash('error', $this->uploadErrorMessage($file['error'] ?? \UPLOAD_ERR_NO_FILE));
            return $this->redirect('/admin/themes');
        }

        $name = (string) ($file['name'] ?? '');
        if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'zip') {
            $this->flash('error', 'Please upload a .zip file.');
            return $this->redirect('/admin/themes');
        }

        // Cap size at 16 MB to keep shared hosts happy.
        if (($file['size'] ?? 0) > 16 * 1024 * 1024) {
            $this->flash('error', 'Theme archive is too large (max 16 MB).');
            return $this->redirect('/admin/themes');
        }

        $tmpName = $file['tmp_name'] ?? '';
        if (!is_uploaded_file($tmpName)) {
            $this->flash('error', 'Upload failed (not a valid uploaded file).');
            return $this->redirect('/admin/themes');
        }

        try {
            /** @var ThemeService $themes */
            $themes = $this->app->make(ThemeService::class);
            $overwrite = (bool) $request->input('overwrite', 0);
            $result = $themes->installFromZip($tmpName, $overwrite);
            \App\Services\ActivityLogService::record($this->userId(), 'theme.installed', 'theme', null,
                ($result['replaced'] ? 'Updated' : 'Installed') . " theme '{$result['slug']}' v" . ($result['manifest']['version'] ?? '?'));
            $this->flash('success', ($result['replaced'] ? 'Updated' : 'Installed')
                . " '" . ($result['manifest']['name'] ?? $result['slug']) . "'."
                . ($result['replaced'] ? '' : ' Activate it below to make it your site\'s look.'));
        } catch (\Throwable $e) {
            $this->flash('error', 'Install failed: ' . $e->getMessage());
        }
        return $this->redirect('/admin/themes');
    }

    /** POST /admin/themes/{slug}/delete — remove an inactive theme's files. */
    public function delete(Request $request, string $slug): Response
    {
        if (!$this->verifyCsrf($request)) { $this->flash('error', 'Security check failed.'); return $this->back(); }
        try {
            $this->app->make(ThemeService::class)->delete($slug);
            \App\Services\ActivityLogService::record($this->userId(), 'theme.deleted', 'theme', null, "Deleted theme '{$slug}'");
            $this->flash('success', "Theme '{$slug}' deleted.");
        } catch (\Throwable $e) {
            $this->flash('error', $e->getMessage());
        }
        return $this->redirect('/admin/themes');
    }

    private function uploadErrorMessage(int $code): string
    {
        return match ($code) {
            \UPLOAD_ERR_INI_SIZE, \UPLOAD_ERR_FORM_SIZE => 'The file exceeds the server upload limit.',
            \UPLOAD_ERR_PARTIAL => 'The file was only partially uploaded — try again.',
            \UPLOAD_ERR_NO_FILE => 'Choose a theme .zip to upload.',
            \UPLOAD_ERR_NO_TMP_DIR, \UPLOAD_ERR_CANT_WRITE => 'The server could not store the upload (temp dir/permissions).',
            default => 'Upload failed (error ' . $code . ').',
        };
    }

    // ==================================================================
    // Marketplace (browse + install themes from CloudHim)
    // ==================================================================

    /** GET /admin/themes/marketplace — the storefront page. */
    public function marketplace(Request $request): Response
    {
        return $this->view('themes.marketplace', [
            'title'       => 'Theme Marketplace',
            'currentUser' => $this->user(),
        ]);
    }

    /** GET /admin/themes/marketplace/browse.json?q&category&tag&sort&page */
    public function marketplaceBrowse(Request $request): Response
    {
        /** @var ThemeService $themes */
        $themes = $this->app->make(ThemeService::class);
        $result = $themes->marketplaceBrowse([
            'q'        => (string) $request->input('q', ''),
            'category' => (string) $request->input('category', ''),
            'tag'      => (string) $request->input('tag', ''),
            'sort'     => (string) $request->input('sort', ''),
            'page'     => (string) $request->input('page', ''),
        ]);
        return \App\Core\Response::json($result);
    }

    /** GET /admin/themes/marketplace/facets.json */
    public function marketplaceFacets(Request $request): Response
    {
        /** @var ThemeService $themes */
        $themes = $this->app->make(ThemeService::class);
        return \App\Core\Response::json($themes->marketplaceFacets());
    }

    /** POST /admin/themes/marketplace/install — install one theme by slug. */
    public function marketplaceInstall(Request $request): Response
    {
        if (!$this->verifyCsrf($request)) {
            return \App\Core\Response::json(['ok' => false, 'error' => 'Security check failed.'], 419);
        }
        $slug = (string) $request->input('slug', '');
        /** @var ThemeService $themes */
        $themes = $this->app->make(ThemeService::class);
        $result = $themes->marketplaceInstall($slug);
        if (!empty($result['ok'])) {
            \App\Services\ActivityLogService::record($this->userId(), 'theme.installed', 'theme', null,
                "Installed '{$slug}' from the marketplace");
        }
        return \App\Core\Response::json($result);
    }
}
