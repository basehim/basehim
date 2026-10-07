<?php
declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Http\Controllers\Controller;
use App\Services\AuthSecurityService;
use App\Services\AuthService;
use App\Services\TwoFactorService;

class AuthController extends Controller
{
    /** Minutes allowed between the password and the emailed code. */
    private const PENDING_MINUTES = 15;

    /**
     * Failed passwords from one address, across all accounts, per 15 minutes,
     * after which everyone from that address answers the security question.
     * A question rather than a refusal: on hosts behind a proxy or CDN every
     * visitor can share one address, and refusing would let one attacker shut
     * every user out.
     */
    private const IP_FAIL_LIMIT = 40;

    public function showLogin(Request $request): Response
    {
        $session = $this->app->make(Session::class);
        // "Use a different account" from the code screen.
        if ($request->query('switch') !== null) {
            $session->forget('two_factor_pending');
        }
        // Already signed in (and the session is still valid): go to the dashboard.
        if ($this->app->make(AuthService::class)->sessionUser()) {
            return $this->redirect('/admin/dashboard');
        }

        $auth = $this->authCfg();
        $login = trim((string) $request->query('u', (string) $session->get('last_login_id', '')));
        $ip = $this->clientIp($request);

        // A captcha once this visitor has failed enough passwords for this
        // account. The answer stays in the session and is checked once.
        $captcha = null;
        $locked = 0;
        $sec = $this->app->make(AuthSecurityService::class);
        if (($login !== '' && $sec->captchaRequired($login, $ip, $auth['login_attempt_limit']))
            || $sec->ipFailures($ip) >= self::IP_FAIL_LIMIT) {
            $captcha = $this->newCaptcha();
        }
        if ($login !== '') $locked = $sec->lockedSeconds($login, $ip);

        return $this->view('auth.login', [
            'title' => 'Sign in',
            'csrf' => $session->csrfToken(),
            'allowRegistration' => !empty($auth['allow_registration']),
            'rememberMe' => !empty($auth['remember_me']),
            'honeypot' => !empty($auth['honeypot']),
            'needCaptcha' => $captcha !== null,
            'captcha' => $captcha,
            'lastLogin' => $login,
            'locked' => $locked,
            'canUnlock' => $locked > 0 && !empty($auth['otp_enabled']),
        ]);
    }

    public function login(Request $request): Response
    {
        $session = $this->app->make(Session::class);

        if (!$this->verifyCsrf($request)) {
            $this->flash('error', 'Your session expired. Please try again.');
            return $this->redirect('/admin/login');
        }

        $auth = $this->authCfg();
        $ip = $this->clientIp($request);

        // Honeypot — a hidden field only a bot would fill. Reject silently.
        if (!empty($auth['honeypot']) && trim((string) $request->input('website', '')) !== '') {
            $this->flash('error', 'Invalid credentials.');
            return $this->redirect('/admin/login');
        }

        $login = mb_substr(trim((string) $request->input('login', '')), 0, 190);
        $password = (string) $request->input('password', '');
        $session->set('last_login_id', $login);

        if ($login === '' || $password === '') {
            $this->flash('error', 'Please enter both email/username and password.');
            return $this->redirect('/admin/login');
        }

        /** @var AuthSecurityService $sec */
        $sec = $this->app->make(AuthSecurityService::class);

        // Locked after repeated failures for this account from this address.
        if (($wait = $sec->lockedSeconds($login, $ip)) > 0) {
            $this->flash('error', $this->lockMessage($wait));
            return $this->redirect('/admin/login');
        }

        // If a captcha is required, check it BEFORE the password. A wrong or
        // missing answer counts as a captcha failure and may escalate to an
        // emailed unlock code.
        if ($sec->captchaRequired($login, $ip, $auth['login_attempt_limit']) || $sec->ipFailures($ip) >= self::IP_FAIL_LIMIT) {
            if (!$this->checkCaptcha((string) $request->input('captcha', ''))) {
                $sec->recordCaptchaFailure($login, $ip);
                if (!empty($auth['otp_enabled'])
                    && $sec->otpRequired($login, $ip, $auth['login_attempt_limit'], $auth['captcha_fail_limit'])) {
                    return $this->beginOtp($sec, $login, $ip);
                }
                $this->flash('error', 'Incorrect answer to the security question. Please try again.');
                return $this->redirect('/admin/login');
            }
        }

        /** @var AuthService $authSvc */
        $authSvc = $this->app->make(AuthService::class);
        $result = $authSvc->attemptDetailed($login, $password);

        if (empty($result['ok'])) {
            // Correct password but the account is blocked — say why.
            if (($result['reason'] ?? '') === 'blocked') {
                $status = (string) ($result['status'] ?? 'inactive');
                $messages = [
                    'suspended' => 'Your account has been suspended. Please contact an administrator.',
                    'inactive'  => 'Your account is inactive. Please contact an administrator to reactivate it.',
                    'archived'  => 'Your account has been archived and can no longer sign in. Please contact an administrator.',
                    'pending'   => 'Your account is pending approval. You will be able to sign in once it is activated.',
                ];
                $msg = $messages[$status] ?? 'Your account is not permitted to sign in. Please contact an administrator.';
                $sec->clear($login, $ip);
                try {
                    if (!empty($result['user'])) {
                        \App\Services\ActivityLogService::record((int) $result['user']['id'], 'auth.login_blocked', 'user', (int) $result['user']['id'], 'Blocked: ' . $status);
                    }
                } catch (\Throwable) {}
                $this->flash('error', $msg);
                return $this->redirect('/admin/login');
            }

            // Genuinely wrong credentials.
            $sec->recordFailure($login, $ip, $auth['lockout_after'], $auth['lockout_minutes']);
            try {
                $known = $this->findUser($login);
                if ($known) {
                    \App\Services\ActivityLogService::record((int) $known['id'], 'auth.login_failed', 'user', (int) $known['id'], 'Invalid credentials');
                }
            } catch (\Throwable) {}
            if (($wait = $sec->lockedSeconds($login, $ip)) > 0) {
                $this->flash('error', $this->lockMessage($wait));
            } else {
                $this->flash('error', 'Invalid credentials.');
            }
            return $this->redirect('/admin/login');
        }

        $user = $result['user'];
        $sec->clear($login, $ip);
        $session->forget('last_login_id');
        $session->forget('login_captcha');
        $remember = !empty($request->input('remember')) && !empty($auth['remember_me']);

        // Two-step verification: the password was right, now the emailed code.
        /** @var TwoFactorService $tf */
        $tf = $this->app->make(TwoFactorService::class);
        if ($tf->applies($user) && !$tf->isTrusted($user, (string) ($_COOKIE[TwoFactorService::TRUST_COOKIE] ?? ''))) {
            return $this->beginTwoFactor($tf, $user, $remember, $ip);
        }

        $this->finishLogin($user, $remember);
        return $this->redirect($this->intendedUrl());
    }

