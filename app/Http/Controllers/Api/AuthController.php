<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Core\Request;
use App\Core\Response;
use App\Http\Controllers\Api\ApiController;
use App\Services\AuthService;
use App\Services\UserService;

class AuthController extends ApiController
{
    /**
     * POST /api/v1/auth/login — {login, password[, otp]}.
     *
     * Same protection as the sign-in page: failed passwords are counted per
     * account and address and lock after the configured number, an address
     * failing across many accounts is refused, and an account with two-step
     * verification gets a 401 `two_factor_required` (with a code emailed) until
     * the request repeats with `otp`. Before 1.2.33 this endpoint had no limit
     * at all and skipped every sign-in protection.
     */
    public function login(Request $request): Response
    {
        $login = $request->input('login') ?? $request->input('email') ?? $request->input('username');
        $password = $request->input('password');

        if (!$login || !$password || !is_string($login) || !is_string($password)) {
            return Response::json(['error' => 'login and password are required'], 422);
        }
        $login = mb_substr(trim($login), 0, 190);
        $ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);

        /** @var \App\Services\AuthSecurityService $sec */
        $sec = $this->app->make(\App\Services\AuthSecurityService::class);
        $cfg = $this->authCfg();
        // Higher than the sign-in page's captcha threshold: there is no captcha
        // here, and behind a proxy every client may share one address.
        if ($sec->ipFailures($ip) >= 100) {
            return $this->tooMany(900, 'Too many failed sign-in attempts from this address.');
        }
        if (($wait = $sec->lockedSeconds($login, $ip)) > 0) {
            return $this->tooMany($wait, 'Too many failed attempts for this account. Try again later.');
        }

        /** @var AuthService $auth */
        $auth = $this->app->make(AuthService::class);
        $result = $auth->attemptDetailed($login, $password);
        if (empty($result['ok'])) {
            if (($result['reason'] ?? '') === 'blocked') {
                return Response::json(['error' => 'account_inactive', 'message' => 'This account is not permitted to sign in.'], 403);
            }
            $sec->recordFailure($login, $ip, $cfg['lockout_after'], $cfg['lockout_minutes']);
            return Response::json(['error' => 'Invalid credentials'], 401);
        }
        $user = $result['user'];

        /** @var \App\Services\TwoFactorService $tf */
        $tf = $this->app->make(\App\Services\TwoFactorService::class);
        if ($tf->applies($user)) {
            $otp = (string) ($request->input('otp') ?? $request->input('code') ?? '');
            if ($otp === '') {
                $r = $tf->send($user, 'login', $ip);
                if (!$r['ok'] && !in_array($r['error'] ?? '', ['wait'], true)) {
                    return Response::json(['error' => 'two_factor_unavailable', 'message' => \App\Services\TwoFactorService::sendError($r)], ($r['error'] ?? '') === 'mail' ? 503 : 429);
                }
                return Response::json([
                    'error' => 'two_factor_required',
                    'message' => 'A 6-digit code was emailed to ' . \App\Http\Controllers\Admin\AuthController::maskEmail((string) $user['email']) . '. Repeat the request with "otp".',
                ], 401);
            }
            $v = $tf->verify((int) $user['id'], 'login', $otp);
            if ($v !== 'ok') {
                return Response::json(['error' => 'invalid_otp', 'message' => match ($v) {
                    'locked'  => 'Too many wrong codes. Try again in 30 minutes.',
                    'expired' => 'The code has expired or was used up. Request a new one by signing in without "otp".',
                    default   => 'The code is not right.',
                }], $v === 'locked' ? 429 : 401);
            }
        }

