<?php
declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Http\Controllers\Controller;
use App\Services\UserService;
use App\Core\Config;

class UserController extends Controller
{
    public function index(Request $request): Response
    {
        /** @var UserService $users */
        $users = $this->app->make(UserService::class);
        $page = max(1, (int)$request->query('page', 1));
        $search = (string)$request->query('q', '');
        $role = $request->query('role');

        $filters = [];
        if ($search !== '') $filters['search'] = $search;
        if ($role) $filters['role'] = $role;

        $result = $users->paginate($filters, $page, 25);
        $session = $this->app->make(Session::class);

        return $this->view('users.index', [
            'title' => 'Users',
            'currentUser' => $this->user(),
            'users' => $result['data'],
            'meta' => $result['meta'],
            'search' => $search,
            'role' => $role,
            'csrf' => $session->csrfToken(),
            'roles' => array_keys($this->app->make(Config::class)->get('capabilities', [])),
        ]);
    }

    public function create(Request $request): Response
    {
        return $this->renderForm(null);
    }

    public function edit(Request $request, string $id): Response
    {
        /** @var UserService $users */
        $users = $this->app->make(UserService::class);
        $user = $users->find((int)$id);
        if (!$user) return $this->abort(404);
        return $this->renderForm($user);
    }

    private function renderForm(?array $user): Response
    {
        $session = $this->app->make(Session::class);
        $config = $this->app->make(Config::class);

        // Module access map: admin area → gating capability (mirrors AdminAreaPolicy).
        $moduleMap = [
            ['label' => 'Posts',        'cap' => 'edit_posts',        'icon' => 'fa-newspaper'],
            ['label' => 'Pages',        'cap' => 'edit_pages',        'icon' => 'fa-file-lines'],
            ['label' => 'Media',        'cap' => 'upload_media',      'icon' => 'fa-photo-film'],
            ['label' => 'Comments',     'cap' => 'moderate_comments', 'icon' => 'fa-comments'],
            ['label' => 'Taxonomies',   'cap' => 'manage_taxonomies', 'icon' => 'fa-folder-tree'],
            ['label' => 'Menus',        'cap' => 'manage_menus',      'icon' => 'fa-bars'],
            ['label' => 'Users',        'cap' => 'manage_users',      'icon' => 'fa-users'],
            ['label' => 'Apps',         'cap' => 'manage_apps',       'icon' => 'fa-plug'],
            ['label' => 'Themes',       'cap' => 'manage_themes',     'icon' => 'fa-palette'],
            ['label' => 'Settings',     'cap' => 'manage_settings',   'icon' => 'fa-gear'],
            ['label' => 'API keys',     'cap' => 'manage_options',    'icon' => 'fa-code'],
        ];
        // Fine-grained (non-module) capabilities for the Individual Permissions grid.
        $permissionCatalog = [
            'Posts'  => ['publish_posts', 'edit_others_posts', 'delete_posts', 'delete_others_posts'],
            'Pages'  => ['publish_pages', 'edit_others_pages', 'delete_pages', 'delete_others_pages'],
            'Media'  => ['delete_media'],
            'Other'  => ['manage_seo', 'read_private_meta'],
        ];

        $overrides = $user ? \App\Http\Middleware\CheckCapability::overridesFor($user) : ['grant' => [], 'deny' => []];

        /** @var \App\Services\AccessControl $ac */
        $ac = $this->app->make(\App\Services\AccessControl::class);
        $me = $this->user();

        // Installed apps as togglable capabilities for the App Access section.
        // 'eff' uses userCan so it reflects the default-allow/explicit-deny rule.
        $appItems = [];
        foreach ($ac->appList() as $p) {
            $appItems[] = [
                'label' => $p['name'],
                'cap'   => $p['cap'],
                'icon'  => 'fa-plug',
                'slug'  => $p['slug'],
                'eff'   => $user ? \App\Http\Middleware\CheckCapability::userCan($user, $p['cap']) : false,
            ];
        }

        // Candidate owners for the "Transfer ownership" action.
        $allUsers = [];
        if ($user) {
            /** @var UserService $users */
            $users = $this->app->make(UserService::class);
            $res = $users->paginate([], 1, 200);
            $allUsers = array_values(array_filter($res['data'] ?? [], fn($u) => (int) $u['id'] !== (int) $user['id']));
        }

        return $this->view('users.edit', [
            'title' => $user ? 'Edit User' : 'New User',
            'currentUser' => $me,
            'editUser' => $user,
            'roles' => array_keys($ac->assignableRoles($me)),
            'roleDefs' => $ac->roles(),
            'moduleMap' => $moduleMap,
            'appItems' => $appItems,
            'permissionCatalog' => $permissionCatalog,
            'overrides' => $overrides,
            'effectiveCaps' => $user ? \App\Http\Middleware\CheckCapability::effectiveCaps($user) : [],
            'canManageTarget' => $user ? $ac->canManage($me, $user) : true,
            'grantableCaps' => \App\Http\Middleware\CheckCapability::effectiveCaps($me),
            'allUsers' => $allUsers,
            'csrf' => $session->csrfToken(),
        ]);
    }

