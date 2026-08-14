<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Http\Controllers\Controller;
use App\Services\AccessControl;

/**
 * RoleController — manage custom roles (Users → Roles).
 *
 * Built-in roles (config/capabilities.php) are read-only here; admins can
 * create additional roles with a hand-picked capability set and a level. A
 * custom role's level is capped below admin, and — like everything else — you
 * can only grant a role capabilities you hold yourself.
 */
class RoleController extends Controller
{
    public function index(Request $request): Response
    {
        $ac = $this->app->make(AccessControl::class);
        $session = $this->app->make(Session::class);

        return $this->view('roles.index', [
            'title'        => 'Roles',
            'currentUser'  => $this->user(),
            'roles'        => $ac->roles(),
            'catalog'      => $this->capabilityCatalog(),
            'appItems'  => $ac->appList(),
            'grantableCaps'=> \App\Http\Middleware\CheckCapability::effectiveCaps($this->user()),
            'csrf'         => $session->csrfToken(),
        ]);
    }

    public function store(Request $request): Response
    {
        if (!$this->verifyCsrf($request)) { $this->flash('error', 'Security check failed.'); return $this->back(); }
        $ac = $this->app->make(AccessControl::class);

        $label = trim((string) $request->input('label', ''));
        $slug  = trim((string) $request->input('slug', ''));
        $level = (int) $request->input('level', 25);
        $caps  = (array) $request->input('capabilities', []);
        $caps  = array_values(array_filter(array_map('strval', $caps), fn($c) => $c !== ''));

        if ($label === '' && $slug === '') {
            $this->flash('error', 'A role name is required.');
            return $this->redirect('/admin/roles');
        }

        // You can't grant a role a capability you don't hold yourself.
        $me = $this->user();
        $blocked = array_values(array_filter($caps, fn($c) => !$ac->canGrantCapability($me, $c)));
        $caps = array_values(array_filter($caps, fn($c) => $ac->canGrantCapability($me, $c)));

        // A custom role can't sit at/above the creator's level.
        $level = max(1, min($ac->userLevel($me) - 1, $level));

        $created = $ac->saveCustomRole($slug, $label, $level, $caps);
        if ($created === '') {
            $this->flash('error', 'Could not save role (name may collide with a built-in role).');
            return $this->redirect('/admin/roles');
        }

        \App\Services\ActivityLogService::record($this->userId(), 'user.role_created', 'role', null,
            "Custom role '{$created}' created by " . ($me['display_name'] ?? 'admin'));

        if ($blocked) {
            $this->flash('error', 'Role saved, but these capabilities were skipped (you do not hold them): ' . implode(', ', $blocked));
        } else {
            $this->flash('success', "Role '{$created}' saved.");
        }
        return $this->redirect('/admin/roles');
    }

    public function destroy(Request $request, string $slug): Response
    {
        if (!$this->verifyCsrf($request)) { $this->flash('error', 'Security check failed.'); return $this->back(); }
        $ac = $this->app->make(AccessControl::class);
        $roles = $ac->roles();

        if (!isset($roles[$slug]) || empty($roles[$slug]['custom'])) {
            $this->flash('error', 'Built-in roles cannot be deleted.');
            return $this->redirect('/admin/roles');
        }

        // Refuse if any user still holds this role.
        /** @var \App\Core\Database $db */
        $db = $this->app->make(\App\Core\Database::class);
        $inUse = $db->selectOne('SELECT COUNT(*) AS c FROM {users} WHERE role = :r AND deleted_at IS NULL', ['r' => $slug]);
        if ((int) ($inUse['c'] ?? 0) > 0) {
            $this->flash('error', 'That role is assigned to one or more users — reassign them first.');
            return $this->redirect('/admin/roles');
        }

        $ac->deleteCustomRole($slug);
        \App\Services\ActivityLogService::record($this->userId(), 'user.role_deleted', 'role', null,
            "Custom role '{$slug}' deleted by " . ($this->user()['display_name'] ?? 'admin'));
        $this->flash('success', "Role '{$slug}' deleted.");
        return $this->redirect('/admin/roles');
    }

    /** Grouped capability catalog for the role builder. */
    private function capabilityCatalog(): array
    {
        return [
            'Content'    => ['publish_posts', 'edit_posts', 'edit_others_posts', 'delete_posts', 'delete_others_posts',
                             'publish_pages', 'edit_pages', 'edit_others_pages', 'delete_pages', 'delete_others_pages'],
            'Media'      => ['upload_media', 'delete_media'],
            'Moderation' => ['moderate_comments', 'manage_taxonomies', 'manage_menus', 'manage_seo'],
            'Admin'      => ['manage_options', 'manage_users', 'manage_settings', 'manage_apps', 'manage_themes'],
            'Reading'    => ['read', 'read_private_meta'],
        ];
    }
}
