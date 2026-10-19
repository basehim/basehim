<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Application;
use App\Core\EnvFile;
use App\Http\Middleware\CheckCapability;

/**
 * The site's administration email, and how it stays in step (1.2.66).
 *
 * Three places held "the admin email" and nothing linked them:
 *
 *  - Settings → General `admin_email`: where site notices go (new comments…);
 *  - the administrator's own account email: their sign-in name, password
 *    resets and sign-in codes;
 *  - ADMIN_EMAIL in .env: the installer's value, the default for the setting.
 *
 * Changing one left the others behind. Now:
 *
 *  - a new administration email is written to .env as well;
 *  - when it was your own account's address, Settings offers to change your
 *    account too, through the same protected change as My Profile (current
 *    password; with two-step verification, a code to the new address; the old
 *    address is told);
 *  - when someone who may change settings changes an account email that was
 *    the administration email, the setting (and .env) follow.
 *
 * An account email is never changed by a setting alone: that would let an open
 * session take the account over, which My Profile was hardened against.
 */
final class AdminEmailService
{
    public const ENV_KEY = 'ADMIN_EMAIL';

    private Application $app;

    public function __construct(?Application $app = null)
    {
        $this->app = $app ?? Application::getInstance();
    }

    public function current(): string
    {
        return strtolower(trim((string) $this->app->make(SettingService::class)->get('general', 'admin_email', '')));
    }

    /** Write the address to .env. False when .env is missing or not writable. */
    public function writeEnv(string $email): bool
    {
        // The file the site actually read its settings from.
        $path = \App\Core\Env::path()
            ?? ((defined('BASEHIM_ROOT') ? (string) BASEHIM_ROOT : dirname(__DIR__, 2)) . '/.env');
        return EnvFile::set($path, self::ENV_KEY, strtolower(trim($email)));
    }

    /**
     * Change $user's own account email to $new, as My Profile does.
     *
     * @return array{status:'changed'|'pending'|'error', message:string}
     */
    public function changeOwnAccount(array $user, string $new, string $currentPassword): array
    {
        $users = $this->app->make(UserService::class);
        $fresh = $users->find((int) ($user['id'] ?? 0));
        if (!$fresh) return ['status' => 'error', 'message' => 'Your account could not be found.'];
        $new = strtolower(trim($new));
        if (!filter_var($new, FILTER_VALIDATE_EMAIL)) {
            return ['status' => 'error', 'message' => 'Please enter a valid email address.'];
        }
        if ($new === strtolower((string) $fresh['email'])) {
            return ['status' => 'changed', 'message' => ''];
        }
        if (!password_verify($currentPassword, (string) $fresh['password_hash'])) {
            return ['status' => 'error', 'message' => 'Enter your current password to change your account email as well. Nothing was saved.'];
        }
        if ($users->emailExists($new, (int) $fresh['id'])) {
            return ['status' => 'error', 'message' => 'That email address is already used by another account. Nothing was saved.'];
        }
        $tf = $this->app->make(TwoFactorService::class);
        if ($tf->applies($fresh)) {
            // Sign-in codes go to this address: prove it works before switching.
            $r = $tf->send(['email' => $new] + $fresh, 'email', substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45));
            if (!$r['ok'] && ($r['error'] ?? '') !== 'wait') {
                return ['status' => 'error', 'message' => TwoFactorService::sendError($r) . ' Nothing was saved.'];
            }
            $this->app->make(\App\Core\Session::class)->set('email_change', ['uid' => (int) $fresh['id'], 'email' => $new, 'at' => time()]);
            return ['status' => 'pending', 'message' => 'To finish changing your account email, enter the code we sent to '
                . \App\Http\Controllers\Admin\AuthController::maskEmail($new) . ' on My Profile.'];
        }
        $users->update((int) $fresh['id'], ['email' => $new]);
        ActivityLogService::record((int) $fresh['id'], 'user.email_changed', 'user', (int) $fresh['id'], 'Email changed from Settings (administration email)');
        try {
            $this->app->make(Mailer::class)->sendTemplate(
                (string) $fresh['email'],
                'Your account email was changed',
                'Email address changed',
                '<p>The email address of your account was changed to <strong>' . htmlspecialchars(\App\Http\Controllers\Admin\AuthController::maskEmail($new)) . '</strong>.</p>'
                . '<p style="font-size:12px;color:#64748b;">If you did not make this change, contact your site administrator immediately.</p>'
            );
        } catch (\Throwable) {}
        return ['status' => 'changed', 'message' => 'Your account email is now ' . $new . '.'];
    }

    /**
     * An account email changed from $old to $new. When $old was the
     * administration email and the person signed in may change settings,
     * the setting and .env follow. Never throws.
     */
    public function accountEmailChanged(string $old, string $new): void
    {
        try {
            $old = strtolower(trim($old));
            $new = strtolower(trim($new));
            if ($old === '' || $new === '' || $old === $new || !filter_var($new, FILTER_VALIDATE_EMAIL)) return;
            if ($this->current() !== $old) return;
            $actor = $this->app->has('auth.user') ? $this->app->make('auth.user') : null;
            if (!is_array($actor) || !CheckCapability::userCan($actor, 'manage_options')) return;
            $this->app->make(SettingService::class)->set('general', 'admin_email', $new);
            $this->writeEnv($new);
        } catch (\Throwable) {
        }
    }
}