    public function store(Request $request): Response
    {
        if (!$this->verifyCsrf($request)) { $this->flash('error', 'Security check failed.'); return $this->back(); }

        /** @var UserService $users */
        $users = $this->app->make(UserService::class);

        $username = trim((string)$request->input('username'));
        $email = trim((string)$request->input('email'));
        $password = (string)$request->input('password');

        if ($username === '' || $email === '' || strlen($password) < 8) {
            $this->flash('error', 'Username, email, and 8+ character password are required.');
            return $this->back();
        }
        if ($users->emailExists($email)) {
            $this->flash('error', 'Email already in use.'); return $this->back();
        }
        if ($users->usernameExists($username)) {
            $this->flash('error', 'Username already in use.'); return $this->back();
        }

        $id = $users->create([
            'username' => $username,
            'email' => $email,
            'password' => $password,
            'display_name' => $request->input('display_name', $username),
            'role' => $request->input('role', 'subscriber'),
            'status' => $request->input('status', 'active'),
            'bio' => $request->input('bio'),
        ]);

        // Welcome email (if enabled in Authorization settings).
        try {
            $settings = $this->app->make(\App\Services\SettingService::class);
            if (!empty($settings->get('authorization', 'welcome_email', 0))) {
                \App\Http\Controllers\Admin\AuthController::sendWelcomeEmailStatic(
                    $this->app, $email, (string) $request->input('display_name', $username)
                );
            }
        } catch (\Throwable) {}

        $this->flash('success', 'User created.');
        return $this->redirect("/admin/users/{$id}/edit");
    }

    public function update(Request $request, string $id): Response
    {
        if (!$this->verifyCsrf($request)) { $this->flash('error', 'Security check failed.'); return $this->back(); }

        /** @var UserService $users */
        $users = $this->app->make(UserService::class);
        $existing = $users->find((int)$id);
        if (!$existing) return $this->abort(404);

        $data = [
            'display_name' => $request->input('display_name'),
            'email' => $request->input('email'),
            'bio' => $request->input('bio'),
        ];
        // Role/status of your own account can't be changed here — prevents
        // accidentally locking yourself out. Another admin can change them.
        if ((int)$id !== $this->userId()) {
            /** @var \App\Services\AccessControl $ac */
            $ac = $this->app->make(\App\Services\AccessControl::class);
            if (!$ac->canManage($this->user(), $existing)) {
                $this->flash('error', 'You cannot modify a user at or above your access level.');
                return $this->redirect('/admin/users');
            }
            $newRole = (string) $request->input('role', $existing['role']);
            if ($newRole !== $existing['role'] && !$ac->canAssignRole($this->user(), $newRole)) {
                $this->flash('error', 'You cannot assign a role higher than your own.');
                return $this->redirect("/admin/users/{$id}/edit");
            }
            $data['role'] = $newRole;
            $data['status'] = $request->input('status', $existing['status']);
            if ($data['role'] !== $existing['role'] && $this->isLastAdmin($existing)) {
                $this->flash('error', 'This is the last administrator account — assign another admin first.');
                return $this->redirect("/admin/users/{$id}/edit");
            }
        }
        $password = (string)$request->input('password', '');
        if ($password !== '') {
            if (strlen($password) < 8) { $this->flash('error', 'Password must be 8+ characters.'); return $this->back(); }
            $data['password'] = $password;
        }

        $users->update((int)$id, $data);

        $actor = (string) ($this->user()['display_name'] ?? $this->user()['username'] ?? 'admin');
        if (isset($data['role']) && $data['role'] !== $existing['role']) {
            \App\Services\ActivityLogService::record((int)$id, 'user.role_changed', 'user', (int)$id,
                "Role changed {$existing['role']} → {$data['role']} by {$actor}");
        }
        \App\Services\ActivityLogService::record((int)$id, 'user.updated', 'user', (int)$id, "Account updated by {$actor}");

        $this->flash('success', 'User updated.');
        return $this->redirect("/admin/users/{$id}/edit");
    }

