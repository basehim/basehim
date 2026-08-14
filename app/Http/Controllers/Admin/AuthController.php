<?php
declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Http\Controllers\Controller;
use App\Services\AuthService;

class AuthController extends Controller
{
    public function showLogin(Request $request): Response
    {
        $session = $this->app->make(Session::class);
        // If already authenticated, go to dashboard
        if ($session->get('user_id')) {
            return $this->redirect('/admin/dashboard');
        }

        /** @var \App\Services\SettingService $settings */
        $settings = $this->app->make(\App\Services\SettingService::class);
        $auth = $this->authCfg();

        // Decide whether this visitor already needs a captcha (persisted across
        // the redirect that follows a failed attempt).
        $login = trim((string) $request->query('u', (string) $session->get('last_login_id', '')));
        $ip = $this->clientIp($request);
        $needCaptcha = false;
        $captcha = null;
        if ($login !== '') {
            /** @var \App\Services\AuthSecurityService $sec */
            $sec = $this->app->make(\App\Services\AuthSecurityService::class);
            $needCaptcha = $sec->captchaRequired($login, $ip, $auth['login_attempt_limit']);
        }
        if ($needCaptcha) {
            $captcha = $this->app->make(\App\Services\AuthSecurityService::class)->makeCaptcha($this->captchaSecret());
        }

        return $this->view('auth.login', [
            'title' => 'Sign in',
            'csrf' => $session->csrfToken(),
            'allowRegistration' => !empty($auth['allow_registration']),
            'rememberMe' => !empty($auth['remember_me']),
            'honeypot' => !empty($auth['honeypot']),
            'needCaptcha' => $needCaptcha,
            'captcha' => $captcha,
            'lastLogin' => htmlspecialchars($login),
        ]);
    }

