<?php $this->extend('layouts.app'); ?>
<?php $this->section('content'); ?>

<div class="flex items-center justify-between mb-5 flex-wrap gap-3">
    <div>
        <h2 class="text-xl font-semibold text-slate-900">Users</h2>
        <p class="text-sm text-slate-500">Manage user accounts.</p>
    </div>
    <a href="<?= $base ?>/admin/users/create" class="inline-flex items-center gap-2 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium shadow-sm">
        <?= icon('user-plus', 'w-4 h-4') ?> New User
    </a>
</div>

<div class="bg-white rounded-xl border border-slate-200 p-4 mb-5">
    <form method="GET" class="flex flex-wrap gap-3">
        <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search users..."
            class="flex-1 min-w-[200px] px-3 py-2 border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-200 focus:border-blue-500 outline-none">
        <select name="role" class="px-3 py-2 border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-200 focus:border-blue-500 outline-none">
            <option value="">All roles</option>
            <?php foreach ($roles as $r): ?>
                <option value="<?= $r ?>" <?= $role === $r ? 'selected' : '' ?>><?= ucwords(str_replace('_', ' ', $r)) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-lg text-sm font-medium">Filter</button>
    </form>
</div>

<div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
    <?php if (empty($users)): ?>
        <div class="text-center py-16 text-slate-500">
            <?= icon('user-minus', 'w-12 h-12 text-slate-300 mb-3') ?>
            <p>No users found.</p>
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
            <?php foreach ($users as $u): ?>
            <tr class="hover:bg-slate-50">
                <td class="px-5 py-3">
                    <div class="flex items-center gap-3">
                        <div class="w-9 h-9 rounded-full bg-gradient-to-br from-blue-400 to-blue-600 grid place-items-center text-white text-sm font-semibold">
                            <?= strtoupper(substr($u['display_name'] ?? $u['username'], 0, 1)) ?>
                        </div>
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
                    <span class="text-xs px-2 py-0.5 rounded-full font-medium bg-blue-50 text-blue-700">
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