    public function destroy(Request $request, string $id): Response
    {
        if (!$this->verifyCsrf($request)) { $this->flash('error', 'Security check failed.'); return $this->back(); }

        if ((int)$id === $this->userId()) {
            $this->flash('error', "You can't delete your own account.");
            return $this->redirect('/admin/users');
        }
        /** @var UserService $users */
        $users = $this->app->make(UserService::class);
        $target = $users->find((int)$id);
        if (!$target) return $this->abort(404);
        if (!$this->canManageOrFail($target)) return $this->redirect('/admin/users');
        if ($this->isLastAdmin($target)) {
            $this->flash('error', 'This is the last administrator account — assign another admin first.');
            return $this->redirect("/admin/users/{$id}/edit");
        }
        $users->delete((int)$id);
        \App\Services\ActivityLogService::record((int)$id, 'user.deleted', 'user', (int)$id,
            'Deleted by ' . ($this->user()['display_name'] ?? 'admin'));
        $this->flash('success', 'User deleted.');
        return $this->redirect('/admin/users');
    }

    // ==================================================================
    // Access control tab
    // ==================================================================

    /** POST /admin/users/{id}/access — role + per-user permission overrides. */
    public function saveAccess(Request $request, string $id): Response
    {
        if (!$this->verifyCsrf($request)) { $this->flash('error', 'Security check failed.'); return $this->back(); }
        /** @var UserService $users */
        $users = $this->app->make(UserService::class);
        $existing = $users->find((int)$id);
        if (!$existing) return $this->abort(404);

        /** @var \App\Services\AccessControl $ac */
        $ac = $this->app->make(\App\Services\AccessControl::class);
        $me = $this->user();
        $tab = '#access';

        // GUARD 1 — you can never edit your own access (role or overrides). This
        // is the core fix: a denied admin must not be able to re-grant himself.
        if ((int)$id === (int)($me['id'] ?? -1)) {
            $this->flash('error', 'You cannot change your own role or permissions. Another higher-level administrator must do it.');
            return $this->redirect("/admin/users/{$id}/edit{$tab}");
        }
        // GUARD 2 — you may only manage users strictly below your own level.
        if (!$ac->canManage($me, $existing)) {
            $this->flash('error', 'You do not have permission to manage this user (they are at or above your access level).');
            return $this->redirect("/admin/users/{$id}/edit{$tab}");
        }

        $actor = (string) ($me['display_name'] ?? 'admin');

        // Role — must be one the actor is allowed to assign (at/below their level).
        $role = (string) $request->input('role', $existing['role']);
        if ($role !== $existing['role']) {
            if (!$ac->canAssignRole($me, $role)) {
                $this->flash('error', 'You cannot assign a role higher than your own.');
                return $this->redirect("/admin/users/{$id}/edit{$tab}");
            }
            if ($this->isLastAdmin($existing)) {
                $this->flash('error', 'This is the last administrator account — assign another admin first.');
                return $this->redirect("/admin/users/{$id}/edit{$tab}");
            }
            $users->update((int)$id, ['role' => $role]);
            \App\Services\ActivityLogService::record((int)$id, 'user.role_changed', 'user', (int)$id,
                "Role changed {$existing['role']} → {$role} by {$actor}");
        }

        // Tri-state overrides: cap_mode[CAP] = default | grant | deny.
        // GUARD 3 — the actor can only GRANT capabilities they themselves hold,
        // so privileges can't be laundered upward via overrides.
        $modes = (array) $request->input('cap_mode', []);
        $grant = []; $deny = []; $blocked = [];
        foreach ($modes as $cap => $mode) {
            // Keep the full capability charset: app caps look like
            // "access_app:wake-on-lan" (hyphens, and possibly dots, in the
            // slug). Stripping those would mangle the key so it never matches
            // on reload — which made app grant/deny silently revert.
            $cap = preg_replace('/[^a-z0-9_:.\-]/i', '', (string) $cap);
            if ($cap === '') continue;
            if ($mode === 'grant') {
                if ($ac->canGrantCapability($me, $cap)) $grant[] = $cap;
                else $blocked[] = $cap;
            } elseif ($mode === 'deny') {
                $deny[] = $cap;
            }
        }

        $meta = is_string($existing['meta'] ?? null) ? (json_decode($existing['meta'], true) ?: []) : (array) ($existing['meta'] ?? []);
        $meta['caps_grant'] = array_values(array_unique($grant));
        $meta['caps_deny'] = array_values(array_unique($deny));

        /** @var \App\Repositories\UserRepository $repo */
        $repo = $this->app->make(\App\Repositories\UserRepository::class);
        $repo->update((int)$id, ['meta' => json_encode($meta, JSON_UNESCAPED_SLASHES)]);

        \App\Services\ActivityLogService::record((int)$id, 'user.permissions_changed', 'user', (int)$id,
            'Overrides: +' . count($meta['caps_grant']) . ' / −' . count($meta['caps_deny']) . " by {$actor}");

        if ($blocked) {
            $this->flash('error', 'Access saved, but these could not be granted (you do not hold them yourself): ' . implode(', ', $blocked));
        } else {
            $this->flash('success', 'Access control saved.');
        }
        return $this->redirect("/admin/users/{$id}/edit{$tab}");
    }