    // ==================================================================
    // Two-step verification at sign-in
    // ==================================================================

    private function beginTwoFactor(TwoFactorService $tf, array $user, bool $remember, string $ip): Response
    {
        $session = $this->app->make(Session::class);
        $session->regenerate();
        $session->set('two_factor_pending', ['uid' => (int) $user['id'], 'remember' => $remember, 'at' => time()]);

        $r = $tf->send($user, 'login', $ip);
        if ($r['ok'] || ($r['error'] ?? '') === 'wait') {
            // The code screen itself says where the code went.
        } else {
            $this->flash('error', TwoFactorService::sendError($r));
        }
        try { \App\Services\ActivityLogService::record((int) $user['id'], 'auth.two_factor_sent', 'user', (int) $user['id'], 'Password accepted; sign-in code requested'); } catch (\Throwable) {}
        return $this->redirect('/admin/login/verify');
    }

    /** GET /admin/login/verify */
    public function showVerify(Request $request): Response
    {
        [$pending, $user] = $this->pendingTwoFactor();
        if (!$user) return $this->redirect('/admin/login');
        $session = $this->app->make(Session::class);
        $tf = $this->app->make(TwoFactorService::class);
        return $this->view('auth.otp', [
            'title' => 'Enter your sign-in code',
            'mode' => 'two_factor',
            'csrf' => $session->csrfToken(),
            'email' => self::maskEmail((string) $user['email']),
            'trustDays' => $tf->trustDays(),
        ]);
    }

    /** POST /admin/login/verify */
    public function verifyTwoFactor(Request $request): Response
    {
        if (!$this->verifyCsrf($request)) {
            $this->flash('error', 'Your session expired. Please try again.');
            return $this->redirect('/admin/login/verify');
        }
        [$pending, $user] = $this->pendingTwoFactor();
        if (!$user) return $this->redirect('/admin/login');

        /** @var TwoFactorService $tf */
        $tf = $this->app->make(TwoFactorService::class);
        $result = $tf->verify((int) $user['id'], 'login', (string) $request->input('code', ''));
        if ($result !== 'ok') {
            try { \App\Services\ActivityLogService::record((int) $user['id'], 'auth.two_factor_failed', 'user', (int) $user['id'], 'Wrong sign-in code (' . $result . ')'); } catch (\Throwable) {}
            $this->flash('error', match ($result) {
                'locked'  => 'Too many wrong codes. Code entry is paused for 30 minutes.',
                'expired' => 'That code has expired or was entered wrong too many times. Send a new code.',
                default   => 'That code is not right. Check the most recent email and try again.',
            });
            return $this->redirect('/admin/login/verify');
        }

        $session = $this->app->make(Session::class);
        $session->forget('two_factor_pending');
        $this->finishLogin($user, !empty($pending['remember']));

        $days = $tf->trustDays();
        if ($days > 0 && !empty($request->input('trust'))) {
            $this->setCookie(TwoFactorService::TRUST_COOKIE, $tf->trustToken($user, $days), $days);
        }
        return $this->redirect($this->intendedUrl());
    }

