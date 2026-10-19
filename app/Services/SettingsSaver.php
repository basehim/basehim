<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Application;

/**
 * Saves one Settings screen's fields.
 *
 * The settings forms post to /admin/settings/{tab}, and since 1.2.50 the
 * same forms save through the AJAX gateway (settings.save) without leaving
 * the page. Both paths come here, so validation, clamping and the message
 * shown afterwards are the same whichever way a form was sent.
 *
 * save() returns ['ok' => bool, 'type' => 'success'|'error', 'message' => string].
 * 'ok' is false only when nothing was saved (a value was refused); a partial
 * save — permalinks without .htaccess, AI settings with bad catalog JSON —
 * is ok with an 'error' message, as it always was.
 */
final class SettingsSaver
{
    /** Tabs with a form, and the name each is shown under. */
    public const TABS = [
        'general'       => 'General',
        'reading'       => 'Reading',
        'writing'       => 'Writing',
        'discussion'    => 'Discussion',
        'seo'           => 'SEO',
        'ai'            => 'AI',
        'appearance'    => 'Appearance',
        'permalinks'    => 'Permalinks',
        'media'         => 'Media',
        'email'         => 'Email',
        'authorization' => 'Authentication',
    ];

    private Application $app;
    private SettingService $settings;

    public function __construct(?Application $app = null)
    {
        $this->app = $app ?? Application::getInstance();
        $this->settings = $this->app->make(SettingService::class);
    }

    public static function label(string $tab): string
    {
        return self::TABS[$tab] ?? ucfirst($tab);
    }

    /**
     * @param array<string,mixed> $in   the posted fields (without _csrf)
     * @param array|null          $user the signed-in user, for messages about them
     */
    public function save(string $tab, array $in, ?array $user = null): array
    {
        unset($in['_csrf']);
        return match ($tab) {
            'general'       => $this->general($in, $user),
            'email'         => $this->email($in),
            'authorization' => $this->authorization($in, $user),
            'permalinks'    => $this->permalinks($in),
            'ai'            => $this->ai($in),
            default         => isset(self::TABS[$tab]) ? $this->generic($tab, $in) : self::result(false, 'error', 'Unknown settings screen.'),
        };
    }

    private static function result(bool $ok, string $type, string $message): array
    {
        return ['ok' => $ok, 'type' => $type, 'message' => $message];
    }

    /** Reading, Writing, Discussion, SEO, Appearance, Media: every posted key is stored. */
    private function generic(string $tab, array $in): array
    {
        foreach ($in as $key => $val) {
            // booleans arrive as "1"/"0" (each checkbox has a hidden "0" before it)
            $this->settings->set($tab, (string) $key, $val);
        }
        return self::result(true, 'success', self::label($tab) . ' settings saved.');
    }

