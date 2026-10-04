<?php $this->extend('layouts.app'); ?>
<?php $this->section('content'); ?>

<div class="flex items-center justify-between mb-5 flex-wrap gap-3">
    <div>
        <h2 class="text-xl font-semibold text-slate-900">Users</h2>
        <p class="text-sm text-slate-500">Manage user accounts.</p>
    </div>
    <a href="<?= $base ?>/admin/users/create" class="bh-btn bh-btn--primary">
        <?= icon('user-plus', 'w-4 h-4') ?> New user
    </a>
</div>

<div class="bh-card bh-card--pad mb-5">
    <form method="GET" class="flex flex-wrap gap-3">
        <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search users..."
            class="bh-input flex-1 min-w-[200px]">
        <select name="role" class="bh-select bh-select--auto">
            <option value="">All roles</option>
            <?php foreach ($roles as $r): ?>
                <option value="<?= $r ?>" <?= $role === $r ? 'selected' : '' ?>><?= ucwords(str_replace('_', ' ', $r)) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="bh-btn bh-btn--secondary">Filter</button>
    </form>
</div>

<div class="bh-card overflow-hidden">
    <?php if (empty($users)): ?>
        <?php $__filtered = !empty($search) || !empty($role); ?>
        <div class="bh-empty">
            <span class="bh-empty__icon"><?= icon('users') ?></span>
            <p class="bh-empty__title"><?= $__filtered ? 'No users match' : 'No users yet' ?></p>
            <p class="bh-empty__text"><?= $__filtered ? 'Try a different search or role.' : 'People you add appear here.' ?></p>
            <div class="bh-empty__actions">
                <?php if ($__filtered): ?><a href="<?= $base ?>/admin/users" class="bh-btn bh-btn--secondary">Clear filters</a><?php endif; ?>
                <a href="<?= $base ?>/admin/users/create" class="bh-btn bh-btn--primary"><?= icon('user-plus', 'w-4 h-4') ?> New user</a>
            </div>
        </div>
    <?php else: ?>
    <table class="w-full text-sm">
        <thead class="bg-slate-50 border-b border-slate-200">
            <tr>
                <th class="text-left px-5 py-3 font-medium text-slate-600">User</th>
                <th class="text-left px-5 py-3 font-medium text-slate-600 hidden md:table-cell">Email</th>
                <th class="text-left px-5 py-3 font-medium text-slate-600">Role</th>
                <th class="text-left px-5 py-3 font-medium text-slate-600 hidden md:table-cell">Last Login</th>
                <th class="text-right px-5 py-3 font-medium text-slate-600">Actions</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            <?php
                // Every listed user's photo in one query, not one per row.
                $__thumbs = \App\Core\Application::getInstance()->make(\App\Services\AvatarService::class)->thumbnails(array_column($users, 'id'));
            ?>
            <?php foreach ($users as $u): ?>
            <tr class="hover:bg-slate-50">
                <td class="px-5 py-3">
                    <div class="flex items-center gap-3">
                        <?= bh_user_avatar($u, 'w-9 h-9 text-sm', $__thumbs[(int) $u['id']] ?? '') ?>
                        <div>
                            <a href="<?= $base ?>/admin/users/<?= $u['id'] ?>/edit" class="font-medium text-slate-900 hover:text-blue-600">
                                <?= htmlspecialchars($u['display_name'] ?? $u['username']) ?>
                            </a>
                            <div class="text-xs text-slate-500">@<?= htmlspecialchars($u['username']) ?></div>
                        </div>
                    </div>
                </td>
                <td class="px-5 py-3 text-slate-600 hidden md:table-cell"><?= htmlspecialchars($u['email']) ?></td>
                <td class="px-5 py-3">
                    <span class="bh-badge bh-badge--blue">
                        <?= ucwords(str_replace('_', ' ', $u['role'])) ?>
                    </span>
                </td>
                <td class="px-5 py-3 text-slate-500 text-xs hidden md:table-cell">
                    <?= $u['last_login_at'] ? date('M j, Y', strtotime($u['last_login_at'])) : 'Never' ?>
                </td>
                <td class="px-5 py-3 text-right">
                    <div class="inline-flex items-center gap-1">
                        <?php $canManageRow = \App\Core\Application::getInstance()->make(\App\Services\AccessControl::class)->canManage($currentUser, $u); ?>
                        <a href="<?= $base ?>/admin/users/<?= $u['id'] ?>/edit" class="p-2 text-slate-400 hover:text-blue-600 hover:bg-blue-50 rounded" title="Edit">
                            <?= icon('pencil', 'w-4 h-4') ?>
                        </a>
                        <?php if ($canManageRow): ?>
                        <form method="POST" action="<?= $base ?>/admin/users/<?= $u['id'] ?>/delete" class="inline" onsubmit="return confirm('Delete this user?')">
                            <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
                            <button class="p-2 text-slate-400 hover:text-red-600 hover:bg-red-50 rounded" title="Delete">
                                <?= icon('trash', 'w-4 h-4') ?>
                            </button>
                        </form>
                        <?php else: ?>
                        <span class="p-2 text-slate-200" title="You can't manage this user (higher or equal access level)">
                            <?= icon('lock-closed', 'w-4 h-4') ?>
                        </span>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<?php $this->endSection(); ?>