    /** POST /admin/login/verify/resend */
    public function resendTwoFactor(Request $request): Response
    {
        if (!$this->verifyCsrf($request)) {
            $this->flash('error', 'Your session expired. Please try again.');
            return $this->redirect('/admin/login/verify');
        }
        [, $user] = $this->pendingTwoFactor();
        if (!$user) return $this->redirect('/admin/login');
        $r = $this->app->make(TwoFactorService::class)->send($user, 'login', $this->clientIp($request));
        if ($r['ok']) {
            $this->flash('success', 'A new code is on its way to ' . self::maskEmail((string) $user['email']) . '.');
        } else {
            $this->flash('error', TwoFactorService::sendError($r));
        }
        return $this->redirect('/admin/login/verify');
    }

    /**
     * The sign-in waiting for its code: [pending, user], or [null, null] with
     * a message when there is none, it has expired, or the account changed.
     */
    private function pendingTwoFactor(): array
    {
        $session = $this->app->make(Session::class);
        $p = $session->get('two_factor_pending');
        if (!is_array($p) || empty($p['uid'])) {
            $this->flash('error', 'Please sign in first.');
            return [null, null];
        }
        if ((int) ($p['at'] ?? 0) < time() - self::PENDING_MINUTES * 60) {
            $session->forget('two_factor_pending');
            $this->flash('error', 'That sign-in took too long. Please enter your password again.');
            return [null, null];
        }
        try {
            $user = $this->app->make(\App\Services\UserService::class)->find((int) $p['uid']);
        } catch (\Throwable) { $user = null; }
        if (!$user || ($user['status'] ?? '') !== 'active') {
            $session->forget('two_factor_pending');
            $this->flash('error', 'This account cannot sign in. Please contact an administrator.');
            return [null, null];
        }
        return [$p, $user];
    }

    // ==================================================================
    // Emailed unlock code after repeated failures
    // ==================================================================

    /**
     * Email a one-time unlock code to the account owner and show the code
     * screen. A correct code clears the failed attempts; the password is
     * still needed afterwards. (Before 1.2.33 the code signed the visitor in
     * directly, so mailbox access alone was enough to enter the admin.)
     */
    private function beginOtp(AuthSecurityService $sec, string $login, string $ip, bool $requested = false): Response
    {
        $session = $this->app->make(Session::class);
        $session->set('otp_login_id', $login);
        try { $user = $this->findUser($login); } catch (\Throwable) { $user = null; }

        $neutral = $requested
            ? 'If this is a valid account, an unlock code has been emailed to its owner.'
            : 'Too many attempts. If this is a valid account, an unlock code has been emailed to its owner.';
        // Neutral message whether or not the account exists.
        if (!$user || ($user['status'] ?? 'active') !== 'active' || empty($user['email'])) {
            $this->flash('info', $neutral);
            return $this->redirect('/admin/login/otp');
        }

        // One email a minute at most, however often the form is submitted.
        if (!$sec->otpSentWithin($login, $ip, 60)) {
            $code = $sec->generateOtp($login, $ip, (int) $user['id']);
            try {
                $site = (string) (self::site()['name']);
                $name = htmlspecialchars((string) ($user['display_name'] ?: $user['username']));
                $this->app->make(\App\Services\Mailer::class)->sendTemplate(
                    (string) $user['email'],
                    $code . ' is your ' . $site . ' unlock code',
                    'Unlock your sign-in',
                    "<p>Hi {$name},</p>"
                    . '<p>We noticed several failed sign-in attempts on your account, so password entry was paused. Enter this code on the verification screen to unlock it:</p>'
                    . '<p style="text-align:center;margin:22px 0;"><span style="display:inline-block;background:#0f172a;color:#fff;'
                    . 'font-size:26px;letter-spacing:6px;font-weight:bold;padding:12px 22px;border-radius:10px;">' . $code . '</span></p>'
                    . '<p style="font-size:12px;color:#64748b;">This code expires in 10 minutes. You will still need your password. If you did not try to sign in, someone may be guessing your password — consider changing it.</p>'
                );
            } catch (\Throwable) {}
        }

        $this->flash('info', $neutral);
        return $this->redirect('/admin/login/otp');
    }

