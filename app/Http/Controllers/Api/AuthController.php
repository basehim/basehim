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
    public function login(Request $request): Response
    {
        $login = $request->input('login') ?? $request->input('email') ?? $request->input('username');
        $password = $request->input('password');

        if (!$login || !$password) {
            return Response::json(['error' => 'login and password are required'], 422);
        }

        $ip = $this->clientIp();

        // Share the admin form's failure counters. Without this the API was a
        // complete bypass of the lockout/captcha/OTP ladder guarding the same
        // credentials — unlimited guesses at whatever rate the host would serve.
        /** @var \App\Services\AuthSecurityService $sec */
        $sec = $this->app->make(\App\Services\AuthSecurityService::class);
        $limit = $this->loginAttemptLimit();

        // An API client cannot solve a captcha or read the OTP email, so once
        // the ladder has escalated the honest answer is "finish this in a
        // browser" rather than silently letting the API through.
        if ($sec->captchaRequired((string)$login, $ip, $limit)) {
            return Response::json([
                'error'  => 'Too many failed attempts for this account. Sign in through the web interface to continue.',
                'status' => 429,
            ], 429);
        }

        /** @var AuthService $auth */
        $auth = $this->app->make(AuthService::class);
        $user = $auth->attempt((string)$login, (string)$password);
        if (!$user) {
            $sec->recordFailure((string)$login, $ip);
            return Response::json(['error' => 'Invalid credentials'], 401);
        }

        $sec->clear((string)$login, $ip);
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

    public function register(Request $request): Response
    {
        // The admin form honours this setting; the API used to ignore it, so a
        // site with registration switched off still had an open signup endpoint.
        if (!$this->registrationAllowed()) {
            return Response::json(['error' => 'Registration is disabled.'], 403);
        }

        // Signup is unauthenticated, so it needs its own throttle or it becomes
        // a bulk account-creation endpoint.
        $ip = $this->clientIp();
        if ($this->tooManyAttempts('register:' . $ip, 5, 3600)) {
            return Response::json(['error' => 'Too many registration attempts. Try again later.'], 429);
        }

        $username = trim((string)$request->input('username', ''));
        $email = trim((string)$request->input('email', ''));
        $password = (string)$request->input('password', '');
        $displayName = $request->input('display_name', $username);

        if ($username === '' || $email === '' || strlen($password) < 8) {
            return Response::json(['error' => 'username, email, and 8+ char password are required'], 422);
        }
        /** @var UserService $users */
        $users = $this->app->make(UserService::class);
        if ($users->emailExists($email)) return Response::json(['error' => 'Email already in use'], 409);
        if ($users->usernameExists($username)) return Response::json(['error' => 'Username already in use'], 409);

        $id = $users->create([
            'username' => $username,
            'email' => $email,
            'password' => $password,
            'display_name' => $displayName,
            'role' => $this->defaultRole(),
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
        foreach (['display_name', 'email', 'bio'] as $f) {
            $val = $request->input($f);
            if ($val !== null) $update[$f] = $val;
        }
        $pw = (string)$request->input('password', '');
        if (strlen($pw) >= 8) $update['password'] = $pw;

        if ($update) $users->update((int)$user['id'], $update);
        $fresh = $users->find((int)$user['id']);
        return Response::json(['user' => $this->safeUser($fresh)]);
    }

    private function authUserOrNull(): ?array
    {
        return $this->authUser();
    }

    // ==================================================================
    // Guards shared with the admin auth flow
    // ==================================================================

    /** The `authorization` settings group, with the same defaults the admin form uses. */
    private function authCfg(): array
    {
        try {
            $g = (array) $this->app->make(\App\Services\SettingService::class)->getGroup('authorization');
        } catch (\Throwable) { $g = []; }
        return $g;
    }

    private function registrationAllowed(): bool
    {
        return !empty($this->authCfg()['allow_registration']);
    }

    private function loginAttemptLimit(): int
    {
        return max(1, (int) ($this->authCfg()['login_attempt_limit'] ?? 3));
    }

    /**
     * The role a self-registered account gets.
     *
     * Mirrors the admin form, including its refusal to hand out an admin role
     * through self-registration even if the setting says otherwise.
     */
    private function defaultRole(): string
    {
        $role = (string) ($this->authCfg()['default_role'] ?? 'subscriber');
        if (in_array($role, ['admin', 'super_admin'], true)) return 'subscriber';
        return $role !== '' ? $role : 'subscriber';
    }

    /**
     * Only ever REMOTE_ADDR. Forwarding headers are attacker-controlled, and a
     * throttle keyed on a value the attacker picks is not a throttle.
     */
    private function clientIp(): string
    {
        return substr((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
    }

    /**
     * Simple cache-backed counter for unauthenticated endpoints that have no
     * per-account identity to key on (registration).
     */
    private function tooManyAttempts(string $key, int $max, int $window): bool
    {
        try {
            /** @var \App\Core\Cache $cache */
            $cache = $this->app->make(\App\Core\Cache::class);
            $bucket = 'throttle:' . sha1($key);
            $count = (int) ($cache->get($bucket, 0));
            if ($count >= $max) return true;
            $cache->set($bucket, $count + 1, $window);
            return false;
        } catch (\Throwable) {
            // Cache unavailable — fail open rather than locking out signup.
            return false;
        }
    }
}
