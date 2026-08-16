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

        /** @var AuthService $auth */
        $auth = $this->app->make(AuthService::class);
        $user = $auth->attempt((string)$login, (string)$password);
        if (!$user) return Response::json(['error' => 'Invalid credentials'], 401);

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
            'role' => 'subscriber',
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
}