    /**
     * POST /admin/login/unlock — the account owner, locked out by someone
     * else's guessing, asks for an unlock code instead of waiting.
     */
    public function requestUnlock(Request $request): Response
    {
        if (!$this->verifyCsrf($request)) {
            $this->flash('error', 'Your session expired. Please try again.');
            return $this->redirect('/admin/login');
        }
        $login = (string) $this->app->make(Session::class)->get('last_login_id', '');
        $ip = $this->clientIp($request);
        $sec = $this->app->make(AuthSecurityService::class);
        if ($login === '' || empty($this->authCfg()['otp_enabled']) || $sec->lockedSeconds($login, $ip) <= 0) {
            return $this->redirect('/admin/login');
        }
        return $this->beginOtp($sec, $login, $ip, true);
    }

    /** GET /admin/login/otp — enter the emailed unlock code. */
    public function showOtp(Request $request): Response
    {
        $session = $this->app->make(Session::class);
        if ((string) $session->get('otp_login_id', '') === '') {
            return $this->redirect('/admin/login');
        }
        return $this->view('auth.otp', [
            'title' => 'Enter unlock code',
            'mode' => 'unlock',
            'csrf' => $session->csrfToken(),
        ]);
    }

    /** POST /admin/login/otp */
    public function verifyOtp(Request $request): Response
    {
        $session = $this->app->make(Session::class);
        if (!$this->verifyCsrf($request)) {
            $this->flash('error', 'Your session expired. Please try again.');
            return $this->redirect('/admin/login/otp');
        }
        $login = (string) $session->get('otp_login_id', '');
        $ip = $this->clientIp($request);
        if ($login === '') {
            $this->flash('error', 'Your verification session expired. Please sign in again.');
            return $this->redirect('/admin/login');
        }

        /** @var AuthSecurityService $sec */
        $sec = $this->app->make(AuthSecurityService::class);
        $userId = $sec->verifyOtp($login, $ip, (string) $request->input('code', ''));
        if (!$userId) {
            $this->flash('error', 'Invalid or expired code.');
            return $this->redirect('/admin/login/otp');
        }

        $sec->clear($login, $ip);
        $session->forget('otp_login_id');
        $session->set('last_login_id', $login);
        try { \App\Services\ActivityLogService::record($userId, 'auth.unlocked', 'user', $userId, 'Sign-in unlocked with emailed code'); } catch (\Throwable) {}
        $this->flash('success', 'Code accepted — sign-in is unlocked. Enter your password to continue.');
        return $this->redirect('/admin/login');
    }

    /**
     * Finalise a login: session, remember-me cookie, activity log, flash.
     */
    private function finishLogin(array $user, bool $remember): void
    {
        /** @var AuthService $authSvc */
        $authSvc = $this->app->make(AuthService::class);
        $authSvc->loginSession($user);
        try { $this->app->make(TwoFactorService::class)->consumePass($user); } catch (\Throwable) {}

        if ($remember) {
            try {
                $cookie = $this->app->make(AuthSecurityService::class)->issueRemember((int) $user['id'], 30);
                $this->setCookie(AuthSecurityService::REMEMBER_COOKIE, $cookie, 30);
            } catch (\Throwable) {}
        }

        \App\Services\ActivityLogService::record((int) $user['id'], 'auth.login_success', 'user', (int) $user['id'], 'Signed in');
        $this->flash('success', 'Welcome back, ' . ($user['display_name'] ?: $user['username']) . '!');
    }

    // ==================================================================
    // Sign out
    // ==================================================================

    /**
     * POST /admin/logout with the CSRF token signs out. Anything else — a GET,
     * or a POST without a valid token — shows a confirmation page, so another
     * site cannot sign people out with a hidden image or form.
     */
    public function logout(Request $request): Response
    {
        $session = $this->app->make(Session::class);
        if (!$request->isMethod('POST') || !$this->verifyCsrf($request)) {
            return $this->view('auth.logout', [
                'title' => 'Sign out',
                'csrf' => $session->csrfToken(),
                'user' => $this->user(),
            ]);
        }

        /** @var AuthService $auth */
        $auth = $this->app->make(AuthService::class);
        if ($uid = $this->userId()) {
            \App\Services\ActivityLogService::record($uid, 'auth.logout', 'user', $uid, 'Signed out');
        }
        $cookie = (string) ($_COOKIE[AuthSecurityService::REMEMBER_COOKIE] ?? '');
        if ($cookie !== '') {
            try { $this->app->make(AuthSecurityService::class)->revokeRememberCookie($cookie); } catch (\Throwable) {}
            $this->setCookie(AuthSecurityService::REMEMBER_COOKIE, '', -1);
        }
        $auth->logoutSession();
        $this->flash('success', 'You have been signed out.');
        return $this->redirect('/admin/login');
    }

    // ==================================================================
    // Password reset
    // ==================================================================