    // ==================================================================
    // Activity tab
    // ==================================================================

    /** GET /admin/users/{id}/activity.json?filter=all|logins|content|audit&page=N */
    public function activityJson(Request $request, string $id): Response
    {
        /** @var \App\Services\ActivityLogService $log */
        $log = $this->app->make(\App\Services\ActivityLogService::class);
        $filter = (string) $request->query('filter', 'all');
        if (!array_key_exists($filter, \App\Services\ActivityLogService::FILTERS)) $filter = 'all';
        $page = max(1, (int) $request->query('page', 1));
        $result = $log->forUser((int)$id, $filter, $page, 30);
        return Response::json(['ok' => true] + $result);
    }

    // ==================================================================
    // Danger zone tab
    // ==================================================================

    public function archive(Request $request, string $id): Response
    {
        return $this->setLifecycleStatus($request, (int)$id, 'inactive', 'user.archived', 'User archived — they can no longer sign in.');
    }

    public function suspend(Request $request, string $id): Response
    {
        return $this->setLifecycleStatus($request, (int)$id, 'suspended', 'user.suspended', 'Account suspended.');
    }

    public function reactivate(Request $request, string $id): Response
    {
        return $this->setLifecycleStatus($request, (int)$id, 'active', 'user.reactivated', 'Account reactivated.');
    }