    private function general(array $in, ?array $user = null): array
    {
        // Administration email (1.2.66): validated, written to .env too, and —
        // when it was your own account's address and you ask — your account
        // changes with it, through the same protected change as My Profile.
        // The two helper fields are never stored as settings.
        $wantAccount = !empty($in['admin_email_account']);
        $password = (string) ($in['admin_email_password'] ?? '');
        unset($in['admin_email_account'], $in['admin_email_password']);
        $adminEmail = new AdminEmailService($this->app);
        $oldAdmin = $adminEmail->current();
        $newAdmin = null;
        if (array_key_exists('admin_email', $in)) {
            $newAdmin = strtolower(trim((string) $in['admin_email']));
            // Checked when it changes; an address saved before (admin@localhost
            // on a test install) must not stop the rest of the screen saving.
            if ($newAdmin !== $oldAdmin && !filter_var($newAdmin, FILTER_VALIDATE_EMAIL)) {
                return self::result(false, 'error', 'Enter a valid administration email address.');
            }
            $in['admin_email'] = $newAdmin;
        }
        $adminChanged = $newAdmin !== null && $newAdmin !== $oldAdmin;

        // The timezone used to be a free-text field that nothing read. It is a
        // list now; anything not in it is refused rather than stored.
        $tz = $in['timezone'] ?? null;
        if ($tz !== null && !\App\Core\Time::isValidTimezone((string) $tz)) {
            return self::result(false, 'error', 'Choose a timezone from the list.');
        }
        foreach (['date_format', 'time_format'] as $k) {
            $v = $in[$k] ?? null;
            if ($v !== null && (!is_string($v) || $v === '' || strlen($v) > 40 || preg_match('/[<>"\'\\\\]/', $v))) {
                return self::result(false, 'error', 'That date or time format is not allowed.');
            }
        }
        $wk = $in['week_starts_on'] ?? null;
        if ($wk !== null && !in_array((string) $wk, ['0', '1', '2', '3', '4', '5', '6'], true)) {
            return self::result(false, 'error', 'Pick a day for the start of the week.');
        }
        // Your account first: a wrong password must leave everything unsaved.
        $accountNote = '';
        if ($adminChanged && $wantAccount && is_array($user) && isset($user['id'])
            && strtolower((string) ($user['email'] ?? '')) === $oldAdmin) {
            $r = $adminEmail->changeOwnAccount($user, $newAdmin, $password);
            if ($r['status'] === 'error') {
                return self::result(false, 'error', $r['message']);
            }
            $accountNote = $r['message'];
        }
        $res = $this->generic('general', $in);
        \App\Core\Time::reset();
        // .env follows the setting; this also repairs a site whose setting was
        // changed before 1.2.66, the next time General is saved.
        $envBehind = $newAdmin !== null && filter_var($newAdmin, FILTER_VALIDATE_EMAIL)
            && strtolower(trim((string) (\App\Core\Env::get(AdminEmailService::ENV_KEY, '') ?? ''))) !== $newAdmin;
        if ($adminChanged || $envBehind) {
            $notes = [];
            if ($accountNote !== '') $notes[] = $accountNote;
            if (!$adminEmail->writeEnv($newAdmin)) {
                $notes[] = 'ADMIN_EMAIL in .env could not be updated (the file is not writable); it is only the default used before this setting exists.';
            }
            if ($notes) {
                $res = self::result(true, 'success', $res['message'] . ' ' . implode(' ', $notes));
            }
        }
        return $res;
    }

    /**
     * Only known keys are stored. An empty From Email becomes noreply@ the
     * site's domain, and a malformed one is refused rather than saved.
     */
    private function email(array $in): array
    {
        $from = trim((string) ($in['from_email'] ?? ''));
        if ($from === '') {
            $from = Mailer::defaultFrom();
        } elseif (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
            return self::result(false, 'error', 'From Email is not a valid email address. Nothing was saved.');
        }
        $driver = (string) ($in['driver'] ?? 'mail') === 'smtp' ? 'smtp' : 'mail';
        $enc = (string) ($in['smtp_encryption'] ?? 'tls');
        if (!in_array($enc, ['tls', 'ssl', 'none'], true)) $enc = 'tls';
        $port = (int) ($in['smtp_port'] ?? 587);
        if ($port < 1 || $port > 65535) $port = 587;

        $s = $this->settings;
        $s->set('email', 'from_email', $from);
        // The From Name follows the site name unless something else is typed,
        // so renaming the site later also renames the sender.
        $name = trim(str_replace(["\r", "\n"], ' ', (string) ($in['from_name'] ?? '')));
        if ($name === trim((string) $s->get('general', 'site_title', ''))) $name = '';
        $s->set('email', 'from_name', $name);
        $s->set('email', 'driver', $driver);
        $s->set('email', 'smtp_host', trim((string) ($in['smtp_host'] ?? '')));
        $s->set('email', 'smtp_port', (string) $port);
        $s->set('email', 'smtp_encryption', $enc);
        $s->set('email', 'smtp_username', trim((string) ($in['smtp_username'] ?? '')));
        // A blank password field keeps the saved one (it is never shown).
        if ((string) ($in['smtp_password'] ?? '') !== '') $s->set('email', 'smtp_password', (string) $in['smtp_password']);
        if (!empty($in['smtp_password_clear'])) $s->set('email', 'smtp_password', '');

        return self::result(true, 'success', 'Email settings saved. Emails are sent from ' . $from . '.');
    }