        $sec->clear($login, $ip);
        $tokens = $auth->issueTokens($user);
        return Response::json([
            'user' => $this->safeUser($user),
            'access_token' => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'],
            'token_type' => 'Bearer',
            'expires_in' => $tokens['expires_in'],
        ]);
    }

    public function refresh(Request $request): Response
    {
        $refresh = $request->input('refresh_token');
        if (!$refresh) return Response::json(['error' => 'refresh_token required'], 422);

        /** @var AuthService $auth */
        $auth = $this->app->make(AuthService::class);
        $tokens = $auth->refreshTokens((string)$refresh);
        if (!$tokens) return Response::json(['error' => 'Invalid refresh token'], 401);

        return Response::json([
            'access_token' => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'],
            'token_type' => 'Bearer',
            'expires_in' => $tokens['expires_in'],
        ]);
    }

    public function logout(Request $request): Response
    {
        $refresh = $request->input('refresh_token');
        if ($refresh) {
            /** @var AuthService $auth */
            $auth = $this->app->make(AuthService::class);
            $auth->revokeRefreshToken((string)$refresh);
        }
        return Response::json(['message' => 'Logged out']);
    }

    /**
     * POST /api/v1/auth/register — only while public registration is allowed
     * (Settings › Authentication). Before 1.2.33 this endpoint created
     * accounts even with registration turned off.
     */
    public function register(Request $request): Response
    {
        $cfg = $this->authCfg();
        if (!$cfg['allow_registration']) {
            return Response::json(['error' => 'registration_disabled', 'message' => 'Registration is turned off on this site.'], 403);
        }
        $ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
        if (!$this->app->make(\App\Services\AuthSecurityService::class)->throttle('register', $ip, 5, 3600)) {
            return $this->tooMany(3600, 'Too many accounts created from this address. Try again later.');
        }

        $username = trim((string)$request->input('username', ''));
        $email = strtolower(trim((string)$request->input('email', '')));
        $password = (string)$request->input('password', '');
        $displayName = mb_substr(trim((string) $request->input('display_name', $username)), 0, 120) ?: $username;

        if ($username === '' || $email === '' || strlen($password) < 8) {
            return Response::json(['error' => 'username, email, and 8+ char password are required'], 422);
        }
        if (!preg_match('/^[a-zA-Z0-9_.-]{3,32}$/', $username)) {
            return Response::json(['error' => 'username must be 3-32 characters: letters, numbers, _ . -'], 422);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return Response::json(['error' => 'email is not a valid address'], 422);
        }
        /** @var UserService $users */
        $users = $this->app->make(UserService::class);
        if ($users->emailExists($email) || $users->usernameExists($username)) {
            return Response::json(['error' => 'An account with that email or username already exists'], 409);
        }

        $id = $users->create([
            'username' => $username,
            'email' => $email,
            'password' => $password,
            'display_name' => $displayName,
            'role' => \App\Http\Controllers\Admin\AuthController::registrationRole($this->app, $cfg['default_role']),
            'status' => 'active',
        ]);
        $user = $users->find($id);

        /** @var AuthService $auth */
        $auth = $this->app->make(AuthService::class);
        $tokens = $auth->issueTokens($user);

        return Response::json([
            'user' => $this->safeUser($user),
            'access_token' => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'],
            'token_type' => 'Bearer',
            'expires_in' => $tokens['expires_in'],
        ], 201);
    }

    public function me(Request $request): Response
    {
        $user = $this->authUser();
        if (!$user) return Response::json(['error' => 'Unauthenticated'], 401);
        return Response::json(['user' => $this->safeUser($user)]);
    }

    public function updateProfile(Request $request): Response
    {
        $user = $this->authUser();
        if (!$user) return Response::json(['error' => 'Unauthenticated'], 401);

        /** @var UserService $users */
        $users = $this->app->make(UserService::class);
        $update = [];
        foreach (['display_name', 'bio'] as $f) {
            $val = $request->input($f);
            if ($val !== null) $update[$f] = $val;
        }

        // Email and password changes need current_password, as in the admin.
        // A leaked access token used to be enough to take the account over.
        $email = $request->input('email');
        $pw = (string) $request->input('password', '');
        $emailChange = is_string($email) && strtolower(trim($email)) !== strtolower((string) $user['email']);
        if ($emailChange || $pw !== '') {
            if (!password_verify((string) $request->input('current_password', ''), (string) $user['password_hash'])) {
                return Response::json(['error' => 'current_password is required to change email or password'], 403);
            }
        }
        if ($pw !== '') {
            if (strlen($pw) < 8) return Response::json(['error' => 'password must be at least 8 characters'], 422);
            $update['password'] = $pw;
        }
        if ($emailChange) {
            $email = strtolower(trim($email));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return Response::json(['error' => 'email is not a valid address'], 422);
            if ($users->emailExists($email, (int) $user['id'])) return Response::json(['error' => 'Email already in use'], 409);
            $update['email'] = $email;
        }

        if ($update) $users->update((int)$user['id'], $update);
        $fresh = $users->find((int)$user['id']);
        return Response::json(['user' => $this->safeUser($fresh)]);
    }

    private function authUserOrNull(): ?array
    {
        return $this->authUser();
    }

    private function tooMany(int $seconds, string $message): Response
    {
        return Response::json(['error' => 'too_many_attempts', 'message' => $message, 'retry_after' => max(1, $seconds)], 429, ['Retry-After' => (string) max(1, $seconds)]);
    }

    /** The settings this controller needs from Settings › Authentication. */
    private function authCfg(): array
    {
        try {
            $g = $this->app->make(\App\Services\SettingService::class)->getGroup('authorization');
        } catch (\Throwable) { $g = []; }
        return [
            'allow_registration' => !empty($g['allow_registration']),
            'default_role'       => (string) ($g['default_role'] ?? 'subscriber'),
            'lockout_after'      => max(3, min(50, (int) ($g['lockout_after'] ?? 10))),
            'lockout_minutes'    => max(1, min(1440, (int) ($g['lockout_minutes'] ?? 15))),
        ];
    }
}
