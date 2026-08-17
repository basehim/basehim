<?php
declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Http\Controllers\Controller;
use App\Services\SettingService;
use App\Services\ThemeService;

class SettingController extends Controller
{
    public function general(Request $request): Response   { return $this->renderTab('general'); }
    public function reading(Request $request): Response   { return $this->renderTab('reading'); }
    public function writing(Request $request): Response   { return $this->renderTab('writing'); }
    public function discussion(Request $request): Response{ return $this->renderTab('discussion'); }
    public function seo(Request $request): Response       { return $this->renderTab('seo'); }
    public function appearance(Request $request): Response{ return $this->renderTab('appearance'); }
    public function permalinks(Request $request): Response{ return $this->renderTab('permalinks'); }
    public function media(Request $request): Response     { return $this->renderTab('media'); }
    public function email(Request $request): Response     { return $this->renderTab('email'); }
    public function authorization(Request $request): Response { return $this->renderTab('authorization'); }

    /** POST /admin/settings/email — persisted via the generic tab saver. */
    public function saveEmail(Request $request): Response  { return $this->saveTab($request, 'email'); }
    public function saveAuthorization(Request $request): Response { return $this->saveTab($request, 'authorization'); }

    /** POST /admin/settings/email/test — send a test message to the current user. */
    public function testEmail(Request $request): Response
    {
        if (!$this->verifyCsrf($request)) { $this->flash('error', 'Security check failed.'); return $this->back(); }
        $me = $this->user();
        $to = (string) ($me['email'] ?? '');
        if ($to === '') { $this->flash('error', 'Your account has no email address.'); return $this->redirect('/admin/settings/email'); }

        /** @var \App\Services\Mailer $mailer */
        $mailer = $this->app->make(\App\Services\Mailer::class);
        $cfg = $mailer->config();
        $ok = $mailer->sendTemplate(
            $to,
            'Basehim test email',
            'It works!',
            '<p>This is a test email from your Basehim site.</p>'
            . '<p style="font-size:12px;color:#64748b;">Driver: <strong>' . htmlspecialchars($cfg['driver']) . '</strong>'
            . ($cfg['driver'] === 'smtp' ? ' via ' . htmlspecialchars($cfg['smtp_host'] . ':' . $cfg['smtp_port']) : '') . '</p>'
        );
        if ($ok) {
            $this->flash('success', "Test email sent to {$to} — check the inbox (and spam folder).");
        } else {
            $this->flash('error', 'Test email failed: ' . $mailer->lastError());
        }
        return $this->redirect('/admin/settings/email');
    }

    public function saveGeneral(Request $request): Response    { return $this->saveTab($request, 'general'); }
    public function saveReading(Request $request): Response    { return $this->saveTab($request, 'reading'); }
    public function saveWriting(Request $request): Response    { return $this->saveTab($request, 'writing'); }
    public function saveDiscussion(Request $request): Response { return $this->saveTab($request, 'discussion'); }
    public function saveSeo(Request $request): Response        { return $this->saveTab($request, 'seo'); }
    public function saveAppearance(Request $request): Response { return $this->saveTab($request, 'appearance'); }
    /**
     * POST /admin/settings/permalinks
     *
     * Saves the settings, then writes the canonical-URL rules into .htaccess.
     * The settings are stored first and separately: if the file cannot be
     * written — no .htaccess, wrong permissions, nginx — the preference is
     * still recorded and the screen can show the block to paste by hand.
     */
    public function savePermalinks(Request $request): Response
    {
        if (!$this->verifyCsrf($request)) { $this->flash('error', 'Security check failed.'); return $this->back(); }

        /** @var SettingService $settings */
        $settings = $this->app->make(SettingService::class);

        $host = (string) $request->input('canonical_host', 'none');
        if (!in_array($host, ['none', 'www', 'root'], true)) $host = 'none';
        $https = (bool) $request->input('force_https', false);

        $settings->set('permalinks', 'structure', (string) $request->input('structure', 'pretty'));
        $settings->set('permalinks', 'canonical_host', $host);
        $settings->set('permalinks', 'force_https', $https);

        /** @var \App\Services\HtaccessService $ht */
        $ht = $this->app->make(\App\Services\HtaccessService::class);

        // The URL to check afterwards. Redirect rules are exactly the kind of
        // change that can take a site down, so it is fetched before the
        // operator is told everything went well.
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $verify = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? '') . (defined('BASEHIM_BASE') ? BASEHIM_BASE : '') . '/';

