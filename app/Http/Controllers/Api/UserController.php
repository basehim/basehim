<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Core\Request;
use App\Core\Response;
use App\Services\UserService;

class UserController extends ApiController
{
    public function index(Request $request): Response
    {
        $user = $this->authUser();
        if (!$user || !in_array($user['role'], ['super_admin', 'admin'], true)) {
            return Response::json(['error' => 'Forbidden'], 403);
        }

        /** @var UserService $users */
        $users = $this->app->make(UserService::class);
        $page = max(1, (int)$request->query('page', 1));
        $per = min(100, max(1, (int)$request->query('per_page', 25)));
        $filters = [];
        if ($request->query('q')) $filters['search'] = $request->query('q');
        if ($request->query('role')) $filters['role'] = $request->query('role');
        $r = $users->paginate($filters, $page, $per);
        $r['data'] = array_map(fn($u) => $this->safeUser($u), $r['data']);
        return Response::json($r);
    }

    public function show(Request $request, string $id): Response
    {
        $user = $this->authUser();
        if (!$user) return Response::json(['error' => 'Unauthenticated'], 401);

        /** @var UserService $users */
        $users = $this->app->make(UserService::class);
        $target = $users->find((int)$id);
        if (!$target) return Response::json(['error' => 'Not found'], 404);
        return Response::json(['data' => $this->safeUser($target)]);
    }

    public function store(Request $request): Response
    {
        $user = $this->authUser();
        if (!$user || !in_array($user['role'], ['super_admin', 'admin'], true)) {
            return Response::json(['error' => 'Forbidden'], 403);
        }
        /** @var UserService $users */
        $users = $this->app->make(UserService::class);
        $username = trim((string)$request->input('username', ''));
        $email = trim((string)$request->input('email', ''));
        $password = (string)$request->input('password', '');
        if ($username === '' || $email === '' || strlen($password) < 8) {
            return Response::json(['error' => 'username, email, password (8+) required'], 422);
        }
        if ($users->emailExists($email)) return Response::json(['error' => 'Email already in use'], 409);
        if ($users->usernameExists($username)) return Response::json(['error' => 'Username already in use'], 409);

        $id = $users->create([
            'username' => $username,
            'email' => $email,
            'password' => $password,
            'display_name' => $request->input('display_name', $username),
            'role' => $request->input('role', 'subscriber'),
            'status' => $request->input('status', 'active'),
            'bio' => $request->input('bio'),
        ]);
        return Response::json(['data' => $this->safeUser($users->find($id))], 201);
    }

    public function update(Request $request, string $id): Response
    {
        $user = $this->authUser();
        if (!$user) return Response::json(['error' => 'Unauthenticated'], 401);
        // Only admins can edit anyone; users can edit themselves
        if ((int)$user['id'] !== (int)$id && !in_array($user['role'], ['super_admin', 'admin'], true)) {
            return Response::json(['error' => 'Forbidden'], 403);
        }

        /** @var UserService $users */
        $users = $this->app->make(UserService::class);
        $target = $users->find((int)$id);
        if (!$target) return Response::json(['error' => 'Not found'], 404);

        $update = [];
        foreach (['display_name', 'email', 'bio'] as $f) {
            if ($request->input($f) !== null) $update[$f] = $request->input($f);
        }
        // Only admins can change role/status
        if (in_array($user['role'], ['super_admin', 'admin'], true)) {
            foreach (['role', 'status'] as $f) {
                if ($request->input($f) !== null) $update[$f] = $request->input($f);
            }
        }
        $pw = (string)$request->input('password', '');
        if (strlen($pw) >= 8) $update['password'] = $pw;

        if ($update) $users->update((int)$id, $update);
        return Response::json(['data' => $this->safeUser($users->find((int)$id))]);
    }

    public function destroy(Request $request, string $id): Response
    {
        $user = $this->authUser();
        if (!$user || !in_array($user['role'], ['super_admin', 'admin'], true)) {
            return Response::json(['error' => 'Forbidden'], 403);
        }
        if ((int)$id === (int)$user['id']) {
            return Response::json(['error' => "You can't delete your own account"], 422);
        }
        /** @var UserService $users */
        $users = $this->app->make(UserService::class);
        if (!$users->find((int)$id)) return Response::json(['error' => 'Not found'], 404);
        $users->delete((int)$id);
        return Response::json(['message' => 'Deleted']);
    }
}
