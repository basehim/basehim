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
    public function ai(Request $request): Response        { return $this->renderTab('ai'); }
    public function appearance(Request $request): Response{ return $this->renderTab('appearance'); }
    public function permalinks(Request $request): Response{ return $this->renderTab('permalinks'); }
    public function media(Request $request): Response     { return $this->renderTab('media'); }
    public function email(Request $request): Response     { return $this->renderTab('email'); }
    public function authorization(Request $request): Response { return $this->renderTab('authorization'); }

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
            'title' => ucfirst($tab) . ' Settings',
            'currentUser' => $this->user(),
            'tab' => $tab,
            'values' => $settings->getGroup($tab),
            'allThemes' => $themes->scan(),
            'activeTheme' => $themes->activeSlug(),
            'csrf' => $session->csrfToken(),
        ], $extra));
    }

    /**
     * POST /admin/settings/ai — AI agent accessibility. Its own saver:
     * unchecked boxes are stored as off, numbers are clamped, the origin-trial
     * token keeps only token characters, and custom catalog entries must be
     * valid JSON or they are not saved.
     */
    public function saveAi(Request $request): Response
    {
        if (!$this->verifyCsrf($request)) { $this->flash('error', 'Security check failed.'); return $this->back(); }
        /** @var SettingService $settings */
        $settings = $this->app->make(SettingService::class);
        $in = $request->all();
        $flags = ['enabled', 'robots_enabled', 'llms_enabled', 'llms_pages', 'llms_categories', 'llms_full', 'catalog_enabled',
                  'webmcp_enabled', 'webmcp_forms', 'webmcp_comments', 'tool_recent', 'tool_categories', 'jsonld_website', 'robots_agentmap'];
        foreach ($flags as $k) $settings->set('ai', $k, !empty($in[$k]) ? '1' : '0');
        foreach (['ai_training', 'ai_search', 'ai_user'] as $k) $settings->set('ai', $k, (($in[$k] ?? 'allow') === 'block') ? 'block' : 'allow');
        $settings->set('ai', 'llms_posts', (string) max(0, min(500, (int) ($in['llms_posts'] ?? 30))));
        $settings->set('ai', 'llms_full_limit', (string) max(1, min(500, (int) ($in['llms_full_limit'] ?? 50))));
        $clip = static fn($v, int $max) => mb_substr(str_replace("\r", '', trim((string) $v)), 0, $max);
        $settings->set('ai', 'llms_intro', $clip($in['llms_intro'] ?? '', 4000));
        $settings->set('ai', 'llms_extra', $clip($in['llms_extra'] ?? '', 20000));
        $settings->set('ai', 'catalog_queries', $clip($in['catalog_queries'] ?? '', 2000));
        $settings->set('ai', 'webmcp_token', preg_replace('/[^A-Za-z0-9+\/=]/', '', (string) ($in['webmcp_token'] ?? '')) ?? '');
        $settings->set('seo', 'robots_txt', $clip($in['robots_rules'] ?? '', 20000));

        $extra = trim((string) ($in['catalog_extra'] ?? ''));
        $message = 'AI agent settings saved.';
        $type = 'success';
        if ($extra === '') {
            $settings->set('ai', 'catalog_extra', '');
        } else {
            $decoded = json_decode($extra, true);
            if (!is_array($decoded)) {
                $type = 'error';
                $message = 'Settings saved, except the extra catalog entries: they are not valid JSON (' . json_last_error_msg() . '). The previous entries were kept.';
            } else {
                /** @var \App\Services\AiAccessService $agents */
                $agents = $this->app->make(\App\Services\AiAccessService::class);
                $list = array_is_list($decoded) ? $decoded : [$decoded];
                $bad = 0;
                foreach ($list as $entry) if (!is_array($entry) || $agents->normalizeEntry($entry) === null) $bad++;
                $settings->set('ai', 'catalog_extra', json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                if ($bad) {
                    $type = 'error';
                    $message = 'Settings saved. ' . $bad . ' of the extra catalog entries ' . ($bad === 1 ? 'is' : 'are') . ' not valid ARD entries and will be left out: each needs an identifier like urn:air:' . $agents->publisher() . ':namespace:name, a displayName, a type, and exactly one of url or data.';
                }
            }
        }
        try { $this->app->make(\App\Services\AiAccessService::class)->reset(); } catch (\Throwable) {}
        $this->flash($type, $message);
        return $this->redirect('/admin/settings/ai');
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