        $result = $ht->apply($host, $https, filter_var($verify, FILTER_VALIDATE_URL) ? $verify : null);

        if ($result['ok']) {
            $this->flash('success', 'Permalink settings saved. ' . $result['message']);
        } else {
            // Not an error state for the settings themselves — they saved.
            $this->flash('error', 'Settings saved, but the .htaccess file was not changed: ' . $result['message']);
        }

        return $this->redirect('/admin/settings/permalinks');
    }
    public function saveMedia(Request $request): Response      { return $this->saveTab($request, 'media'); }

    /** POST /admin/settings/media/regenerate — rebuild thumbnails for all images. */
    public function regenerateThumbnails(Request $request): Response
    {
        if (!$this->verifyCsrf($request)) { $this->flash('error', 'Security check failed.'); return $this->back(); }
        @set_time_limit(0);
        /** @var \App\Services\MediaService $media */
        $media = $this->app->make(\App\Services\MediaService::class);
        $r = $media->regenerateAll();
        $this->flash('success', sprintf(
            'Thumbnails regenerated: %d image%s processed (%d variant%s), %d skipped, %d failed.',
            $r['processed'], $r['processed'] === 1 ? '' : 's',
            $r['variants'], $r['variants'] === 1 ? '' : 's',
            $r['skipped'], $r['failed']
        ));
        return $this->redirect('/admin/settings/media');
    }

    private function renderTab(string $tab): Response
    {
        /** @var SettingService $settings */
        $settings = $this->app->make(SettingService::class);
        /** @var ThemeService $themes */
        $themes = $this->app->make(ThemeService::class);
        $session = $this->app->make(Session::class);

        $extra = [];
        if ($tab === 'permalinks') {
            /** @var \App\Services\HtaccessService $ht */
            $ht = $this->app->make(\App\Services\HtaccessService::class);
            $extra['htaccess'] = $ht->status();
            $extra['htaccessBlock'] = $ht->buildBlock(
                (string) $settings->get('permalinks', 'canonical_host', 'none'),
                (bool) $settings->get('permalinks', 'force_https', false)
            );
            $extra['currentHost'] = $_SERVER['HTTP_HOST'] ?? '';
        }
        if ($tab === 'media') {
            /** @var \App\Services\MediaService $media */
            $media = $this->app->make(\App\Services\MediaService::class);
            $extra['media'] = $media->mediaSettings();
            $extra['gdAvailable'] = \extension_loaded('gd');
            $extra['gdWebp'] = \function_exists('imagewebp');
            $extra['mediaCount'] = $media->totalCount();
        }

        return $this->view('settings.' . $tab, array_merge([
            'title' => ucfirst($tab) . ' Settings',
            'currentUser' => $this->user(),
            'tab' => $tab,
            'values' => $settings->getGroup($tab),
            'allThemes' => $themes->scan(),
            'activeTheme' => $themes->activeSlug(),
            'csrf' => $session->csrfToken(),
        ], $extra));
    }

    private function saveTab(Request $request, string $tab): Response
    {
        if (!$this->verifyCsrf($request)) { $this->flash('error', 'Security check failed.'); return $this->back(); }
        /** @var SettingService $settings */
        $settings = $this->app->make(SettingService::class);

        $input = $request->all();
        unset($input['_csrf']);

        foreach ($input as $key => $val) {
            // booleans posted as "1"/"0" or absent
            $settings->set($tab, (string)$key, $val);
        }

        $this->flash('success', ucfirst($tab) . ' settings saved.');
        return $this->redirect("/admin/settings/{$tab}");
    }
}
