<?php $this->extend('layouts.auth'); ?>
<?php $this->section('content'); ?>

<div class="w-full max-w-md">
    <!-- Brand header -->
    <div class="text-center mb-8">
        <div class="inline-flex mb-4"><?= brand_logo(56) ?></div>
        <h1 class="text-2xl font-semibold text-slate-900">Welcome to Basehim</h1>
        <p class="text-sm text-slate-500 mt-1">Sign in to manage your site.</p>
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

        <form method="POST" action="<?= $base ?>/admin/login" class="space-y-5">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">

            <?php if (!empty($honeypot)): ?>
            <!-- Honeypot: hidden from humans, tempting to bots. Real users leave it empty. -->
            <div aria-hidden="true" style="position:absolute;left:-9999px;top:-9999px;height:0;overflow:hidden;">
                <label>Website<input type="text" name="website" tabindex="-1" autocomplete="off"></label>
            </div>
            <?php endif; ?>

            <div>
                <label for="login" class="block text-sm font-medium text-slate-700 mb-1.5">Email or Username</label>
                <div class="relative">
                    <span class="absolute inset-y-0 left-0 flex items-center pl-3 text-slate-400">
                        <?= icon('user', 'w-4 h-4') ?>
                    </span>
                    <input type="text" name="login" id="login" required autofocus value="<?= $lastLogin ?? '' ?>"
                        class="w-full pl-10 pr-3 py-2.5 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500 outline-none transition"
                        placeholder="you@example.com">
                </div>
            </div>

            <div>
                <div class="flex items-center justify-between mb-1.5">
                    <label for="password" class="block text-sm font-medium text-slate-700">Password</label>
                    <a href="<?= $base ?>/admin/forgot-password" class="text-xs text-blue-600 hover:text-blue-700">Forgot?</a>
                </div>
                <div class="relative">
                    <span class="absolute inset-y-0 left-0 flex items-center pl-3 text-slate-400">
                        <?= icon('lock-closed', 'w-4 h-4') ?>
                    </span>
                    <input type="password" name="password" id="password" required
                        class="w-full pl-10 pr-10 py-2.5 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500 outline-none transition"
                        placeholder="••••••••">
                    <button type="button" id="bh-pw-toggle" tabindex="-1" aria-label="Show password"
                        class="absolute inset-y-0 right-0 flex items-center pr-3 text-slate-400 hover:text-slate-600">
                        <?= icon('eye', 'w-5 h-5', ['id' => 'bh-pw-eye']) ?><?= icon('eye-slash', 'w-5 h-5 hidden', ['id' => 'bh-pw-eyeslash']) ?>
                    </button>
                </div>
            </div>

            <?php if (!empty($needCaptcha) && !empty($captcha)): ?>
            <div>
                <label for="captcha" class="block text-sm font-medium text-slate-700 mb-1.5">
                    Security check — what is <strong><?= htmlspecialchars($captcha['question']) ?></strong>?
                </label>
                <input type="hidden" name="captcha_token" value="<?= htmlspecialchars($captcha['token']) ?>">
                <input type="number" name="captcha" id="captcha" required inputmode="numeric"
                    class="w-full px-3 py-2.5 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500 outline-none transition"
                    placeholder="Answer">
            </div>
            <?php endif; ?>

            <?php if (!empty($rememberMe)): ?>
            <label class="flex items-center gap-2 select-none">
                <input type="checkbox" name="remember" value="1" class="rounded border-slate-300 text-blue-600 focus:ring-blue-500">
                <span class="text-sm text-slate-600">Remember me on this device</span>
            </label>
            <?php endif; ?>

            <button type="submit"
                class="w-full bg-blue-600 hover:bg-blue-700 text-white font-medium py-2.5 rounded-lg shadow-sm transition flex items-center justify-center gap-2">
                <?= icon('arrow-right-end-on-rectangle', 'w-4 h-4') ?>
                Sign in
            </button>
        </form>

        <?php if (!empty($allowRegistration)): ?>
        <p class="text-center text-sm text-slate-500 mt-6">
            Don't have an account?
            <a href="<?= $base ?>/admin/register" class="text-blue-600 hover:text-blue-700 font-medium">Create one</a>
        </p>
        <?php endif; ?>
    </div>

    <p class="text-center text-xs text-slate-400 mt-6">
        Basehim &copy; <?= date('Y') ?>
    </p>
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
