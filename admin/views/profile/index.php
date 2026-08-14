<?php $this->extend('layouts.app'); ?>
<?php $this->section('content'); ?>

<div class="mb-5">
    <h2 class="text-xl font-semibold text-slate-900">My Profile</h2>
    <p class="text-sm text-slate-500">Update your account details.</p>
</div>

<form method="POST" action="<?= $base ?>/admin/profile" class="grid grid-cols-1 lg:grid-cols-3 gap-5 max-w-5xl">
    <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">

    <div class="lg:col-span-2 space-y-5">
        <div class="bg-white rounded-xl border border-slate-200 p-5 space-y-4">
            <h3 class="text-sm font-semibold text-slate-900">Account Info</h3>
            <div class="flex items-center gap-4 pb-3 border-b border-slate-100">
                <div class="w-16 h-16 rounded-full bg-gradient-to-br from-blue-400 to-blue-600 grid place-items-center text-white text-xl font-semibold">
                    <?= strtoupper(substr($currentUser['display_name'] ?? $currentUser['username'], 0, 1)) ?>
                </div>
                <div>
                    <div class="font-semibold text-slate-900"><?= htmlspecialchars($currentUser['display_name'] ?? $currentUser['username']) ?></div>
                    <div class="text-xs text-slate-500">@<?= htmlspecialchars($currentUser['username']) ?> · <?= ucwords(str_replace('_', ' ', $currentUser['role'])) ?></div>
                </div>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs text-slate-500 mb-1">Username (read-only)</label>
                    <input type="text" value="<?= htmlspecialchars($currentUser['username']) ?>" readonly
                        class="w-full px-3 py-2 border border-slate-300 rounded-lg bg-slate-50">
                </div>
                <div>
                    <label class="block text-xs text-slate-500 mb-1">Email</label>
                    <input type="email" name="email" value="<?= htmlspecialchars($currentUser['email']) ?>" required
                        class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500 outline-none">
                </div>
                <div class="md:col-span-2">
                    <label class="block text-xs text-slate-500 mb-1">Display Name</label>
                    <input type="text" name="display_name" value="<?= htmlspecialchars($currentUser['display_name'] ?? '') ?>"
                        class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500 outline-none">
                </div>
                <div class="md:col-span-2">
                    <label class="block text-xs text-slate-500 mb-1">Bio</label>
                    <textarea name="bio" rows="3" class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500 outline-none"><?= htmlspecialchars($currentUser['bio'] ?? '') ?></textarea>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-xl border border-slate-200 p-5 space-y-4">
            <h3 class="text-sm font-semibold text-slate-900"><?= icon('lock-closed', 'w-4 h-4 text-blue-500 mr-2') ?>Change Password</h3>
            <p class="text-xs text-slate-500">Leave blank to keep your current password.</p>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs text-slate-500 mb-1">Current Password</label>
                    <input type="password" name="current_password" class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500 outline-none">
                </div>
                <div>
                    <label class="block text-xs text-slate-500 mb-1">New Password (8+ chars)</label>
                    <input type="password" name="new_password" minlength="8" class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500 outline-none">
                </div>
            </div>
        </div>
    </div>

    <div class="space-y-5">
        <div class="bg-white rounded-xl border border-slate-200 p-5">
            <h3 class="text-sm font-semibold text-slate-900 mb-2">Account Status</h3>
            <div class="text-sm space-y-2 text-slate-600">
                <div class="flex justify-between"><span>Role</span><span class="font-medium text-slate-900"><?= ucwords(str_replace('_', ' ', $currentUser['role'])) ?></span></div>
                <div class="flex justify-between"><span>Status</span><span class="text-green-700 font-medium"><?= ucfirst($currentUser['status']) ?></span></div>
                <div class="flex justify-between"><span>Member since</span><span class="text-slate-500"><?= date('M Y', strtotime($currentUser['created_at'])) ?></span></div>
                <?php if (!empty($currentUser['last_login_at'])): ?>
                <div class="flex justify-between"><span>Last login</span><span class="text-slate-500"><?= date('M j, Y g:i a', strtotime($currentUser['last_login_at'])) ?></span></div>
                <?php endif; ?>
            </div>
        </div>

        <button type="submit" class="w-full px-4 py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium shadow-sm">
            <?= icon('document-check', 'w-4 h-4 mr-1') ?> Save Profile
        </button>
    </div>
</form>

<?php $this->endSection(); ?>