    public function showForgot(Request $request): Response
    {
        $session = $this->app->make(Session::class);
        return $this->view('auth.forgot', [
            'title' => 'Forgot password',
            'csrf' => $session->csrfToken(),
        ]);
    }

    public function sendReset(Request $request): Response
    {
        if (!$this->verifyCsrf($request)) {
            $this->flash('error', 'Your session expired. Please try again.');
            return $this->redirect('/admin/forgot-password');
        }

        $email = strtolower(trim((string) $request->input('email', '')));
        // Always the same response, whether or not the account exists.
        $neutral = function (): Response {
            $this->flash('info', 'If an account with that email exists, a reset link has been sent.');
            return $this->redirect('/admin/login');
        };
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $neutral();
        }
        // Per-address limit on top of the per-email one in PasswordResetService.
        if (!$this->app->make(AuthSecurityService::class)->throttle('reset', $this->clientIp($request), 10, 3600)) {
            return $neutral();
        }

        /** @var \App\Services\UserService $users */
        $users = $this->app->make(\App\Services\UserService::class);
        $user = $users->findByEmail($email);
        if (!$user || ($user['status'] ?? 'active') !== 'active') {
            return $neutral();
        }

        /** @var \App\Services\PasswordResetService $resets */
        $resets = $this->app->make(\App\Services\PasswordResetService::class);
        $token = $resets->createToken($email);
        if ($token === null) {
            return $neutral();
        }

        // The link is built from the configured site address, not the Host
        // header of this request — otherwise anyone could request a reset
        // with a forged Host and receive the victim's token at their domain.
        $link = self::origin() . '/admin/reset-password/' . $token;

        try {
            $name = htmlspecialchars((string) ($user['display_name'] ?: $user['username']));
            $this->app->make(\App\Services\Mailer::class)->sendTemplate(
                (string) $user['email'],
                'Reset your password',
                'Password reset requested',
                "<p>Hi {$name},</p>"
                . '<p>Someone requested a password reset for your account. Click the button below to choose a new password. '
                . 'This link expires in <strong>60 minutes</strong> and can be used once.</p>'
                . '<p style="text-align:center;margin:22px 0;"><a href="' . htmlspecialchars($link) . '" '
                . 'style="display:inline-block;background:#2563eb;color:#ffffff;text-decoration:none;padding:11px 26px;'
                . 'border-radius:9px;font-weight:bold;font-size:14px;">Reset Password</a></p>'
                . '<p style="font-size:12px;color:#64748b;">If the button does not work, paste this link into your browser:<br>'
                . '<a href="' . htmlspecialchars($link) . '">' . htmlspecialchars($link) . '</a></p>'
                . '<p style="font-size:12px;color:#64748b;">If you did not request this, you can safely ignore this email — your password will not change.</p>'
            );
        } catch (\Throwable) {}

