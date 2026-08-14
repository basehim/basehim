<?php $this->extend('layouts.auth'); ?>
<?php $this->section('content'); ?>

<div class="w-full max-w-md">
    <div class="text-center mb-8">
                <div class="inline-flex mb-4"><?= brand_logo(56) ?></div>
        <h1 class="text-2xl font-semibold text-slate-900">Create your account</h1>
        <p class="text-sm text-slate-500 mt-1">Join in a few seconds.</p>
    </div>

    <div class="bg-white rounded-2xl shadow-xl shadow-blue-100/40 border border-slate-100 px-8 py-8">
        <?php if (!empty($flash)): ?>
            <div class="mb-5 px-3 py-2.5 rounded-lg text-sm border <?php
                echo $flash['type'] === 'error' ? 'bg-red-50 border-red-200 text-red-700' :
                    ($flash['type'] === 'success' ? 'bg-green-50 border-green-200 text-green-700' :
                    'bg-blue-50 border-blue-200 text-blue-700');
            ?>">
                <?= icon($flash['type'] === 'error' ? 'fa-circle-xmark' : ($flash['type'] === 'success' ? 'fa-circle-check' : 'fa-circle-info'), 'w-4 h-4 mr-1.5') ?>
                <?= htmlspecialchars($flash['message']) ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="<?= $base ?>/admin/register" class="space-y-4">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">

            <?php if (!empty($honeypot)): ?>
            <div aria-hidden="true" style="position:absolute;left:-9999px;top:-9999px;height:0;overflow:hidden;">
                <label>Website<input type="text" name="website" tabindex="-1" autocomplete="off"></label>
            </div>
            <?php endif; ?>

            <div>
                <label for="display_name" class="block text-sm font-medium text-slate-700 mb-1.5">Display name</label>
                <input type="text" name="display_name" id="display_name"
                    class="w-full px-3 py-2.5 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500 outline-none transition"
                    placeholder="Jane Doe">
            </div>

            <div>
                <label for="username" class="block text-sm font-medium text-slate-700 mb-1.5">Username</label>
                <input type="text" name="username" id="username" required
                    class="w-full px-3 py-2.5 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500 outline-none transition"
                    placeholder="janedoe">
            </div>

            <div>
                <label for="email" class="block text-sm font-medium text-slate-700 mb-1.5">Email</label>
                <input type="email" name="email" id="email" required
                    class="w-full px-3 py-2.5 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500 outline-none transition"
                    placeholder="you@example.com">
            </div>

            <div>
                <label for="password" class="block text-sm font-medium text-slate-700 mb-1.5">Password</label>
                <div class="relative">
                    <input type="password" name="password" id="password" required minlength="8"
                        class="w-full px-3 pr-10 py-2.5 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500 outline-none transition"
                        placeholder="At least 8 characters">
                    <button type="button" id="bh-pw-toggle" tabindex="-1" aria-label="Show password"
                        class="absolute inset-y-0 right-0 flex items-center pr-3 text-slate-400 hover:text-slate-600">
                        <?= icon('eye', 'w-5 h-5', ['id' => 'bh-pw-eye']) ?><?= icon('eye-slash', 'w-5 h-5 hidden', ['id' => 'bh-pw-eyeslash']) ?>
                    </button>
                </div>
            </div>

            <button type="submit"
                class="w-full bg-blue-600 hover:bg-blue-700 text-white font-medium py-2.5 rounded-lg shadow-sm transition flex items-center justify-center gap-2">
                <?= icon('user-plus', 'w-4 h-4') ?>
                Create account
            </button>
        </form>

        <p class="text-center text-sm text-slate-500 mt-6">
            Already have an account?
            <a href="<?= $base ?>/admin/login" class="text-blue-600 hover:text-blue-700 font-medium">Sign in</a>
        </p>
    </div>
</div>

<script>
(function () {
    var btn = document.getElementById('bh-pw-toggle');
    var input = document.getElementById('password');
    var eyeOn = document.getElementById('bh-pw-eye');
    var eyeOff = document.getElementById('bh-pw-eyeslash');
    if (btn && input) {
        btn.addEventListener('click', function () {
            var show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            if (eyeOn) eyeOn.classList.toggle('hidden', show);
            if (eyeOff) eyeOff.classList.toggle('hidden', !show);
            btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
        });
    }
})();
</script>

<?php $this->endSection(); ?>
