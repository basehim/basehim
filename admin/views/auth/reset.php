<?php $this->extend('layouts.auth'); ?>
<?php $this->section('content'); ?>

<div class="w-full max-w-md">
    <div class="text-center mb-8">
                <div class="inline-flex mb-4"><?= brand_logo(56) ?></div>
        <h1 class="text-2xl font-semibold text-slate-900">Choose a new password</h1>
    </div>

    <div class="bg-white rounded-2xl shadow-xl shadow-blue-100/40 border border-slate-100 px-8 py-8">
        <form method="POST" action="<?= $base ?>/admin/reset-password" class="space-y-5">
            <input type="hidden" name="token" value="<?= htmlspecialchars($token ?? '') ?>">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf ?? '') ?>">
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1.5">New Password</label>
                <input type="password" name="password" required minlength="8"
                    class="w-full px-3 py-2.5 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500 outline-none">
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1.5">Confirm Password</label>
                <input type="password" name="password_confirm" required minlength="8"
                    class="w-full px-3 py-2.5 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500 outline-none">
            </div>
            <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-medium py-2.5 rounded-lg shadow-sm">
                Save New Password
            </button>
        </form>
    </div>
</div>

<?php $this->endSection(); ?>
