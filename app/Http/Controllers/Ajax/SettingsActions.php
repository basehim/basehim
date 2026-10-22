<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ajax;

use App\Core\Ajax\AjaxContext;
use App\Core\Ajax\AjaxException;
use App\Core\Application;
use App\Http\Middleware\AdminAreaPolicy;
use App\Http\Middleware\CheckCapability;
use App\Services\SettingsSaver;
use App\Services\SettingService;

/**
 * settings.save — save a Settings screen without leaving it.
 *
 * The browser sends the form exactly as it would have posted it, encoded
 * (`form`, application/x-www-form-urlencoded), and it is decoded here the way
 * PHP decodes a POST body, so a field means the same thing either way —
 * including the hidden "0" each checkbox carries. SettingsSaver does the
 * rest, shared with the form routes.
 */
final class SettingsActions
{
    public function save(AjaxContext $ctx): array
    {
        $tab = (string) $ctx->input('tab');
        if (!isset(SettingsSaver::TABS[$tab])) throw AjaxException::notFound('Unknown settings screen.');

        // The action is registered for /admin/settings; a site may have opened
        // or closed single screens with admin.area_policy, so ask again for
        // this one.
        $cap = AdminAreaPolicy::requiredFor('/admin/settings/' . $tab);
        if ($cap !== null && !CheckCapability::userCan($ctx->user(), $cap)) {
            throw AjaxException::forbidden('You are not allowed to change these settings.');
        }

        $in = [];
        parse_str((string) $ctx->input('form', ''), $in);
        unset($in['_csrf']);

        $app = Application::getInstance();
        $r = (new SettingsSaver($app))->save($tab, $in, $ctx->user());
        if (!$r['ok']) throw AjaxException::invalid($r['message']);

        $ctx->log('Settings saved', ['tab' => $tab]);

        $out = ['type' => $r['type'], 'message' => $r['message']];
        // Values the server changed on the way in (an empty From Email becomes
        // noreply@…, numbers are clamped), so the form shows what was stored.
        $out['values'] = self::shown($app, $tab);
        if ($tab === 'general') {
            // A renamed site retitles the open tab and the sidebar at once.
            $out['site_title'] = \App\Core\AdminHead::siteName();
            $out['document_title'] = \App\Core\AdminHead::title(SettingsSaver::label($tab) . ' Settings');
            // The account email may have changed with the administration email (1.2.66).
            $me = $app->make(\App\Services\UserService::class)->find((int) ($ctx->userId() ?? 0));
            $out['account_email'] = (string) ($me['email'] ?? '');
        }
        return $out;
    }

    /**
     * settings.section (1.2.82) — one Settings section, to swap in without a
     * page load: the content of the page's <main> as GET /admin/settings/{tab}
     * renders it (so a section's own scripts and live panels come with it),
     * and the page title. tab "home" is the phone's list of sections.
     */
    public function section(AjaxContext $ctx): array
    {
        $tab = (string) $ctx->input('tab');
        $home = $tab === 'home';
        if ($home) $tab = 'general';
        if (!isset(SettingsSaver::TABS[$tab])) throw AjaxException::notFound('Unknown settings screen.');
        $cap = AdminAreaPolicy::requiredFor('/admin/settings/' . $tab);
        if ($cap !== null && !CheckCapability::userCan($ctx->user(), $cap)) {
            throw AjaxException::forbidden('You are not allowed to see these settings.');
        }
        $app = Application::getInstance();
        // The views read the signed-in person the way a page request does.
        if (!$app->has('auth.user') && $ctx->user() !== null) $app->instance('auth.user', $ctx->user());

        $page = (new \App\Http\Controllers\Admin\SettingController())->sectionPage($tab, $home);
        // The layout's own <main> (id bh-content), not the word in a comment or a style.
        if (!preg_match('#<main id="bh-content"[^>]*>(.*)</main>#s', $page, $m)) {
            throw new AjaxException('SERVER_ERROR', 'This settings screen could not be loaded.', 500);
        }
        $title = preg_match('#<title>(.*?)</title>#s', $page, $t) ? html_entity_decode(trim($t[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8') : '';
        return [
            'tab'     => $home ? 'home' : $tab,
            'html'    => trim($m[1]),
            'title'   => $title,
            'heading' => $home ? 'Settings' : SettingsSaver::label($tab) . ' Settings',
        ];
    }

    /** The saved values of the fields a screen shows, as strings. */
    private static function shown(Application $app, string $tab): array
    {
        $s = $app->make(SettingService::class);
        $group = (array) $s->getGroup($tab);
        if ($tab === 'email') {
            $cfg = $app->make(\App\Services\Mailer::class)->config();
            $group['from_email'] = (string) ($cfg['from_email'] ?? '');
            $group['from_name'] = (string) ($cfg['from_name'] ?? '') ?: (string) ($s->get('general', 'site_title', '') ?: 'Basehim');
            unset($group['smtp_password']);       // never sent back to the browser
        }
        if ($tab === 'ai') {
            $group['robots_rules'] = (string) $s->get('seo', 'robots_txt', '');
        }
        $out = [];
        foreach ($group as $k => $v) {
            if (is_scalar($v) || $v === null) $out[(string) $k] = is_bool($v) ? ($v ? '1' : '0') : (string) $v;
        }
        return $out;
    }
}
