<?php $this->extend('layouts.auth'); ?>
<?php $this->section('content'); ?>

<div class="w-full max-w-md">
    <div class="text-center mb-8">
        <div class="inline-flex w-14 h-14 rounded-2xl bg-gradient-to-br from-slate-700 to-slate-900 items-center justify-center text-white text-2xl shadow-lg mb-4">
            <?= icon('shield-check', 'w-4 h-4') ?>
        </div>
        <h1 class="text-2xl font-semibold text-slate-900">Enter verification code</h1>
        <p class="text-sm text-slate-500 mt-1">We emailed a one-time code to the account owner.</p>
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

        <form method="POST" action="<?= $base ?>/admin/login/otp" class="space-y-5">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
            <div>
                <label for="code" class="block text-sm font-medium text-slate-700 mb-1.5">6-digit code</label>
                <input type="text" name="code" id="code" required inputmode="numeric" pattern="[0-9]*" maxlength="6" autofocus autocomplete="one-time-code"
                    class="w-full px-3 py-2.5 text-center text-2xl tracking-[0.5em] font-semibold border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500 outline-none transition"
                    placeholder="------">
            </div>
            <button type="submit"
                class="w-full bg-blue-600 hover:bg-blue-700 text-white font-medium py-2.5 rounded-lg shadow-sm transition flex items-center justify-center gap-2">
                <?= icon('check', 'w-4 h-4') ?>
                Verify &amp; sign in
            </button>
        </form>

        <p class="text-center text-sm text-slate-500 mt-6">
            <a href="<?= $base ?>/admin/login" class="text-blue-600 hover:text-blue-700 font-medium">Back to sign in</a>
        </p>
    </div>

    <p class="text-center text-xs text-slate-400 mt-6">
        The code expires in 10 minutes.
    </p>
</div>

<?php $this->endSection(); ?>