    public function login(Request $request): Response
    {
        $session = $this->app->make(Session::class);

        if (!$this->verifyCsrf($request)) {
            $this->flash('error', 'Security check failed. Please try again.');
            return $this->redirect('/admin/login');
        }

        $auth = $this->authCfg();
        $ip = $this->clientIp($request);

        // (5) Honeypot — a hidden field only a bot would fill. Reject silently.
        if (!empty($auth['honeypot']) && trim((string) $request->input('website', '')) !== '') {
            // Behave like a normal invalid login, no hint that we detected a bot.
            $this->flash('error', 'Invalid credentials.');
            return $this->redirect('/admin/login');
        }

        $login = trim((string)$request->input('login', ''));
        $password = (string)$request->input('password', '');
        $session->set('last_login_id', $login);

        if ($login === '' || $password === '') {
            $this->flash('error', 'Please enter both email/username and password.');
            return $this->redirect('/admin/login');
        }

        /** @var \App\Services\AuthSecurityService $sec */
        $sec = $this->app->make(\App\Services\AuthSecurityService::class);

        // (4) If a captcha is currently required, validate it BEFORE checking
        // the password. A wrong/missing captcha counts as a captcha failure and
        // may escalate to OTP.
        if ($sec->captchaRequired($login, $ip, $auth['login_attempt_limit'])) {
            $answer = (string) $request->input('captcha', '');
            $token  = (string) $request->input('captcha_token', '');
            if (!$sec->checkCaptcha($answer, $token, $this->captchaSecret())) {
                $sec->recordCaptchaFailure($login, $ip);
                // (7) Escalate to OTP once captcha has also been failed enough.
                if (!empty($auth['otp_enabled'])
                    && $sec->otpRequired($login, $ip, $auth['login_attempt_limit'], $auth['captcha_fail_limit'])) {
                    return $this->beginOtp($sec, $login, $ip);
                }
                $this->flash('error', 'Incorrect captcha answer. Please try again.');
                return $this->redirect('/admin/login');
            }
        }

        /** @var AuthService $authSvc */
        $authSvc = $this->app->make(AuthService::class);
        $result = $authSvc->attemptDetailed($login, $password);

        if (empty($result['ok'])) {
            // Correct password but the account is blocked — tell the user why,
            // rather than a misleading "invalid credentials".
            if (($result['reason'] ?? '') === 'blocked') {
                $status = (string) ($result['status'] ?? 'inactive');
                $messages = [
                    'suspended' => 'Your account has been suspended. Please contact an administrator.',
                    'inactive'  => 'Your account is inactive. Please contact an administrator to reactivate it.',
                    'archived'  => 'Your account has been archived and can no longer sign in. Please contact an administrator.',
                    'pending'   => 'Your account is pending approval. You will be able to sign in once it is activated.',
                ];
                $msg = $messages[$status] ?? 'Your account is not permitted to sign in. Please contact an administrator.';
                // Don't count a blocked-account attempt toward captcha/lockout —
                // the password was correct, so it isn't a brute-force signal.
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
            $sec->recordFailure($login, $ip);
            try {
                /** @var \App\Services\UserService $usersSvc */
                $usersSvc = $this->app->make(\App\Services\UserService::class);
                $known = filter_var($login, FILTER_VALIDATE_EMAIL)
                    ? $usersSvc->findByEmail($login)
                    : $usersSvc->findByUsername($login);
                if ($known) {
                    \App\Services\ActivityLogService::record((int) $known['id'], 'auth.login_failed', 'user', (int) $known['id'], 'Invalid credentials');
                }
            } catch (\Throwable) {}
            $this->flash('error', 'Invalid credentials.');
            return $this->redirect('/admin/login');
        }

        $user = $result['user'];

        // Success — clear counters and finalise the session.
        $sec->clear($login, $ip);
        $session->forget('last_login_id');
        $this->finishLogin($user, !empty($request->input('remember')) && !empty($auth['remember_me']));
        return $this->redirect($this->intendedUrl());
    }

    /**
     * Generate + email an OTP, then send the user to the OTP entry screen.
     */
    private function beginOtp(\App\Services\AuthSecurityService $sec, string $login, string $ip): Response
    {
        try {
            /** @var \App\Services\UserService $users */
            $users = $this->app->make(\App\Services\UserService::class);
            $user = filter_var($login, FILTER_VALIDATE_EMAIL)
                ? $users->findByEmail($login)
                : $users->findByUsername($login);
        } catch (\Throwable) { $user = null; }

        // Neutral message whether or not the account exists.
        if (!$user || ($user['status'] ?? 'active') !== 'active' || empty($user['email'])) {
            $this->flash('info', 'Too many attempts. If this is a valid account, a one-time code has been emailed.');
            return $this->redirect('/admin/login/otp');
        }

        $code = $sec->generateOtp($login, $ip, (int) $user['id']);
        try {
            /** @var \App\Services\Mailer $mailer */
            $mailer = $this->app->make(\App\Services\Mailer::class);
            $name = htmlspecialchars((string) ($user['display_name'] ?: $user['username']));
            $mailer->sendTemplate(
                (string) $user['email'],
                'Your sign-in verification code',
                'Verify your sign-in',
                "<p>Hi {$name},</p>"
                . '<p>We noticed several failed sign-in attempts on your account. To continue, enter this one-time code on the verification screen:</p>'
                . '<p style="text-align:center;margin:22px 0;"><span style="display:inline-block;background:#0f172a;color:#fff;'
                . 'font-size:26px;letter-spacing:6px;font-weight:bold;padding:12px 22px;border-radius:10px;">' . $code . '</span></p>'
                . '<p style="font-size:12px;color:#64748b;">This code expires in 10 minutes. If you didn\'t try to sign in, someone may have your password — consider resetting it.</p>'
            );
        } catch (\Throwable) {}

        $this->app->make(Session::class)->set('otp_login_id', $login);
        $this->flash('info', 'Too many attempts. A one-time code has been emailed to the account owner.');
        return $this->redirect('/admin/login/otp');
    }

    /** GET /admin/login/otp — enter the emailed code. */
    public function showOtp(Request $request): Response
    {
        $session = $this->app->make(Session::class);
        return $this->view('auth.otp', [
            'title' => 'Enter code',
            'csrf' => $session->csrfToken(),
        ]);
    }

    /** POST /admin/login/otp */
    public function verifyOtp(Request $request): Response
    {
        $session = $this->app->make(Session::class);
        if (!$this->verifyCsrf($request)) {
            $this->flash('error', 'Security check failed.');
            return $this->redirect('/admin/login/otp');
        }
        $login = (string) $session->get('otp_login_id', '');
        $ip = $this->clientIp($request);
        $code = (string) $request->input('code', '');
        if ($login === '') {
            $this->flash('error', 'Your verification session expired. Please sign in again.');
            return $this->redirect('/admin/login');
        }

        /** @var \App\Services\AuthSecurityService $sec */
        $sec = $this->app->make(\App\Services\AuthSecurityService::class);
        $userId = $sec->verifyOtp($login, $ip, $code);
        if (!$userId) {
            $this->flash('error', 'Invalid or expired code.');
            return $this->redirect('/admin/login/otp');
        }

        try {
            $user = $this->app->make(\App\Services\UserService::class)->find($userId);
        } catch (\Throwable) { $user = null; }
        if (!$user) {
            $this->flash('error', 'Account unavailable.');
            return $this->redirect('/admin/login');
        }

        $sec->clear($login, $ip);
        $session->forget('otp_login_id');
        $session->forget('last_login_id');
        $this->finishLogin($user, false);
        return $this->redirect($this->intendedUrl());
    }

    /**
     * Finalise a login: session, remember-me cookie, activity log, flash.
     */
    private function finishLogin(array $user, bool $remember): void
    {
        /** @var AuthService $authSvc */
        $authSvc = $this->app->make(AuthService::class);
        $authSvc->loginSession($user);

        if ($remember) {
            try {
                /** @var \App\Services\AuthSecurityService $sec */
                $sec = $this->app->make(\App\Services\AuthSecurityService::class);
                $cookie = $sec->issueRemember((int) $user['id'], 30);
                $this->setRememberCookie($cookie, 30);
            } catch (\Throwable) {}
        }

        \App\Services\ActivityLogService::record((int) $user['id'], 'auth.login_success', 'user', (int) $user['id'], 'Signed in');
        $this->flash('success', 'Welcome back, ' . $user['display_name'] . '!');
    }

    public function logout(Request $request): Response
    {
        /** @var AuthService $auth */
        $auth = $this->app->make(AuthService::class);
        if ($uid = $this->userId()) {
            \App\Services\ActivityLogService::record($uid, 'auth.logout', 'user', $uid, 'Signed out');
        }
        // Revoke + clear the remember-me cookie if present.
        $cookie = (string) ($_COOKIE[\App\Services\AuthSecurityService::REMEMBER_COOKIE] ?? '');
        if ($cookie !== '') {
            try {
                $this->app->make(\App\Services\AuthSecurityService::class)->revokeRememberCookie($cookie);
            } catch (\Throwable) {}
            $this->setRememberCookie('', -1);
        }
        $auth->logoutSession();
        return $this->redirect('/admin/login');
    }

    public function showForgot(Request $request): Response
    {
        $session = $this->app->make(Session::class);
        return $this->view('auth.forgot', [
            'title' => 'Forgot Password',
            'csrf' => $session->csrfToken(),
        ]);
    }

    public function sendReset(Request $request): Response
    {
        if (!$this->verifyCsrf($request)) {
            $this->flash('error', 'Security check failed. Please try again.');
            return $this->redirect('/admin/forgot-password');
        }

        $email = trim((string) $request->input('email', ''));
        // Always the same response, whether or not the account exists — this
        // prevents the form being used to probe for registered emails.
        $neutral = function (): Response {
            $this->flash('info', 'If an account with that email exists, a reset link has been sent.');
            return $this->redirect('/admin/login');
        };
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
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
            // Rate-limited — still answer neutrally.
            return $neutral();
        }

        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $basePath = defined('BASEHIM_BASE') ? rtrim((string) BASEHIM_BASE, '/') : '';
        $link = "{$scheme}://{$host}{$basePath}/admin/reset-password/{$token}";

        /** @var \App\Services\Mailer $mailer */
        $mailer = $this->app->make(\App\Services\Mailer::class);
        $name = htmlspecialchars((string) ($user['display_name'] ?: $user['username']));
        $mailer->sendTemplate(
            $email,
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

        return $neutral();
    }

    public function showReset(Request $request, string $token): Response
    {
        $session = $this->app->make(Session::class);
        /** @var \App\Services\PasswordResetService $resets */
        $resets = $this->app->make(\App\Services\PasswordResetService::class);
        $valid = $resets->validateToken($token) !== null;
        if (!$valid) {
            $this->flash('error', 'That reset link is invalid or has expired. Please request a new one.');
            return $this->redirect('/admin/forgot-password');
        }
        return $this->view('auth.reset', [
            'title' => 'Reset Password',
            'token' => $token,
            'csrf'  => $session->csrfToken(),
        ]);
    }

    public function reset(Request $request): Response
    {
        if (!$this->verifyCsrf($request)) {
            $this->flash('error', 'Security check failed. Please try again.');
            return $this->redirect('/admin/forgot-password');
        }

        $token = (string) $request->input('token', '');
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

        $users->update((int) $user['id'], ['password' => $password]);
        $resets->consume($row);
        \App\Services\ActivityLogService::record((int) $user['id'], 'auth.password_reset', 'user', (int) $user['id'], 'Password reset via email link');

        // Confirmation email (best-effort; the reset already succeeded).
        try {
            /** @var \App\Services\Mailer $mailer */
            $mailer = $this->app->make(\App\Services\Mailer::class);
            $mailer->sendTemplate(
                (string) $user['email'],
                'Your password was changed',
                'Password changed',
                '<p>Your account password was just changed via the password-reset flow.</p>'
                . '<p style="font-size:12px;color:#64748b;">If this was not you, reset your password again immediately and contact your site administrator.</p>'
            );
        } catch (\Throwable) {}

        $this->flash('success', 'Password updated — you can sign in now.');
        return $this->redirect('/admin/login');
    }

    // ==================================================================
    // Registration (3) — gated by the "allow_registration" setting.
    // ==================================================================

    public function showRegister(Request $request): Response
    {
        $session = $this->app->make(Session::class);
        if ($session->get('user_id')) return $this->redirect('/admin/dashboard');

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
        $session = $this->app->make(Session::class);
        $auth = $this->authCfg();

        if (empty($auth['allow_registration'])) {
            $this->flash('error', 'Registration is currently disabled.');
            return $this->redirect('/admin/login');
        }
        if (!$this->verifyCsrf($request)) {
            $this->flash('error', 'Security check failed. Please try again.');
            return $this->redirect('/admin/register');
        }
        // (5) Honeypot.
        if (!empty($auth['honeypot']) && trim((string) $request->input('website', '')) !== '') {
            // Pretend success to the bot without creating anything.
            $this->flash('success', 'Account created. You can sign in now.');
            return $this->redirect('/admin/login');
        }

        $username = trim((string) $request->input('username', ''));
        $email = strtolower(trim((string) $request->input('email', '')));
        $password = (string) $request->input('password', '');
        $displayName = trim((string) $request->input('display_name', '')) ?: $username;

        $errors = [];
        if ($username === '' || !preg_match('/^[a-zA-Z0-9_.-]{3,32}$/', $username)) {
            $errors[] = 'Username must be 3–32 characters (letters, numbers, _ . -).';
        }
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
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
            // Neutral-ish: don't confirm which one exists.
            $this->flash('error', 'An account with that email or username already exists.');
            return $this->redirect('/admin/register');
        }

        $role = (string) ($auth['default_role'] ?? 'subscriber');
        // Never allow self-registration into an admin role, even if misconfigured.
        if (in_array($role, ['admin', 'super_admin'], true)) $role = 'subscriber';

        try {
            $id = $users->create([
                'username'     => $username,
                'email'        => $email,
                'password'     => $password,
                'display_name' => $displayName,
                'role'         => $role,
                'status'       => 'active',
            ]);
        } catch (\Throwable $e) {
            $this->flash('error', 'Could not create your account. Please try again.');
            return $this->redirect('/admin/register');
        }

        // (6) Welcome email.
        if (!empty($auth['welcome_email'])) {
            $this->sendWelcomeEmail($email, $displayName);
        }

        try { \App\Services\ActivityLogService::record((int) $id, 'auth.registered', 'user', (int) $id, 'Self-registered'); } catch (\Throwable) {}

        $this->flash('success', 'Account created — you can sign in now.');
        return $this->redirect('/admin/login');
    }