    /** POST /admin/users/{id}/transfer — reassign all authored content. */
    public function transferOwnership(Request $request, string $id): Response
    {
        if (!$this->verifyCsrf($request)) { $this->flash('error', 'Security check failed.'); return $this->back(); }
        /** @var UserService $users */
        $users = $this->app->make(UserService::class);
        $from = $users->find((int)$id);
        $toId = (int) $request->input('to_user_id', 0);
        $to = $toId > 0 ? $users->find($toId) : null;
        if (!$from || !$to || $toId === (int)$id) {
            $this->flash('error', 'Choose a valid user to transfer content to.');
            return $this->redirect("/admin/users/{$id}/edit#danger");
        }
        if (!$this->canManageOrFail($from, '#danger')) return $this->redirect("/admin/users/{$id}/edit#danger");

        /** @var \App\Core\Database $db */
        $db = $this->app->make(\App\Core\Database::class);
        $count = $db->selectOne('SELECT COUNT(*) AS c FROM {posts} WHERE author_id = :a AND deleted_at IS NULL', ['a' => (int)$id]);
        $db->execute('UPDATE {posts} SET author_id = :to WHERE author_id = :from', ['to' => $toId, 'from' => (int)$id]);

        $moved = (int) ($count['c'] ?? 0);
        $actor = (string) ($this->user()['display_name'] ?? 'admin');
        $toName = (string) ($to['display_name'] ?: $to['username']);
        \App\Services\ActivityLogService::record((int)$id, 'user.ownership_transferred', 'user', (int)$id,
            "{$moved} item(s) transferred to {$toName} by {$actor}");
        \App\Services\ActivityLogService::record($toId, 'user.ownership_received', 'user', (int)$id,
            "Received {$moved} item(s) from " . ($from['display_name'] ?: $from['username']) . " (by {$actor})");

        $this->flash('success', "Transferred {$moved} item(s) to {$toName}.");
        return $this->redirect("/admin/users/{$id}/edit#danger");
    }

    // ==================================================================
    // Helpers
    // ==================================================================

    private function setLifecycleStatus(Request $request, int $id, string $status, string $event, string $message): Response
    {
        if (!$this->verifyCsrf($request)) { $this->flash('error', 'Security check failed.'); return $this->back(); }
        if ($id === $this->userId()) {
            $this->flash('error', "You can't change the status of your own account.");
            return $this->redirect("/admin/users/{$id}/edit#danger");
        }
        /** @var UserService $users */
        $users = $this->app->make(UserService::class);
        $target = $users->find($id);
        if (!$target) return $this->abort(404);
        if (!$this->canManageOrFail($target, '#danger')) return $this->redirect("/admin/users/{$id}/edit#danger");
        if ($status !== 'active' && $this->isLastAdmin($target)) {
            $this->flash('error', 'This is the last administrator account — assign another admin first.');
            return $this->redirect("/admin/users/{$id}/edit#danger");
        }
        $users->update($id, ['status' => $status]);
        \App\Services\ActivityLogService::record($id, $event, 'user', $id,
            ucfirst($status) . ' by ' . ($this->user()['display_name'] ?? 'admin'));
        $this->flash('success', $message);
        return $this->redirect("/admin/users/{$id}/edit#danger");
    }

    /**
     * Central hierarchy guard for every destructive/admin action on a user.
     * Flashes an error and returns false when the actor may not manage $target
     * (target is at or above the actor's level, or is the actor themselves).
     */
    private function canManageOrFail(array $target, string $tab = ''): bool
    {
        /** @var \App\Services\AccessControl $ac */
        $ac = $this->app->make(\App\Services\AccessControl::class);
        if ($ac->canManage($this->user(), $target)) {
            return true;
        }
        $this->flash('error', 'You do not have permission to manage this user — they are at or above your access level.');
        return false;
    }

    /** True when $target is the only remaining active admin/super_admin. */
    private function isLastAdmin(array $target): bool
    {
        if (!in_array($target['role'] ?? '', ['admin', 'super_admin'], true)) return false;
        /** @var \App\Core\Database $db */
        $db = $this->app->make(\App\Core\Database::class);
        $row = $db->selectOne(
            "SELECT COUNT(*) AS c FROM {users}
             WHERE role IN ('admin','super_admin') AND status = 'active'
               AND deleted_at IS NULL AND id <> :id",
            ['id' => (int) $target['id']]
        );
        return (int) ($row['c'] ?? 0) === 0;
    }
}
