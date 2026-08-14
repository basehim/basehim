<?php $this->extend('layouts.app'); ?>
<?php $this->section('content'); ?>
<?php
// Roles for the default-registration-role dropdown.
try {
    $__ac = \App\Core\Application::getInstance()->make(\App\Services\AccessControl::class);
    $__roles = $__ac->roles();
} catch (\Throwable) {
    $__roles = ['subscriber' => ['label' => 'Subscriber'], 'author' => ['label' => 'Author'], 'editor' => ['label' => 'Editor']];
}
$defaultRole = $values['default_role'] ?? 'subscriber';
?>

<div class="mb-5">
    <h2 class="text-xl font-semibold text-slate-900">Settings</h2>
    <p class="text-sm text-slate-500">Configure your site.</p>
</div>

<div>
    <?php $this->include('settings._nav', compact('tab', 'base')); ?>
    <div class="mt-0">
        <form method="POST" action="<?= $base ?>/admin/settings/authorization" class="space-y-5">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
            <!-- Ensure unchecked toggles post a 0 (checkboxes send nothing when off). -->
            <input type="hidden" name="allow_registration" value="0">
            <input type="hidden" name="remember_me" value="0">
            <input type="hidden" name="honeypot" value="0">
            <input type="hidden" name="welcome_email" value="0">
            <input type="hidden" name="otp_enabled" value="0">

            <!-- Registration -->
            <div class="bg-white rounded-xl border border-slate-200 p-6">
                <h3 class="font-semibold text-slate-900 mb-4">Registration</h3>
                <label class="flex items-start gap-2 mb-4">
                    <input type="checkbox" name="allow_registration" value="1" <?= !empty($values['allow_registration']) ? 'checked' : '' ?>
                        class="mt-0.5 rounded border-slate-300 text-blue-600 focus:ring-blue-500">
                    <div>
                        <div class="text-sm font-medium text-slate-700">Allow public registration</div>
                        <div class="text-xs text-slate-500">Visitors can create an account at <code class="px-1 bg-slate-100 rounded">/admin/register</code>.</div>
                    </div>
                </label>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Default role for new users</label>
                    <select name="default_role" class="w-full sm:w-72 px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500 outline-none">
                        <?php foreach ($__roles as $slug => $r): if (($slug) === 'super_admin' || ($slug) === 'admin') continue; ?>
                            <option value="<?= htmlspecialchars($slug) ?>" <?= $defaultRole === $slug ? 'selected' : '' ?>><?= htmlspecialchars($r['label'] ?? ucfirst($slug)) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="text-xs text-slate-500 mt-1">Administrator roles are intentionally excluded from self-registration.</p>
                </div>
                <label class="flex items-start gap-2 mt-4">
                    <input type="checkbox" name="welcome_email" value="1" <?= !empty($values['welcome_email']) ? 'checked' : '' ?>
                        class="mt-0.5 rounded border-slate-300 text-blue-600 focus:ring-blue-500">
                    <div>
                        <div class="text-sm font-medium text-slate-700">Send a welcome email to new users</div>
                        <div class="text-xs text-slate-500">Sent when an account is created (registration or by an admin).</div>
                    </div>
                </label>
            </div>

            <!-- Login security -->
            <div class="bg-white rounded-xl border border-slate-200 p-6">
                <h3 class="font-semibold text-slate-900 mb-4">Login Security</h3>

                <label class="flex items-start gap-2 mb-4">
                    <input type="checkbox" name="remember_me" value="1" <?= !isset($values['remember_me']) || !empty($values['remember_me']) ? 'checked' : '' ?>
                        class="mt-0.5 rounded border-slate-300 text-blue-600 focus:ring-blue-500">
                    <div>
                        <div class="text-sm font-medium text-slate-700">Enable "Remember me"</div>
                        <div class="text-xs text-slate-500">Shows a checkbox on login that keeps users signed in via a secure cookie.</div>
                    </div>
                </label>

                <label class="flex items-start gap-2 mb-4">
                    <input type="checkbox" name="honeypot" value="1" <?= !isset($values['honeypot']) || !empty($values['honeypot']) ? 'checked' : '' ?>
                        class="mt-0.5 rounded border-slate-300 text-blue-600 focus:ring-blue-500">
                    <div>
                        <div class="text-sm font-medium text-slate-700">Honeypot bot protection</div>
                        <div class="text-xs text-slate-500">Adds a hidden field that automated bots fill in — submissions with it set are silently rejected.</div>
                    </div>
                </label>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-4">
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1.5">Wrong-attempt limit before captcha</label>
                        <input type="number" name="login_attempt_limit" min="1" max="10" value="<?= htmlspecialchars((string) ($values['login_attempt_limit'] ?? 3)) ?>"
                            class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500 outline-none">
                        <p class="text-xs text-slate-500 mt-1">After this many failed passwords, a math captcha is shown. Default 3.</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1.5">Captcha failures before email OTP</label>
                        <input type="number" name="captcha_fail_limit" min="1" max="10" value="<?= htmlspecialchars((string) ($values['captcha_fail_limit'] ?? 3)) ?>"
                            class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500 outline-none">
                        <p class="text-xs text-slate-500 mt-1">After the captcha is failed this many times, an email OTP is required. Default 3.</p>
                    </div>
                </div>

                <label class="flex items-start gap-2">
                    <input type="checkbox" name="otp_enabled" value="1" <?= !isset($values['otp_enabled']) || !empty($values['otp_enabled']) ? 'checked' : '' ?>
                        class="mt-0.5 rounded border-slate-300 text-blue-600 focus:ring-blue-500">
                    <div>
                        <div class="text-sm font-medium text-slate-700">Enable email OTP fallback</div>
                        <div class="text-xs text-slate-500">After repeated password + captcha failures, email a one-time code to the account owner as a final verification step.</div>
                    </div>
                </label>
            </div>

            <button type="submit" class="px-5 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg shadow-sm">
                <?= icon('document-check', 'w-4 h-4 mr-1.5') ?>Save Authorization Settings
            </button>
        </form>
    </div>
</div>

<?php $this->endSection(); ?>
