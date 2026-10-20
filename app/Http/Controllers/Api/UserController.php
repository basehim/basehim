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
        if (!$user) return Response::json(['error' => 'Unauthenticated'], 401);
        if (!$this->userCan('manage_users')) return $this->forbidden('manage_users');

        /** @var UserService $users */
        $users = $this->app->make(UserService::class);
        $page = $this->pageNumber($request);
        $per = $this->perPage($request, 25);
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

        // The full record (email, role, status, last sign-in, meta) is for the
        // account itself and for user managers. Anyone else signed in gets the
        // public profile an author page shows. Until 1.2.51 every signed-in
        // account, a subscriber included, could list every user's email.
        if ((int) $target['id'] === (int) $user['id'] || $this->userCan('manage_users')) {
            return Response::json(['data' => $this->safeUser($target)]);
        }
        $public = $this->safeUser(array_intersect_key($target, array_flip(['id', 'display_name', 'bio', 'avatar_media_id'])));
        return Response::json(['data' => $public]);
    }

    public function store(Request $request): Response
    {
        $user = $this->authUser();
        if (!$user) return Response::json(['error' => 'Unauthenticated'], 401);
        // A capability, not role names (1.2.73): a custom role holding
        // manage_users may use the API, and an admin role stripped of it may not.
        if (!$this->userCan('manage_users')) return $this->forbidden('manage_users');

        /** @var UserService $users */
        $users = $this->app->make(UserService::class);
        $username = trim((string)$request->input('username', ''));
        $email = strtolower(trim((string)$request->input('email', '')));
        $password = (string)$request->input('password', '');
        if ($username === '' || $email === '' || strlen($password) < 8) {
            return Response::json(['error' => 'username, email, password (8+) required'], 422);
        }
        if (!preg_match('/^[a-zA-Z0-9_.-]{3,60}$/', $username)) {
            return Response::json(['error' => 'username must be 3-60 characters: letters, numbers, _ . -'], 422);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return Response::json(['error' => 'email is not a valid address'], 422);
        }
        // The same rule as the admin screen (BH-022): only an existing role at
        // or below the caller's own level. Until 1.2.73 an administrator could
        // create super administrators, or accounts with a role that does not
        // exist, through this endpoint.
        $role = (string) $request->input('role', 'subscriber');
        $ac = $this->access();
        if (!$ac->roleExists($role)) return Response::json(['error' => "Unknown role '{$role}'."], 422);
        if (!$ac->canAssignRole($user, $role)) {
            return Response::json(['error' => 'You cannot create an account with a role higher than your own.'], 403);
        }
        if ($users->emailExists($email)) return Response::json(['error' => 'Email already in use'], 409);
        if ($users->usernameExists($username)) return Response::json(['error' => 'Username already in use'], 409);

        $id = $users->create([
            'username' => $username,
            'email' => $email,
            'password' => $password,
            'display_name' => $request->input('display_name', $username),
            'role' => $role,
            'status' => UserService::validStatus($request->input('status', 'active')),
            'bio' => $request->input('bio'),
        ]);
        $this->logChange($id, 'user.created', "Account created via the API by " . $this->actorName($user) . " (role {$role})");
        return Response::json(['data' => $this->safeUser($users->find($id))], 201);
    }

    /**
     * PUT /users/{id}.
     *
     * Hardened in 1.2.73 (BH-022) to the admin screen's rules:
     *  - your own email or password needs current_password, as on /me. A
     *    stolen access token or a users:write key could take the account over;
     *  - your own role and status cannot be changed here;
     *  - another account needs manage_users and a strictly higher level than
     *    theirs, and a new role must exist and be at or below your own;
     *  - the last active administrator keeps their role and status.
     */
    public function update(Request $request, string $id): Response
    {
        $user = $this->authUser();
        if (!$user) return Response::json(['error' => 'Unauthenticated'], 401);

        /** @var UserService $users */
        $users = $this->app->make(UserService::class);
        $target = $users->find((int)$id);
        $self = $target !== null && (int) $target['id'] === (int) $user['id'];
        if (!$self && !$this->userCan('manage_users')) return $this->forbidden('manage_users');
        if (!$target) return Response::json(['error' => 'Not found'], 404);

        $ac = $this->access();
        if (!$self && !$ac->canManage($user, $target)) {
            return Response::json(['error' => 'You cannot modify a user at or above your access level.'], 403);
        }

        $update = [];
        foreach (['display_name', 'bio'] as $f) {
            if ($request->input($f) !== null) $update[$f] = $request->input($f);
        }

        $emailIn = $request->input('email');
        $emailChange = is_string($emailIn) && trim($emailIn) !== ''
            && strtolower(trim($emailIn)) !== strtolower((string) $target['email']);
        $pw = (string) $request->input('password', '');

        if ($self && ($emailChange || $pw !== '')) {
            $fresh = $users->find((int) $user['id']) ?? $user;
            if (!password_verify((string) $request->input('current_password', ''), (string) ($fresh['password_hash'] ?? ''))) {
                return Response::json(['error' => 'current_password is required to change your own email or password'], 403);
            }
        }
        if ($pw !== '') {
            if (strlen($pw) < 8) return Response::json(['error' => 'password must be at least 8 characters'], 422);
            $update['password'] = $pw;
        }
        if ($emailChange) {
            $email = strtolower(trim((string) $emailIn));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return Response::json(['error' => 'email is not a valid address'], 422);
            if ($users->emailExists($email, (int) $target['id'])) return Response::json(['error' => 'Email already in use'], 409);
            $update['email'] = $email;
        }

        $roleIn = $request->input('role');
        $statusIn = $request->input('status');
        $roleChange = $roleIn !== null && (string) $roleIn !== (string) $target['role'];
        $statusChange = $statusIn !== null && (string) $statusIn !== (string) $target['status'];
        if ($self && ($roleChange || $statusChange)) {
            return Response::json(['error' => 'You cannot change your own role or status. Another administrator must do it.'], 403);
        }
        if ($roleChange) {
            $role = (string) $roleIn;
            if (!$ac->roleExists($role)) return Response::json(['error' => "Unknown role '{$role}'."], 422);
            if (!$ac->canAssignRole($user, $role)) {
                return Response::json(['error' => 'You cannot assign a role higher than your own.'], 403);
            }
            $update['role'] = $role;
        }
        if ($statusChange) {
            $update['status'] = UserService::validStatus((string) $statusIn, (string) $target['status']);
        }
        if ((isset($update['role']) || (isset($update['status']) && $update['status'] !== 'active')) && $this->isLastAdmin($target)) {
            return Response::json(['error' => 'This is the last administrator account. Assign another administrator first.'], 409);
        }

        if ($update) {
            $users->update((int)$id, $update);
            $actor = $this->actorName($user);
            if (isset($update['role'])) {
                $this->logChange((int) $id, 'user.role_changed', "Role changed {$target['role']} → {$update['role']} by {$actor} (API)");
            }
            $this->logChange((int) $id, 'user.updated', "Account updated via the API by {$actor}: " . implode(', ', array_map(
                fn($k) => $k === 'password' ? 'password' : $k, array_keys($update))));
        }
        return Response::json(['data' => $this->safeUser($users->find((int)$id))]);
    }

    public function destroy(Request $request, string $id): Response
    {
        $user = $this->authUser();
        if (!$user) return Response::json(['error' => 'Unauthenticated'], 401);
        if (!$this->userCan('manage_users')) return $this->forbidden('manage_users');
        if ((int)$id === (int)$user['id']) {
            return Response::json(['error' => "You can't delete your own account"], 422);
        }
        /** @var UserService $users */
        $users = $this->app->make(UserService::class);
        $target = $users->find((int)$id);
        if (!$target) return Response::json(['error' => 'Not found'], 404);
        if (!$this->access()->canManage($user, $target)) {
            return Response::json(['error' => 'You cannot delete a user at or above your access level.'], 403);
        }
        if ($this->isLastAdmin($target)) {
            return Response::json(['error' => 'This is the last administrator account.'], 409);
        }
        $users->delete((int)$id);
        $this->logChange((int) $id, 'user.deleted', 'Account deleted via the API by ' . $this->actorName($user));
        return Response::json(['message' => 'Deleted']);
    }

    private function access(): \App\Services\AccessControl
    {
        return $this->app->make(\App\Services\AccessControl::class);
    }

    private function actorName(array $user): string
    {
        return (string) ($user['display_name'] ?? $user['username'] ?? ('#' . ($user['id'] ?? '?')));
    }

    private function logChange(int $userId, string $event, string $detail): void
    {
        \App\Services\ActivityLogService::record($userId, $event, 'user', $userId, $detail);
    }

    /** True when $target is the only remaining active admin/super_admin. */
    private function isLastAdmin(array $target): bool
    {
        if (!in_array($target['role'] ?? '', ['admin', 'super_admin'], true)) return false;
        $row = $this->app->make(\App\Core\Database::class)->selectOne(
            "SELECT COUNT(*) AS c FROM {users}
             WHERE role IN ('admin','super_admin') AND status = 'active'
               AND deleted_at IS NULL AND id <> :id",
            ['id' => (int) $target['id']]
        );
        return (int) ($row['c'] ?? 0) === 0;
    }
}
