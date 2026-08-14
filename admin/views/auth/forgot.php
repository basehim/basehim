<?php $this->extend('layouts.auth'); ?>
<?php $this->section('content'); ?>

<div class="w-full max-w-md">
    <div class="text-center mb-8">
                <div class="inline-flex mb-4"><?= brand_logo(56) ?></div>
        <h1 class="text-2xl font-semibold text-slate-900">Reset your password</h1>
        <p class="text-sm text-slate-500 mt-1">Enter your email and we'll send you a reset link.</p>
    </div>

    <div class="bg-white rounded-2xl shadow-xl shadow-blue-100/40 border border-slate-100 px-8 py-8">
        <form method="POST" action="<?= $base ?>/admin/forgot-password" class="space-y-5">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf ?? '') ?>">
            <div>
                <label for="email" class="block text-sm font-medium text-slate-700 mb-1.5">Email Address</label>
                <input type="email" name="email" id="email" required
                    class="w-full px-3 py-2.5 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500 outline-none transition">
            </div>
            <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-medium py-2.5 rounded-lg shadow-sm">
                <?= icon('paper-airplane', 'w-4 h-4 mr-1') ?> Send Reset Link
            </button>
            <a href="<?= $base ?>/admin/login" class="block text-center text-sm text-slate-500 hover:text-blue-600">
                <?= icon('arrow-left', 'w-4 h-4 mr-1') ?> Back to sign in
            </a>
        </form>
    </div>
</div>

<?php $this->endSection(); ?>
