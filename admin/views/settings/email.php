<?php $this->extend('layouts.app'); ?>
<?php $this->section('content'); ?>

<div class="mb-5">
    <h2 class="text-xl font-semibold text-slate-900">Settings</h2>
    <p class="text-sm text-slate-500">Configure how Basehim sends email (password resets, notifications, app mail).</p>
</div>

<div>
    <?php $this->include('settings._nav', compact('tab', 'base')); ?>

    <div class="lg:col-span-3 space-y-5">
        <div class="bg-white rounded-xl border border-slate-200 p-6">
            <h3 class="font-semibold text-slate-900 mb-5">Email Settings</h3>
            <form method="POST" action="<?= $base ?>/admin/settings/email" class="space-y-5">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">

                <?php if (!empty($mail['foreignFrom'])): ?>
                <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                    Emails are sent from <strong><?= htmlspecialchars($mail['from']) ?></strong>, which isn't on this site's domain.
                    Most mail servers refuse or spam-filter mail sent as another domain, so sign-in codes and password resets may not arrive.
                    <?php if (($values['driver'] ?? 'mail') !== 'smtp'): ?>Change it to <strong><?= htmlspecialchars($mail['defaultFrom']) ?></strong>, or send through that provider's SMTP server.<?php else: ?>Make sure your SMTP account is allowed to send as this address.<?php endif; ?>
                </div>
                <?php elseif (empty($mail['realDomain'])): ?>
                <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                    This site's address (<?= htmlspecialchars($mail['domain'] ?? '') ?>) isn't a public domain, so there is no automatic sender address. Set APP_URL in .env to the site's real address, or type a From Email.
                </div>
                <?php endif; ?>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1.5">From Email</label>
                        <input type="email" name="from_email" value="<?= htmlspecialchars($values['from_email'] ?? '') ?>" placeholder="<?= htmlspecialchars($mail['defaultFrom'] ?? 'noreply@yourdomain.com') ?>"
                            class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500 outline-none">
                        <p class="text-xs text-slate-500 mt-1">Use an address on <strong><?= htmlspecialchars($mail['domain'] ?? '') ?></strong>. Leave empty to use <?= htmlspecialchars($mail['defaultFrom'] ?? '') ?>.</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1.5">From Name</label>
                        <input type="text" name="from_name" value="<?= htmlspecialchars($values['from_name'] ?? '') ?>" placeholder="<?= htmlspecialchars($mail['siteTitle'] ?? 'Site name') ?>"
                            class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500 outline-none">
                        <p class="text-xs text-slate-500 mt-1">The name people see next to the address. Leave empty to use your site name.</p>
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Mail Driver</label>
                    <select name="driver" id="email-driver"
                        class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500 outline-none">
                        <option value="mail" <?= ($values['driver'] ?? 'mail') === 'mail' ? 'selected' : '' ?>>PHP mail() — works on most cPanel hosts</option>
                        <option value="smtp" <?= ($values['driver'] ?? '') === 'smtp' ? 'selected' : '' ?>>SMTP — recommended for deliverability</option>
                    </select>
                </div>

                <div id="smtp-fields" class="space-y-4 border-t border-slate-100 pt-4" <?= ($values['driver'] ?? 'mail') === 'smtp' ? '' : 'style="display:none"' ?>>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div class="md:col-span-2">
                            <label class="block text-sm font-medium text-slate-700 mb-1.5">SMTP Host</label>
                            <input type="text" name="smtp_host" value="<?= htmlspecialchars($values['smtp_host'] ?? '') ?>" placeholder="smtp.yourdomain.com"
                                class="w-full px-3 py-2 border border-slate-300 rounded-lg outline-none focus:border-blue-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1.5">Port</label>
                            <input type="number" name="smtp_port" value="<?= htmlspecialchars($values['smtp_port'] ?? '587') ?>"
                                class="w-full px-3 py-2 border border-slate-300 rounded-lg outline-none focus:border-blue-500">
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1.5">Encryption</label>
                        <select name="smtp_encryption" class="w-full px-3 py-2 border border-slate-300 rounded-lg outline-none focus:border-blue-500">
                            <option value="tls" <?= ($values['smtp_encryption'] ?? 'tls') === 'tls' ? 'selected' : '' ?>>STARTTLS (port 587)</option>
                            <option value="ssl" <?= ($values['smtp_encryption'] ?? '') === 'ssl' ? 'selected' : '' ?>>SSL (port 465)</option>
                            <option value="none" <?= ($values['smtp_encryption'] ?? '') === 'none' ? 'selected' : '' ?>>None</option>
                        </select>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1.5">SMTP Username</label>
                            <input type="text" name="smtp_username" value="<?= htmlspecialchars($values['smtp_username'] ?? '') ?>" autocomplete="off"
                                class="w-full px-3 py-2 border border-slate-300 rounded-lg outline-none focus:border-blue-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1.5">SMTP Password</label>
                            <input type="password" name="smtp_password" value="" autocomplete="new-password" placeholder="<?= !empty($mail['hasPassword']) ? 'Saved — leave empty to keep it' : '' ?>"
                                class="w-full px-3 py-2 border border-slate-300 rounded-lg outline-none focus:border-blue-500">
                            <?php if (!empty($mail['hasPassword'])): ?>
                            <label class="inline-flex items-center gap-2 text-xs text-slate-500 mt-1.5"><input type="checkbox" name="smtp_password_clear" value="1"> Remove the saved password</label>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="pt-2">
                    <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium px-5 py-2.5 rounded-lg">Save Email Settings</button>
                </div>
            </form>
        </div>

        <div class="bg-white rounded-xl border border-slate-200 p-6">
            <h3 class="font-semibold text-slate-900 mb-2">Send a test email</h3>
            <p class="text-sm text-slate-500 mb-4">Sends a test message to <strong><?= htmlspecialchars($currentUser['email'] ?? '') ?></strong> using the saved settings above.</p>
            <form method="POST" action="<?= $base ?>/admin/settings/email/test">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
                <button type="submit" class="border border-slate-300 hover:border-blue-500 hover:text-blue-600 text-slate-700 text-sm font-medium px-5 py-2.5 rounded-lg">
                    <?= icon('paper-airplane', 'w-4 h-4 mr-1') ?> Send Test Email
                </button>
            </form>
        </div>
    </div>
</div>

<?php $this->endSection(); ?>

<?php $this->section('scripts'); ?>
<script>
(function () {
    var driver = document.getElementById('email-driver');
    var smtp = document.getElementById('smtp-fields');
    if (driver && smtp) {
        driver.addEventListener('change', function () {
            smtp.style.display = driver.value === 'smtp' ? '' : 'none';
        });
    }
})();
</script>
<?php $this->endSection(); ?>
