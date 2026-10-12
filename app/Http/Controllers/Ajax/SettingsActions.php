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
        }
        return $out;
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