    /**
     * Authentication: only known keys are stored, numbers are clamped and the
     * registration role can never be an administrator role.
     */
    private function authorization(array $in, ?array $user): array
    {
        $s = $this->settings;
        foreach (['allow_registration', 'remember_me', 'honeypot', 'welcome_email', 'otp_enabled'] as $k) {
            $s->set('authorization', $k, !empty($in[$k]) ? '1' : '0');
        }
        $clamp = static fn($v, int $lo, int $hi, int $def): string => (string) max($lo, min($hi, is_numeric($v) ? (int) $v : $def));
        $s->set('authorization', 'login_attempt_limit', $clamp($in['login_attempt_limit'] ?? 3, 1, 10, 3));
        $s->set('authorization', 'account_attempt_limit', $clamp($in['account_attempt_limit'] ?? 10, 3, 100, 10));
        $s->set('authorization', 'captcha_fail_limit', $clamp($in['captcha_fail_limit'] ?? 3, 1, 10, 3));
        $s->set('authorization', 'lockout_after', $clamp($in['lockout_after'] ?? 10, 3, 50, 10));
        $s->set('authorization', 'lockout_minutes', $clamp($in['lockout_minutes'] ?? 15, 1, 1440, 15));
        $s->set('authorization', 'default_role',
            \App\Http\Controllers\Admin\AuthController::registrationRole($this->app, (string) ($in['default_role'] ?? 'subscriber')));

        $policy = (string) ($in['two_factor'] ?? 'optional');
        if (!in_array($policy, TwoFactorService::POLICIES, true)) $policy = 'optional';
        $previous = $this->app->make(TwoFactorService::class)->policy();
        $s->set('authorization', 'two_factor', $policy);
        // Requiring codes for more people: their "keep me signed in" cookies
        // were issued without a code, so they end, and the next sign-in asks.
        $rank = array_flip(TwoFactorService::POLICIES);
        if ($rank[$policy] > $rank[$previous] && in_array($policy, ['admins', 'all'], true)) {
            try {
                $db = $this->app->make(\App\Core\Database::class);
                if ($policy === 'all') {
                    $db->execute('DELETE FROM {auth_remember_tokens}');
                } else {
                    $db->execute("DELETE FROM {auth_remember_tokens} WHERE user_id IN (SELECT id FROM {users} WHERE role IN ('admin', 'super_admin'))");
                }
            } catch (\Throwable) {}
        }
        $s->set('authorization', 'two_factor_trust_days', $clamp($in['two_factor_trust_days'] ?? 30, 0, 90, 30));

        $msg = 'Authentication settings saved.';
        if ($user && in_array($policy, ['admins', 'all'], true) && empty(TwoFactorService::security($user)['two_factor'])) {
            $msg .= ' From your next sign-in you will be asked for a code emailed to '
                . \App\Http\Controllers\Admin\AuthController::maskEmail((string) ($user['email'] ?? ''))
                . ' — make sure the site can send email (Settings › Email › Send test).';
        }
        return self::result(true, 'success', $msg);
    }

    /**
     * Saves the settings, then writes the canonical-URL rules into .htaccess.
     * The settings are stored first and separately: if the file cannot be
     * written — no .htaccess, wrong permissions, nginx — the preference is
     * still recorded and the screen can show the block to paste by hand.
     */
    private function permalinks(array $in): array
    {
        $host = (string) ($in['canonical_host'] ?? 'none');
        if (!in_array($host, ['none', 'www', 'root'], true)) $host = 'none';
        $https = (bool) ($in['force_https'] ?? false);

        $this->settings->set('permalinks', 'structure', (string) ($in['structure'] ?? 'pretty'));
        $this->settings->set('permalinks', 'canonical_host', $host);
        $this->settings->set('permalinks', 'force_https', $https);

        /** @var HtaccessService $ht */
        $ht = $this->app->make(HtaccessService::class);

        // The URL to check afterwards. Redirect rules are exactly the kind of
        // change that can take a site down, so it is fetched before the
        // operator is told everything went well.
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $verify = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? '') . (defined('BASEHIM_BASE') ? BASEHIM_BASE : '') . '/';

