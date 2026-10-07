<?php
declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Http\Controllers\Controller;
use App\Services\UserService;

class ProfileController extends Controller
{
    public function show(Request $request): Response
    {
        $user = $this->user();
        if (!$user) return $this->redirect('/admin/login');
        $session = $this->app->make(Session::class);
        /** @var \App\Services\TwoFactorService $tf */
        $tf = $this->app->make(\App\Services\TwoFactorService::class);
        $setup = $session->get('two_factor_setup');
        $setupPending = is_array($setup) && (int) ($setup['uid'] ?? 0) === (int) $user['id'] && (int) ($setup['at'] ?? 0) > time() - 900;
        if (!$setupPending && $setup !== null) $session->forget('two_factor_setup');
        return $this->view('profile.index', [
            'title' => 'My Profile',
            'currentUser' => $user,
            'csrf' => $session->csrfToken(),
            'emailChange' => (function () use ($session, $user) {
                $p = $session->get('email_change');
                return is_array($p) && (int) ($p['uid'] ?? 0) === (int) $user['id'] && (int) ($p['at'] ?? 0) > time() - 900 ? (string) $p['email'] : '';
            })(),
            'twoFactor' => [
                'policy'    => $tf->policy(),
                'enabled'   => $tf->isEnabled($user),
                'required'  => $tf->isRequired($user),
                'active'    => $tf->applies($user),
                'emergency' => $tf->emergencyDisabled(),
                'setup'     => $setupPending,
                'email'     => \App\Http\Controllers\Admin\AuthController::maskEmail((string) ($user['email'] ?? '')),
                'since'     => (string) (\App\Services\TwoFactorService::security($user)['two_factor_at'] ?? ''),
            ],
        ]);
    }