        return $neutral();
    }

    public function showReset(Request $request, string $token): Response
    {
        $session = $this->app->make(Session::class);
        $resets = $this->app->make(\App\Services\PasswordResetService::class);
        if ($resets->validateToken($token) === null) {
            $this->flash('error', 'That reset link is invalid or has expired. Please request a new one.');
            return $this->redirect('/admin/forgot-password');
        }
        return $this->view('auth.reset', [
            'title' => 'Choose a new password',
            'token' => $token,
            'csrf'  => $session->csrfToken(),
        ]);
    }

    public function reset(Request $request): Response
    {
        $token = (string) $request->input('token', '');
        if (!$this->verifyCsrf($request)) {
            $this->flash('error', 'Your session expired. Please try again.');
            return $this->redirect(preg_match('/^[a-f0-9]{16,128}$/i', $token) ? '/admin/reset-password/' . $token : '/admin/forgot-password');
        }

        $password = (string) $request->input('password', '');
        $confirm = (string) $request->input('password_confirm', '');

        /** @var \App\Services\PasswordResetService $resets */
        $resets = $this->app->make(\App\Services\PasswordResetService::class);
        $row = $resets->validateToken($token);
        if (!$row) {
            $this->flash('error', 'That reset link is invalid or has expired. Please request a new one.');
            return $this->redirect('/admin/forgot-password');
        }
        if (strlen($password) < 8) {
            $this->flash('error', 'Password must be at least 8 characters.');
            return $this->redirect('/admin/reset-password/' . $token);
        }
        if ($password !== $confirm) {
            $this->flash('error', 'Passwords do not match.');
            return $this->redirect('/admin/reset-password/' . $token);
        }

        /** @var \App\Services\UserService $users */
        $users = $this->app->make(\App\Services\UserService::class);
        $user = $users->findByEmail((string) $row['email']);
        if (!$user) {
            $this->flash('error', 'Account not found.');
            return $this->redirect('/admin/forgot-password');
        }

        // UserService ends every other session, remember-me cookie and API
        // token of the account when its password changes.
        $users->update((int) $user['id'], ['password' => $password]);
        $resets->consume($row);
        $sec = $this->app->make(AuthSecurityService::class);
        $sec->clearIdentifier((string) $user['email']);
        $sec->clearIdentifier((string) $user['username']);
        \App\Services\ActivityLogService::record((int) $user['id'], 'auth.password_reset', 'user', (int) $user['id'], 'Password reset via email link');

        try {
            $this->app->make(\App\Services\Mailer::class)->sendTemplate(
                (string) $user['email'],
                'Your password was changed',
                'Password changed',
                '<p>Your account password was just changed via the password-reset flow, and every other device was signed out.</p>'
                . '<p style="font-size:12px;color:#64748b;">If this was not you, reset your password again immediately and contact your site administrator.</p>'
            );
        } catch (\Throwable) {}

        $this->flash('success', 'Password updated — you can sign in now.');
        return $this->redirect('/admin/login');
    }

    // ==================================================================
    // Registration — gated by the "allow_registration" setting.
    // ==================================================================

    public function showRegister(Request $request): Response
    {
        $session = $this->app->make(Session::class);
        if ($this->app->make(AuthService::class)->sessionUser()) return $this->redirect('/admin/dashboard');

        $auth = $this->authCfg();
        if (empty($auth['allow_registration'])) {
            $this->flash('error', 'Registration is currently disabled.');
            return $this->redirect('/admin/login');
        }
        return $this->view('auth.register', [
            'title' => 'Create account',
            'csrf' => $session->csrfToken(),
            'honeypot' => !empty($auth['honeypot']),
        ]);
    }

    public function register(Request $request): Response
    {
        $auth = $this->authCfg();

        if (empty($auth['allow_registration'])) {
            $this->flash('error', 'Registration is currently disabled.');
            return $this->redirect('/admin/login');
        }
        if (!$this->verifyCsrf($request)) {
            $this->flash('error', 'Your session expired. Please try again.');
            return $this->redirect('/admin/register');
        }
        if (!empty($auth['honeypot']) && trim((string) $request->input('website', '')) !== '') {
            $this->flash('success', 'Account created. You can sign in now.');
            return $this->redirect('/admin/login');
        }
        if (!$this->app->make(AuthSecurityService::class)->throttle('register', $this->clientIp($request), 5, 3600)) {
            $this->flash('error', 'Too many accounts have been created from your network recently. Please try again later.');
            return $this->redirect('/admin/register');
        }

        $username = trim((string) $request->input('username', ''));
        $email = strtolower(trim((string) $request->input('email', '')));
        $password = (string) $request->input('password', '');
        $displayName = mb_substr(trim((string) $request->input('display_name', '')), 0, 120) ?: $username;

        $errors = [];
        if ($username === '' || !preg_match('/^[a-zA-Z0-9_.-]{3,32}$/', $username)) {
            $errors[] = 'Username must be 3–32 characters (letters, numbers, _ . -).';
        }
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
            $errors[] = 'Please enter a valid email address.';
        }
        if (strlen($password) < 8) {
            $errors[] = 'Password must be at least 8 characters.';
        }
        if ($errors) {
            $this->flash('error', implode(' ', $errors));
            return $this->redirect('/admin/register');
        }

        /** @var \App\Services\UserService $users */
        $users = $this->app->make(\App\Services\UserService::class);
        if ($users->findByEmail($email) || $users->findByUsername($username)) {
            $this->flash('error', 'An account with that email or username already exists.');
            return $this->redirect('/admin/register');
        }

        try {
            $id = $users->create([
                'username'     => $username,
                'email'        => $email,
                'password'     => $password,
                'display_name' => $displayName,
                'role'         => self::registrationRole($this->app, (string) ($auth['default_role'] ?? 'subscriber')),
                'status'       => 'active',
            ]);
        } catch (\Throwable) {
            $this->flash('error', 'Could not create your account. Please try again.');
            return $this->redirect('/admin/register');
        }

        if (!empty($auth['welcome_email'])) {
            self::sendWelcomeEmailStatic($this->app, $email, $displayName);
        }
        try { \App\Services\ActivityLogService::record((int) $id, 'auth.registered', 'user', (int) $id, 'Self-registered'); } catch (\Throwable) {}

        $this->flash('success', 'Account created — you can sign in now.');
        return $this->redirect('/admin/login');
    }

    /**
     * The role a self-registered account gets: the configured default, if it
     * exists and sits below the administrator level; otherwise subscriber.
     */
    public static function registrationRole(\App\Core\Application $app, string $role): string
    {
        try {
            /** @var \App\Services\AccessControl $ac */
            $ac = $app->make(\App\Services\AccessControl::class);
            if ($ac->roleExists($role) && $ac->levelOf($role) < $ac->levelOf('admin')) return $role;
        } catch (\Throwable) {}
        return 'subscriber';
    }

    /** Static form so other controllers (e.g. admin user creation) can reuse it. */
    public static function sendWelcomeEmailStatic(\App\Core\Application $app, string $email, string $displayName): void
    {
        try {
            $siteName = (string) (self::site()['name']);
            $loginUrl = self::origin() . '/admin/login';
            $name = htmlspecialchars($displayName);
            $safeSite = htmlspecialchars($siteName);
            $app->make(\App\Services\Mailer::class)->sendTemplate(
                $email,
                'Welcome to ' . $siteName,
                'Welcome aboard, ' . $displayName . '!',
                "<p>Hi {$name},</p>"
                . "<p>Your account on <strong>{$safeSite}</strong> is ready. We're glad to have you.</p>"
                . '<p style="text-align:center;margin:22px 0;"><a href="' . htmlspecialchars($loginUrl) . '" '
                . 'style="display:inline-block;background:#2563eb;color:#ffffff;text-decoration:none;padding:11px 26px;'
                . 'border-radius:9px;font-weight:bold;font-size:14px;">Sign in</a></p>'
                . '<p style="font-size:12px;color:#64748b;">If you didn\'t create this account, please ignore this email.</p>'
            );
        } catch (\Throwable) {}
    }

    // ==================================================================
    // Site identity and addresses (also used by the sign-in views)
    // ==================================================================

    /**
     * Who this sign-in page belongs to: name, description, icon or logo,
     * address and accent colour, from General settings and the Customizer.
     *
     * @return array{name:string, tagline:string, logo:string, icon:string, url:string, host:string, accent:string, accentInk:string, initial:string}
     */
    public static function site(): array
    {
        static $site = null;
        if ($site !== null) return $site;
        $g = $a = [];
        try {
            $settings = \App\Core\Application::getInstance()->make(\App\Services\SettingService::class);
            $g = $settings->getGroup('general');
            $a = $settings->getGroup('appearance');
        } catch (\Throwable) {}
        $name = trim((string) ($g['site_title'] ?? '')) ?: 'Basehim';
        $img = static function ($u): string {
            $u = trim((string) $u);
            return ($u !== '' && (preg_match('#^https?://#i', $u) || ($u[0] === '/' && !str_starts_with($u, '//')))) ? $u : '';
        };
        $accent = (string) ($a['primary_color'] ?? '');
        if (!preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $accent)) $accent = '#2563eb';
        $hex = ltrim($accent, '#');
        if (strlen($hex) === 3) $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        $lum = 0.0;
        foreach ([0 => 0.2126, 2 => 0.7152, 4 => 0.0722] as $i => $w) {
            $c = hexdec(substr($hex, $i, 2)) / 255;
            $lum += $w * ($c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4);
        }
        $url = self::origin() . '/';
        $site = [
            'name'      => $name,
            'tagline'   => trim((string) ($g['tagline'] ?? '')),
            'logo'      => $img($a['logo_url'] ?? ''),
            'icon'      => $img($a['favicon_url'] ?? ''),
            'url'       => $url,
            'host'      => (string) (parse_url($url, PHP_URL_HOST) ?: ''),
            'accent'    => '#' . strtolower($hex),
            'accentInk' => $lum > 0.45 ? '#0f172a' : '#ffffff',
            'initial'   => mb_strtoupper(mb_substr($name, 0, 1)),
        ];
        return $site;
    }

    /**
     * Absolute address of this installation (scheme, host, install base), for
     * links that leave the site in emails. Uses APP_URL from .env when it is
     * set to a real address; the request's Host header only as a fallback,
     * and then only when it looks like a host name.
     */
    public static function origin(): string
    {
        $base = defined('BASEHIM_BASE') ? rtrim((string) BASEHIM_BASE, '/') : '';
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') ? 'https' : 'http';
        $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
        if (!preg_match('/^[a-z0-9.-]+(:\d{1,5})?$/', $host)) $host = strtolower((string) ($_SERVER['SERVER_NAME'] ?? 'localhost'));
        $isLocal = static fn(string $h): bool => (bool) preg_match('/^(localhost|127\.\d+\.\d+\.\d+|\[?::1\]?)(:\d+)?$/', $h);

        $env = (string) (\App\Core\Env::get('APP_URL', '') ?? '');
        $p = $env !== '' ? parse_url($env) : false;
        if (is_array($p) && !empty($p['host']) && in_array(strtolower((string) ($p['scheme'] ?? '')), ['http', 'https'], true)) {
            $envHost = strtolower((string) $p['host']);
            // A site installed on localhost and moved without updating APP_URL
            // would mail links to localhost; only then does the request decide.
            if (!$isLocal($envHost) || $isLocal($host)) {
                return strtolower((string) $p['scheme']) . '://' . $envHost . (!empty($p['port']) ? ':' . (int) $p['port'] : '') . $base;
            }
        }
        return $scheme . '://' . $host . $base;
    }

    /** "m•••@example.com" */
    public static function maskEmail(string $email): string
    {
        $at = strrpos($email, '@');
        if ($at === false) return '•••';
        $local = substr($email, 0, $at);
        $shown = mb_substr($local, 0, min(2, max(1, mb_strlen($local) - 1)));
        return $shown . str_repeat('•', 3) . substr($email, $at);
    }

    // ==================================================================
    // Helpers
    // ==================================================================

    /** Authentication settings with sane defaults. */
    private function authCfg(): array
    {
        try {
            $g = $this->app->make(\App\Services\SettingService::class)->getGroup('authorization');
        } catch (\Throwable) { $g = []; }
        return [
            'allow_registration'  => !empty($g['allow_registration']),
            'default_role'        => (string) ($g['default_role'] ?? 'subscriber'),
            'remember_me'         => !isset($g['remember_me']) ? true : !empty($g['remember_me']),
            'honeypot'            => !isset($g['honeypot']) ? true : !empty($g['honeypot']),
            'welcome_email'       => !empty($g['welcome_email']),
            'otp_enabled'         => !isset($g['otp_enabled']) ? true : !empty($g['otp_enabled']),
            'login_attempt_limit' => max(1, (int) ($g['login_attempt_limit'] ?? 3)),
            'captcha_fail_limit'  => max(1, (int) ($g['captcha_fail_limit'] ?? 3)),
            'lockout_after'       => max(3, min(50, (int) ($g['lockout_after'] ?? 10))),
            'lockout_minutes'     => max(1, min(1440, (int) ($g['lockout_minutes'] ?? 15))),
        ];
    }

    private function findUser(string $login): ?array
    {
        /** @var \App\Services\UserService $users */
        $users = $this->app->make(\App\Services\UserService::class);
        return filter_var($login, FILTER_VALIDATE_EMAIL) ? $users->findByEmail($login) : $users->findByUsername($login);
    }

    private function lockMessage(int $seconds): string
    {
        $m = max(1, (int) ceil($seconds / 60));
        return 'Too many failed attempts. Sign-in for this account is paused for ' . $m . ' minute' . ($m === 1 ? '' : 's')
            . '. You can reset your password in the meantime.';
    }

    /** A new security question; its answer is kept in the session for one check. */
    private function newCaptcha(): array
    {
        $c = $this->app->make(AuthSecurityService::class)->makeChallenge();
        $this->app->make(Session::class)->set('login_captcha', [
            'h'   => hash_hmac('sha256', (string) $c['answer'], AuthService::secret()),
            'exp' => time() + 900,
        ]);
        return ['question' => $c['question']];
    }

    /** Check the answer against the session; every question is good for one try. */
    private function checkCaptcha(string $answer): bool
    {
        $session = $this->app->make(Session::class);
        $c = $session->get('login_captcha');
        $session->forget('login_captcha');
        $answer = trim($answer);
        if (!is_array($c) || empty($c['h']) || (int) ($c['exp'] ?? 0) < time() || !preg_match('/^-?\d{1,3}$/', $answer)) return false;
        return hash_equals((string) $c['h'], hash_hmac('sha256', (string) (int) $answer, AuthService::secret()));
    }

    private function clientIp(Request $request): string
    {
        return substr((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
    }

    private function setCookie(string $name, string $value, int $days): void
    {
        $expires = $days < 0 ? time() - 3600 : time() + $days * 86400;
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        $basePath = defined('BASEHIM_BASE') ? (rtrim((string) BASEHIM_BASE, '/') ?: '/') : '/';
        setcookie($name, $value, [
            'expires'  => $expires,
            'path'     => $basePath,
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    /**
     * The post-login destination: the page the user originally tried to reach
     * (saved by the auth middleware), else the dashboard. Read-once, and only
     * safe same-site admin paths are honored (prevents open-redirect abuse).
     */
    private function intendedUrl(): string
    {
        $fallback = '/admin/dashboard';
        try {
            $session = $this->app->make(Session::class);
            $target = (string) $session->get('intended_url', '');
            $session->forget('intended_url');
        } catch (\Throwable) {
            return $fallback;
        }
        if ($target === '') return $fallback;
        if ($target[0] !== '/' || str_starts_with($target, '//') || str_contains($target, '\\')) return $fallback;
        $bare = explode('?', $target)[0];
        $allowed = str_starts_with($bare, '/admin') || $bare === '/oauth/authorize';
        if (!$allowed) return $fallback;
        foreach (['/admin/login', '/admin/logout', '/admin/register'] as $s) {
            if ($bare === $s || str_starts_with($bare, $s . '/')) return $fallback;
        }
        return $target;
    }
}