        $result = $ht->apply($host, $https, filter_var($verify, FILTER_VALIDATE_URL) ? $verify : null);
        return $result['ok']
            ? self::result(true, 'success', 'Permalink settings saved. ' . $result['message'])
            // Not an error state for the settings themselves — they saved.
            : self::result(true, 'error', 'Settings saved, but the .htaccess file was not changed: ' . $result['message']);
    }

    /**
     * AI agent accessibility: unchecked boxes are stored as off, numbers are
     * clamped, the origin-trial token keeps only token characters, and custom
     * catalog entries must be valid JSON or they are not saved.
     */
    private function ai(array $in): array
    {
        $s = $this->settings;
        $flags = ['enabled', 'robots_enabled', 'llms_enabled', 'llms_pages', 'llms_categories', 'llms_full', 'catalog_enabled',
                  'webmcp_enabled', 'webmcp_forms', 'webmcp_comments', 'tool_recent', 'tool_categories', 'jsonld_website', 'robots_agentmap'];
        foreach ($flags as $k) $s->set('ai', $k, !empty($in[$k]) ? '1' : '0');
        foreach (['ai_training', 'ai_search', 'ai_user'] as $k) $s->set('ai', $k, (($in[$k] ?? 'allow') === 'block') ? 'block' : 'allow');
        $s->set('ai', 'llms_posts', (string) max(0, min(500, (int) ($in['llms_posts'] ?? 30))));
        $s->set('ai', 'llms_full_limit', (string) max(1, min(500, (int) ($in['llms_full_limit'] ?? 50))));
        $clip = static fn($v, int $max) => mb_substr(str_replace("\r", '', trim((string) $v)), 0, $max);
        $s->set('ai', 'llms_intro', $clip($in['llms_intro'] ?? '', 4000));
        $s->set('ai', 'llms_extra', $clip($in['llms_extra'] ?? '', 20000));
        $s->set('ai', 'catalog_queries', $clip($in['catalog_queries'] ?? '', 2000));
        $s->set('ai', 'webmcp_token', preg_replace('/[^A-Za-z0-9+\/=]/', '', (string) ($in['webmcp_token'] ?? '')) ?? '');
        $s->set('seo', 'robots_txt', $clip($in['robots_rules'] ?? '', 20000));

        $extra = trim((string) ($in['catalog_extra'] ?? ''));
        $message = 'AI agent settings saved.';
        $type = 'success';
        if ($extra === '') {
            $s->set('ai', 'catalog_extra', '');
        } else {
            $decoded = json_decode($extra, true);
            if (!is_array($decoded)) {
                $type = 'error';
                $message = 'Settings saved, except the extra catalog entries: they are not valid JSON (' . json_last_error_msg() . '). The previous entries were kept.';
            } else {
                /** @var AiAccessService $agents */
                $agents = $this->app->make(AiAccessService::class);
                $list = array_is_list($decoded) ? $decoded : [$decoded];
                $bad = 0;
                foreach ($list as $entry) if (!is_array($entry) || $agents->normalizeEntry($entry) === null) $bad++;
                $s->set('ai', 'catalog_extra', json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                if ($bad) {
                    $type = 'error';
                    $message = 'Settings saved. ' . $bad . ' of the extra catalog entries ' . ($bad === 1 ? 'is' : 'are')
                        . ' not valid ARD entries and will be left out: each needs an identifier like urn:air:' . $agents->publisher()
                        . ':namespace:name, a displayName, a type, and exactly one of url or data.';
                }
            }
        }
        try { $this->app->make(AiAccessService::class)->reset(); } catch (\Throwable) {}
        return self::result(true, $type, $message);
    }
}