    public function update(Request $request): Response
    {
        if (!$this->verifyCsrf($request)) { $this->flash('error', 'Security check failed.'); return $this->back(); }

        $userId = $this->userId();
        if (!$userId) return $this->redirect('/admin/login');

        /** @var UserService $users */
        $users = $this->app->make(UserService::class);
        $current = $users->find($userId);
        if (!$current) return $this->redirect('/admin/login');

        $data = [
            'display_name' => $request->input('display_name', $current['display_name']),
            'bio' => $request->input('bio'),
        ];
        $currentPassword = (string) $request->input('current_password', '');

        // A new email address needs the current password, like a new password
        // does. The address receives password resets and sign-in codes, so
        // changing it freely let anyone holding an open session take the
        // account over for good.
        $newEmail = strtolower(trim((string) $request->input('email', $current['email'])));
        $emailChanged = $newEmail !== strtolower((string) $current['email']);
        if ($emailChanged) {
            if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
                $this->flash('error', 'Please enter a valid email address.');
                return $this->redirect('/admin/profile');
            }
            if (!password_verify($currentPassword, (string) $current['password_hash'])) {
                $this->flash('error', 'Enter your current password to change your email address.');
                return $this->redirect('/admin/profile');
            }
            if ($users->emailExists($newEmail, $userId)) {
                $this->flash('error', 'That email address is already used by another account.');
                return $this->redirect('/admin/profile');
            }
            /** @var \App\Services\TwoFactorService $tf */
            $tf = $this->app->make(\App\Services\TwoFactorService::class);
            if ($tf->applies($current)) {
                // Sign-in codes go to this address, so a typo would lock the
                // account out: prove the new address works before switching.
                $r = $tf->send(['email' => $newEmail] + $current, 'email', substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45));
                if (!$r['ok'] && ($r['error'] ?? '') !== 'wait') {
                    $this->flash('error', \App\Services\TwoFactorService::sendError($r));
                    return $this->redirect('/admin/profile');
                }
                $this->app->make(Session::class)->set('email_change', ['uid' => $userId, 'email' => $newEmail, 'at' => time()]);
                $emailChanged = false;
                $pendingEmail = $newEmail;
            } else {
                $data['email'] = $newEmail;
            }
        }

        $newPassword = (string)$request->input('new_password', '');
        if ($newPassword !== '') {
            if (!password_verify($currentPassword, $current['password_hash'])) {
                $this->flash('error', 'Current password is incorrect.');
                return $this->redirect('/admin/profile');
            }
            if (strlen($newPassword) < 8) {
                $this->flash('error', 'New password must be at least 8 characters.');
                return $this->redirect('/admin/profile');
            }
            $data['password'] = $newPassword;
        }

        $users->update($userId, $data);

        if (isset($data['password'])) {
            // Other devices are signed out by the change; keep this one.
            $this->app->make(\App\Services\AuthService::class)->refreshSessionFingerprint($userId);
            \App\Services\ActivityLogService::record($userId, 'auth.password_changed', 'user', $userId, 'Password changed from profile');
        }
        if ($emailChanged) {
            \App\Services\ActivityLogService::record($userId, 'user.email_changed', 'user', $userId, 'Email changed from profile');
            // Tell the old address, so a takeover does not go unnoticed.
            try {
                $this->app->make(\App\Services\Mailer::class)->sendTemplate(
                    (string) $current['email'],
                    'Your account email was changed',
                    'Email address changed',
                    '<p>The email address of your account was changed to <strong>' . htmlspecialchars(\App\Http\Controllers\Admin\AuthController::maskEmail($newEmail)) . '</strong>.</p>'
                    . '<p style="font-size:12px;color:#64748b;">If you did not make this change, contact your site administrator immediately.</p>'
                );
            } catch (\Throwable) {}
        }

        // Public author address. Cleaned and made unique by AuthorService; an
        // unusable value keeps the current one.
        $slugIn = trim((string) $request->input('author_slug', ''));
        if ($slugIn !== '') {
            /** @var \App\Services\AuthorService $authorSvc */
            $authorSvc = $this->app->make(\App\Services\AuthorService::class);
            $currentSlug = ($u = $authorSvc->find($userId)) ? $authorSvc->slugFor($u) : '';
            if ($slugIn !== $currentSlug && $authorSvc->setSlug($userId, $slugIn) === null) {
                $this->flash('error', 'That author page address could not be used; the previous one was kept.');
            }
        }
        if (!empty($pendingEmail)) {
            $this->flash('info', 'Profile saved. To finish changing your email, enter the code we sent to ' . \App\Http\Controllers\Admin\AuthController::maskEmail($pendingEmail) . '.');
            return $this->redirect('/admin/profile');
        }
        $this->flash('success', 'Profile updated.');
        return $this->redirect('/admin/profile');
    }

    /** POST /admin/profile/email/confirm — finish an email change with the code sent to the new address. */
    public function confirmEmail(Request $request): Response
    {
        if (!$this->verifyCsrf($request)) { $this->flash('error', 'Security check failed.'); return $this->redirect('/admin/profile'); }
        $user = $this->user();
        if (!$user) return $this->redirect('/admin/login');
        $session = $this->app->make(Session::class);
        $pending = $session->get('email_change');
        if ($request->input('cancel') !== null || !is_array($pending) || (int) ($pending['uid'] ?? 0) !== (int) $user['id'] || (int) ($pending['at'] ?? 0) < time() - 900) {
            $session->forget('email_change');
            if ($request->input('cancel') === null) $this->flash('error', 'That email change expired. Enter the new address again.');
            return $this->redirect('/admin/profile');
        }
        $tf = $this->app->make(\App\Services\TwoFactorService::class);
        $result = $tf->verify((int) $user['id'], 'email', (string) $request->input('code', ''));
        if ($result !== 'ok') {
            if ($result !== 'invalid') $session->forget('email_change');
            $this->flash('error', $result === 'invalid' ? 'That code is not right. Check the email sent to the new address.' : 'That code has expired. Enter the new address again to get a new one.');
            return $this->redirect('/admin/profile');
        }
        $session->forget('email_change');
        $users = $this->app->make(UserService::class);
        $newEmail = (string) $pending['email'];
        if ($users->emailExists($newEmail, (int) $user['id'])) {
            $this->flash('error', 'That email address is already used by another account.');
            return $this->redirect('/admin/profile');
        }
        $users->update((int) $user['id'], ['email' => $newEmail]);
        \App\Services\ActivityLogService::record((int) $user['id'], 'user.email_changed', 'user', (int) $user['id'], 'Email changed from profile (confirmed by code)');
        try {
            $this->app->make(\App\Services\Mailer::class)->sendTemplate(
                (string) $user['email'],
                'Your account email was changed',
                'Email address changed',
                '<p>The email address of your account was changed to <strong>' . htmlspecialchars(\App\Http\Controllers\Admin\AuthController::maskEmail($newEmail)) . '</strong>.</p>'
                . '<p style="font-size:12px;color:#64748b;">If you did not make this change, contact your site administrator immediately.</p>'
            );
        } catch (\Throwable) {}
        $this->flash('success', 'Your email address is now ' . $newEmail . '.');
        return $this->redirect('/admin/profile');
    }

    // ── Two-step verification ─────────────────────────────────────────────

    /** POST /admin/profile/two-factor/start — email a code to confirm the address. */
    public function twoFactorStart(Request $request): Response
    {
        if (!$this->verifyCsrf($request)) { $this->flash('error', 'Security check failed.'); return $this->redirect('/admin/profile#two-factor'); }
        $user = $this->user();
        if (!$user) return $this->redirect('/admin/login');
        /** @var \App\Services\TwoFactorService $tf */
        $tf = $this->app->make(\App\Services\TwoFactorService::class);
        if ($tf->policy() === 'off') {
            $this->flash('error', 'Two-step verification is turned off for this site.');
            return $this->redirect('/admin/profile#two-factor');
        }
        $r = $tf->send($user, 'setup', substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45));
        if (!$r['ok'] && ($r['error'] ?? '') !== 'wait') {
            $this->flash('error', \App\Services\TwoFactorService::sendError($r));
            return $this->redirect('/admin/profile#two-factor');
        }
        $this->app->make(Session::class)->set('two_factor_setup', ['uid' => (int) $user['id'], 'at' => time()]);
        $this->flash('info', 'We emailed a 6-digit code to ' . \App\Http\Controllers\Admin\AuthController::maskEmail((string) $user['email']) . '. Enter it below to turn on two-step verification.');
        return $this->redirect('/admin/profile#two-factor');
    }

    /** POST /admin/profile/two-factor/confirm */
    public function twoFactorConfirm(Request $request): Response
    {
        if (!$this->verifyCsrf($request)) { $this->flash('error', 'Security check failed.'); return $this->redirect('/admin/profile#two-factor'); }
        $user = $this->user();
        if (!$user) return $this->redirect('/admin/login');
        $session = $this->app->make(Session::class);
        $setup = $session->get('two_factor_setup');
        if (!is_array($setup) || (int) ($setup['uid'] ?? 0) !== (int) $user['id'] || (int) ($setup['at'] ?? 0) < time() - 900) {
            $session->forget('two_factor_setup');
            $this->flash('error', 'That setup expired. Start again to get a new code.');
            return $this->redirect('/admin/profile#two-factor');
        }
        /** @var \App\Services\TwoFactorService $tf */
        $tf = $this->app->make(\App\Services\TwoFactorService::class);
        $result = $tf->verify((int) $user['id'], 'setup', (string) $request->input('code', ''));
        if ($result !== 'ok') {
            if ($result !== 'invalid') $session->forget('two_factor_setup');
            $this->flash('error', match ($result) {
                'locked'  => 'Too many wrong codes. Please try again in 30 minutes.',
                'expired' => 'That code has expired. Start again to get a new one.',
                default   => 'That code is not right. Check the most recent email and try again.',
            });
            return $this->redirect('/admin/profile#two-factor');
        }
        $session->forget('two_factor_setup');
        $tf->enable((int) $user['id']);
        // Other sessions and remembered browsers must sign in again, now with a code.
        $this->app->make(\App\Services\AuthService::class)->signOutEverywhere((int) $user['id']);
        \App\Services\ActivityLogService::record((int) $user['id'], 'auth.two_factor_enabled', 'user', (int) $user['id'], 'Two-step verification turned on');
        $this->flash('success', 'Two-step verification is on. Next time you sign in, we will email you a code. Other devices have been signed out.');
        return $this->redirect('/admin/profile#two-factor');
    }

    /** POST /admin/profile/two-factor/cancel — abandon a setup in progress. */
    public function twoFactorCancel(Request $request): Response
    {
        if ($this->verifyCsrf($request)) {
            $this->app->make(Session::class)->forget('two_factor_setup');
            if ($uid = $this->userId()) $this->app->make(\App\Services\TwoFactorService::class)->clear($uid, 'setup');
        }
        return $this->redirect('/admin/profile#two-factor');
    }

    /** POST /admin/profile/two-factor/disable — needs the current password. */
    public function twoFactorDisable(Request $request): Response
    {
        if (!$this->verifyCsrf($request)) { $this->flash('error', 'Security check failed.'); return $this->redirect('/admin/profile#two-factor'); }
        $user = $this->user();
        if (!$user) return $this->redirect('/admin/login');
        /** @var \App\Services\TwoFactorService $tf */
        $tf = $this->app->make(\App\Services\TwoFactorService::class);
        if ($tf->isRequired($user)) {
            $this->flash('error', 'Two-step verification is required for your account by the site\'s settings.');
            return $this->redirect('/admin/profile#two-factor');
        }
        if (!password_verify((string) $request->input('current_password', ''), (string) $user['password_hash'])) {
            $this->flash('error', 'Enter your current password to turn off two-step verification.');
            return $this->redirect('/admin/profile#two-factor');
        }
        $tf->disable((int) $user['id']);
        \App\Services\ActivityLogService::record((int) $user['id'], 'auth.two_factor_disabled', 'user', (int) $user['id'], 'Two-step verification turned off');
        $this->flash('success', 'Two-step verification is off.');
        return $this->redirect('/admin/profile#two-factor');
    }

    /** POST /admin/profile/sessions/revoke — sign out every other device. */
    public function signOutOthers(Request $request): Response
    {
        if (!$this->verifyCsrf($request)) { $this->flash('error', 'Security check failed.'); return $this->redirect('/admin/profile#sessions'); }
        $uid = $this->userId();
        if (!$uid) return $this->redirect('/admin/login');
        $this->app->make(\App\Services\AuthService::class)->signOutEverywhere($uid);
        \App\Services\ActivityLogService::record($uid, 'auth.signed_out_everywhere', 'user', $uid, 'Signed out of all other devices');
        $this->flash('success', 'Every other device and browser has been signed out, connected AI clients must reconnect, and trusted devices will be asked for a code again. API keys still work.');
        return $this->redirect('/admin/profile#sessions');
    }

    // ── Profile photo: your own, always allowed ──────────────────────────

    public function avatar(Request $request): Response
    {
        $uid = $this->userId();
        if (!$uid) return Response::json(['ok' => false, 'error' => 'Please sign in again.'], 401);
        return $this->avatarSave($request, (int) $uid);
    }

    public function avatarDelete(Request $request): Response
    {
        $uid = $this->userId();
        if (!$uid) return Response::json(['ok' => false, 'error' => 'Please sign in again.'], 401);
        return $this->avatarRemove($request, (int) $uid);
    }

    /** POST {path} — upload a photo (multipart `avatar`) or use `media_id`. JSON. */
    private function avatarSave(Request $request, int $userId): Response
    {
        if (!$this->verifyCsrf($request)) return Response::json(['ok' => false, 'error' => 'Security check failed. Reload the page and try again.'], 419);
        /** @var \App\Services\AvatarService $svc */
        $svc = $this->app->make(\App\Services\AvatarService::class);
        try {
            $file = $_FILES['avatar'] ?? null;
            if (is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $av = $svc->upload($userId, $file, (int) ($this->userId() ?? $userId));
            } elseif ((int) $request->input('media_id', 0) > 0) {
                $av = $svc->setMedia($userId, (int) $request->input('media_id'));
            } else {
                return Response::json(['ok' => false, 'error' => 'Choose a photo to upload.'], 422);
            }
        } catch (\RuntimeException $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
        return Response::json(['ok' => true, 'avatar' => $av]);
    }

    private function avatarRemove(Request $request, int $userId): Response
    {
        if (!$this->verifyCsrf($request)) return Response::json(['ok' => false, 'error' => 'Security check failed. Reload the page and try again.'], 419);
        $this->app->make(\App\Services\AvatarService::class)->remove($userId);
        return Response::json(['ok' => true, 'avatar' => null]);
    }
}
