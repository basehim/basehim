<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Request;
use App\Core\Response;
use App\Services\CustomizerService;
use App\Http\Controllers\Controller;
use App\Services\ThemeService;

/**
 * The Customizer.
 *
 * A two-pane screen: controls on the left, the live site in an iframe on the
 * right. Changes are applied to the preview immediately and are not saved until
 * the operator says so.
 *
 * ── How the preview stays live ─────────────────────────────────────────────
 *
 * The iframe loads the real front end with `?bh_preview=<token>` appended.
 * Nothing is written to the database for a preview: the pending values travel
 * by postMessage and the front end applies them itself. That matters for two
 * reasons — a preview must never change what a visitor sees, and abandoning a
 * session must leave nothing behind.
 *
 * A colour or a spacing value is applied by setting a CSS custom property, so
 * it lands instantly with no request. Anything structural reloads the frame,
 * because the markup itself is different.
 */
final class CustomizerController extends Controller
{
    /** GET /admin/customize */
    public function index(Request $request): Response
    {
        /** @var CustomizerService $customizer */
        $customizer = $this->app->make(CustomizerService::class);
        /** @var ThemeService $themes */
        $themes = $this->app->make(ThemeService::class);

        $manifest = $themes->activeManifest();

        return $this->view('customizer.index', [
            'sections'    => $customizer->sections(),
            'values'      => $customizer->values(),
            'themeName'   => (string) ($manifest['name'] ?? $themes->activeSlug()),
            'themeSlug'   => $themes->activeSlug(),
            'previewUrl'  => $this->previewUrl(),
            'csrf'        => $this->app->make(\App\Core\Session::class)->csrfToken(),
            'title'       => 'Customize',
        ]);
    }

    /** POST /admin/customize/save */
    public function save(Request $request): Response
    {
        if (!$this->verifyCsrf($request)) {
            return Response::json(['ok' => false, 'error' => 'Security check failed.'], 419);
        }

        $raw = $request->input('values', '');
        $values = is_string($raw) ? json_decode($raw, true) : $raw;
        if (!is_array($values)) {
            return Response::json(['ok' => false, 'error' => 'Nothing to save.']);
        }

        /** @var CustomizerService $customizer */
        $customizer = $this->app->make(CustomizerService::class);
        $result = $customizer->save($values);

        /*
         * Skipped entries are reported rather than silently dropped. A value
         * that fails validation — a colour that is not a colour, a choice not
         * on the list — would otherwise appear to save and then not be there,
         * which is a genuinely confusing thing to debug.
         */
        return Response::json([
            'ok'      => true,
            'saved'   => $result['saved'],
            'skipped' => $result['skipped'],
            'message' => $result['skipped']
                ? sprintf('Saved %d settings. %d could not be saved: %s',
                    $result['saved'], count($result['skipped']), implode(', ', $result['skipped']))
                : 'Saved.',
        ]);
    }

    /**
     * POST /admin/customize/draft
     *
     * Holds the pending values for the preview frame to pick up on its next
     * load. They go in the session, never the settings table: a preview must
     * not change what a visitor sees, and abandoning the screen must leave
     * nothing behind.
     *
     * Needed because a structural change is rendered by PHP — the frame cannot
     * show a different site title or a different layout without the server
     * knowing about it.
     */
    public function draft(Request $request): Response
    {
        if (!$this->verifyCsrf($request)) {
            return Response::json(['ok' => false], 419);
        }

        $raw = $request->input('values', '');
        $values = is_string($raw) ? json_decode($raw, true) : $raw;

        $session = $this->app->make(\App\Core\Session::class);
        $session->set('customizer_draft', is_array($values) ? $values : []);

        return Response::json(['ok' => true]);
    }

    /**
     * The URL the preview iframe loads.
     *
     * The site's own front page, marked as a preview. The marker is a signed
     * token rather than a plain flag: preview mode relaxes caching and accepts
     * injected styles, and neither should be reachable by anyone who simply
     * guesses a query string.
     */
    private function previewUrl(): string
    {
        $base = defined('BASEHIM_BASE') ? BASEHIM_BASE : '';
        return $base . '/?bh_preview=' . $this->previewToken();
    }

    private function previewToken(): string
    {
        /*
         * Derived from the session's CSRF token rather than stored separately.
         * It only has to prove "this browser is the signed-in administrator who
         * opened the Customizer", which is exactly what that token already
         * establishes — and it expires with the session for free.
         */
        $session = $this->app->make(\App\Core\Session::class);
        return substr(hash('sha256', 'bh-preview|' . $session->csrfToken()), 0, 32);
    }
}