    /** Send the welcome email (used by registration and admin-created users). */
    private function sendWelcomeEmail(string $email, string $displayName): void
    {
        self::sendWelcomeEmailStatic($this->app, $email, $displayName);
    }

    /** Static form so other controllers (e.g. admin user creation) can reuse it. */
    public static function sendWelcomeEmailStatic(\App\Core\Application $app, string $email, string $displayName): void
    {
        try {
            /** @var \App\Services\SettingService $settings */
            $settings = $app->make(\App\Services\SettingService::class);
            $siteName = (string) ($settings->get('general', 'site_title', 'Basehim') ?: 'Basehim');
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $basePath = defined('BASEHIM_BASE') ? rtrim((string) BASEHIM_BASE, '/') : '';
            $loginUrl = "{$scheme}://{$host}{$basePath}/admin/login";
            $name = htmlspecialchars($displayName);
            $safeSite = htmlspecialchars($siteName);

            /** @var \App\Services\Mailer $mailer */
            $mailer = $app->make(\App\Services\Mailer::class);
            $mailer->sendTemplate(
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
    // Helpers
    // ==================================================================

    /** Authorization settings with sane defaults. */
    private function authCfg(): array
    {
        try {
            /** @var \App\Services\SettingService $settings */
            $settings = $this->app->make(\App\Services\SettingService::class);
            $g = $settings->getGroup('authorization');
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
        ];
    }

    private function clientIp(Request $request): string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        return substr((string) $ip, 0, 45);
    }

    /** Stable per-install secret for signing captcha tokens. */
    private function captchaSecret(): string
    {
        try {
            $s = (string) $this->app->make(\App\Core\Config::class)->get('app.key', '');
            if ($s !== '') return $s;
        } catch (\Throwable) {}
        return defined('BASEHIM_VERSION') ? 'bh-captcha-' . BASEHIM_VERSION : 'bh-captcha';
    }

    private function setRememberCookie(string $value, int $days): void
    {
        $expires = $days < 0 ? time() - 3600 : time() + $days * 86400;
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        $basePath = defined('BASEHIM_BASE') ? (rtrim((string) BASEHIM_BASE, '/') ?: '/') : '/';
        setcookie(\App\Services\AuthSecurityService::REMEMBER_COOKIE, $value, [
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
        // Must be a local path — reject anything with a scheme/host
        // (guards against open redirects).
        if ($target[0] !== '/' || str_starts_with($target, '//')) return $fallback;
        $bare = explode('?', $target)[0];
        // Admin pages, plus the OAuth consent screen (an MCP client sends the
        // user there, and they may need to sign in first).
        $allowed = str_starts_with($bare, '/admin') || $bare === '/oauth/authorize';
        if (!$allowed) return $fallback;
        // Never bounce back to an auth page.
        foreach (['/admin/login', '/admin/logout', '/admin/register'] as $s) {
            if ($bare === $s || str_starts_with($bare, $s . '/')) return $fallback;
        }
        return $target;
    }
}
