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
    public function savePermalinks(Request $request): Response { return $this->saveTab($request, 'permalinks'); }
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
