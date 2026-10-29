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
    public function general(Request $request): Response
    {
        // /admin/settings is also the phone's Settings home (1.2.82): the sections as a list.
        $home = (bool) preg_match('#/admin/settings/?$#', $request->path());
        return $this->renderTab('general', $home ? ['settingsHome' => true, 'title' => 'Settings'] : []);
    }
    public function reading(Request $request): Response   { return $this->renderTab('reading'); }
    public function writing(Request $request): Response   { return $this->renderTab('writing'); }
    public function discussion(Request $request): Response{ return $this->renderTab('discussion'); }
    public function seo(Request $request): Response       { return $this->renderTab('seo'); }
    public function ai(Request $request): Response        { return $this->renderTab('ai'); }
    public function appearance(Request $request): Response{ return $this->renderTab('appearance'); }
    public function permalinks(Request $request): Response{ return $this->renderTab('permalinks'); }
    public function media(Request $request): Response     { return $this->renderTab('media'); }
    public function email(Request $request): Response     { return $this->renderTab('email'); }
    public function authorization(Request $request): Response { return $this->renderTab('authorization'); }
    public function security(Request $request): Response { return $this->renderTab('security'); }
    public function search(Request $request): Response { return $this->renderTab('search'); }

    /**
     * POST /admin/settings/media/regenerate-batch — one batch of thumbnail
     * regeneration, as JSON. The media settings screen calls it repeatedly,
     * passing back `next`, until `done`; each call stops after a few seconds,
     * so no call can outlast the web server's time limit.
     */
    public function regenerateThumbnailsBatch(Request $request): Response
    {
        if (!$this->verifyCsrf($request)) return $this->json(['error' => 'Security check failed. Reload the page and try again.'], 419);
        if (!\extension_loaded('gd')) return $this->json(['error' => 'The GD image extension is not available on this server.'], 422);
        @set_time_limit(60);
        /** @var \App\Services\MediaService $media */
        $media = $this->app->make(\App\Services\MediaService::class);
        $after = max(0, (int) $request->input('after', 0));
        $onlyMissing = (string) $request->input('scope', 'missing') !== 'all';
        return $this->json($media->regenerateBatch($after, $onlyMissing));
    }

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

    /*
     * POST /admin/settings/{tab}. The forms save through the AJAX gateway
     * (settings.save) when script runs; these routes are the fallback, and
     * both use SettingsSaver, so the checks and messages are the same.
     */
    public function saveGeneral(Request $request): Response       { return $this->saveTab($request, 'general'); }
    public function saveReading(Request $request): Response       { return $this->saveTab($request, 'reading'); }
    public function saveWriting(Request $request): Response       { return $this->saveTab($request, 'writing'); }
    public function saveDiscussion(Request $request): Response    { return $this->saveTab($request, 'discussion'); }
    public function saveSeo(Request $request): Response           { return $this->saveTab($request, 'seo'); }
    public function saveAppearance(Request $request): Response    { return $this->saveTab($request, 'appearance'); }
    public function savePermalinks(Request $request): Response    { return $this->saveTab($request, 'permalinks'); }
    public function saveMedia(Request $request): Response         { return $this->saveTab($request, 'media'); }
    public function saveEmail(Request $request): Response         { return $this->saveTab($request, 'email'); }
    public function saveAuthorization(Request $request): Response { return $this->saveTab($request, 'authorization'); }
    public function saveAi(Request $request): Response            { return $this->saveTab($request, 'ai'); }
    public function saveSecurity(Request $request): Response      { return $this->saveTab($request, 'security'); }
    public function saveSearch(Request $request): Response        { return $this->saveTab($request, 'search'); }

    /** POST /admin/settings/security/clear-reports */
    public function clearCspReports(Request $request): Response
    {
        if ($this->verifyCsrf($request)) {
            \App\Services\SecurityHeaders::clearReports();
            $this->flash('success', 'The policy reports were cleared.');
        } else {
            $this->flash('error', 'Security check failed.');
        }
        return $this->redirect('/admin/settings/security');
    }

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

    /**
     * The whole page of one section (or of the phone's Settings home), as a
     * page load would return it; settings.section (1.2.82) takes its <main>.
     */
    public function sectionPage(string $tab, bool $home = false): string
    {
        return $this->renderTab($tab, $home ? ['settingsHome' => true, 'title' => 'Settings'] : [])->getBody();
    }

    private function renderTab(string $tab, array $more = []): Response
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
        if ($tab === 'ai') {
            /** @var \App\Services\AiAccessService $agents */
            $agents = $this->app->make(\App\Services\AiAccessService::class);
            $extra['values'] = $agents->settings();
            $extra['robotsRules'] = (string) $settings->get('seo', 'robots_txt', "User-agent: *\nAllow: /\nDisallow: /admin/");
            $extra['origin'] = $agents->origin();
            $extra['bots'] = ['training' => \App\Services\AiAccessService::TRAINING_BOTS, 'search' => \App\Services\AiAccessService::SEARCH_BOTS, 'user' => \App\Services\AiAccessService::USER_BOTS];
            $extra['tools'] = $agents->tools();
            $extra['catalogCount'] = count($agents->catalog()['entries']);
            $extra['customInvalid'] = trim($agents->get('catalog_extra')) !== '' && $agents->customEntries() === [];
            $extra['staticRobots'] = is_file(BASEHIM_ROOT . '/robots.txt') || is_file(BASEHIM_ROOT . '/public/robots.txt');
            $extra['staticLlms'] = is_file(BASEHIM_ROOT . '/llms.txt') || is_file(BASEHIM_ROOT . '/public/llms.txt');
        }
        if ($tab === 'authorization') {
            /** @var \App\Services\TwoFactorService $tf */
            $tf = $this->app->make(\App\Services\TwoFactorService::class);
            $extra['twoFactor'] = [
                'policy'    => $tf->policy(),
                'trustDays' => $tf->trustDays(),
                'emergency' => $tf->emergencyDisabled(),
                'mailer'    => (string) ($this->app->make(\App\Services\Mailer::class)->config()['driver'] ?? 'mail'),
            ];
            try {
                $db = $this->app->make(\App\Core\Database::class);
                $rows = $db->select("SELECT meta FROM {users} WHERE deleted_at IS NULL AND meta LIKE '%two_factor%'");
                $extra['twoFactor']['users'] = count(array_filter($rows, fn($r) => !empty(\App\Services\TwoFactorService::security($r)['two_factor'])));
                $extra['twoFactor']['noEmail'] = (int) ($db->selectOne("SELECT COUNT(*) AS n FROM {users} WHERE deleted_at IS NULL AND status = 'active' AND (email IS NULL OR email = '')")['n'] ?? 0);
            } catch (\Throwable) {
                $extra['twoFactor']['users'] = 0;
                $extra['twoFactor']['noEmail'] = 0;
            }
            // Emailed links are built from APP_URL; without a real one they
            // fall back to the address the request came in on.
            $appUrl = (string) (\App\Core\Env::get('APP_URL', '') ?? '');
            $appHost = strtolower((string) (parse_url($appUrl, PHP_URL_HOST) ?: ''));
            $extra['twoFactor']['appUrlMissing'] = $appHost === '' || in_array($appHost, ['localhost', '127.0.0.1', '::1'], true);
        }
        if ($tab === 'email') {
            $mailer = $this->app->make(\App\Services\Mailer::class);
            $cfg = $mailer->config(); // fills in noreply@domain when none is saved
            $domain = \App\Services\Mailer::siteDomain();
            $from = (string) ($cfg['from_email'] ?? '');
            $fromDomain = strtolower((string) substr(strrchr($from, '@') ?: '', 1));
            $extra['mail'] = [
                'domain'      => $domain,
                'defaultFrom' => \App\Services\Mailer::defaultFrom(),
                'from'        => $from,
                'realDomain'  => \App\Services\Mailer::isRealDomain($domain),
                // Sending as someone else's domain (gmail.com, outlook.com…)
                // through this server is refused or lands in spam.
                'foreignFrom' => $fromDomain !== '' && $fromDomain !== $domain
                    && !str_ends_with($fromDomain, '.' . $domain) && !str_ends_with($domain, '.' . $fromDomain),
            ];
            $group = (array) $settings->getGroup('email');
            // The saved SMTP password is never sent back to the browser.
            $extra['mail']['hasPassword'] = (string) ($group['smtp_password'] ?? '') !== '';
            $extra['mail']['siteTitle'] = (string) ($settings->get('general', 'site_title', '') ?: 'Basehim');
            $extra['values'] = array_merge($group, [
                'from_email'    => $from,
                'from_name'     => (string) ($cfg['from_name'] ?? '') ?: $extra['mail']['siteTitle'],
                'smtp_password' => '',
            ]);
        }
        if ($tab === 'security') {
            $req = $this->app->make(\App\Core\Request::class);
            $extra['security'] = \App\Services\SecurityHeaders::settings();
            $extra['secure'] = $req->isSecure();
            $extra['reports'] = \App\Services\SecurityHeaders::reports();
            $extra['htaccessXss'] = (string) $settings->get(\App\Services\SecurityHeaders::GROUP, 'htaccess_xss', '');
            $extra['host'] = (string) ($_SERVER['HTTP_HOST'] ?? '');
        }
        if ($tab === 'search') {
            /** @var \App\Core\Search\SearchManager $sm */
            $sm = $this->app->make(\App\Core\Search\SearchManager::class);
            $site = new \App\Core\Search\SiteSearch($this->app);
            $extra['sources'] = array_values($sm->status());
            $extra['engine'] = $sm->engine()->name();
            $extra['building'] = (bool) array_filter($extra['sources'], fn($s) => $s['kind'] === 'indexed' && !$s['ready']);
            $extra['site'] = $site->settings();
            $extra['types'] = $site->availableTypes();
            $extra['appSources'] = $site->appSources();
        }
        if ($tab === 'media') {
            /** @var \App\Services\MediaService $media */
            $media = $this->app->make(\App\Services\MediaService::class);
            $extra['media'] = $media->mediaSettings();
            $extra['gdAvailable'] = \extension_loaded('gd');
            $extra['gdWebp'] = \function_exists('imagewebp');
            $extra['mediaCount'] = $media->totalCount();
            $extra['imageCounts'] = $media->imageCounts();
        }

        return $this->view('settings.' . $tab, array_merge([
            'title' => \App\Services\SettingsSaver::label($tab) . ' Settings',
            'currentUser' => $this->user(),
            'tab' => $tab,
            'values' => $settings->getGroup($tab),
            'allThemes' => $themes->scan(),
            'activeTheme' => $themes->activeSlug(),
            'csrf' => $session->csrfToken(),
        ], $extra, $more));
    }

    private function saveTab(Request $request, string $tab): Response
    {
        if (!$this->verifyCsrf($request)) { $this->flash('error', 'Security check failed.'); return $this->redirect("/admin/settings/{$tab}"); }
        $r = (new \App\Services\SettingsSaver($this->app))->save($tab, $request->all(), $this->user());
        $this->flash($r['type'], $r['message']);
        return $this->redirect("/admin/settings/{$tab}");
    }
}
