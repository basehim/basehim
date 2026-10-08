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
$tf = $twoFactor ?? ['policy' => 'optional', 'trustDays' => 30, 'emergency' => false, 'mailer' => 'mail', 'users' => 0];
$policies = [
    'off'      => ['Off', 'Nobody is asked for a code, including people who turned it on in their profile.'],
    'optional' => ['Optional', 'Each person decides in My Profile › Two-step verification.'],
    'admins'   => ['Required for administrators', 'Administrators always get a code; everyone else can choose.'],
    'all'      => ['Required for everyone', 'Every account gets a code each time it signs in on a new device.'],
];
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

            <!-- Two-step verification -->
            <div class="bg-white rounded-xl border border-slate-200 p-6" id="two-factor">
                <h3 class="font-semibold text-slate-900 mb-1">Two-step verification</h3>
                <p class="text-sm text-slate-500 mb-4">After the password, ask for a 6-digit code sent to the account's email address. A stolen password alone is then not enough to sign in.
                    <?= (int) ($tf['users'] ?? 0) ?> <?= (int) ($tf['users'] ?? 0) === 1 ? 'person has' : 'people have' ?> turned it on.</p>

                <?php if (!empty($tf['appUrlMissing'])): ?>
                <div class="bg-amber-50 border border-amber-200 rounded-lg p-3 text-sm text-amber-700 mb-4">
                    <?= icon('exclamation-triangle', 'w-4 h-4 mr-1') ?> <code class="px-1 bg-slate-100 rounded">APP_URL</code> in the <code class="px-1 bg-slate-100 rounded">.env</code> file is empty or points to this computer. Set it to the site's address (for example https://example.com) so password-reset links in emails always point here.
                </div>
                <?php endif; ?>
                <?php if (!empty($tf['noEmail'])): ?>
                <div class="bg-amber-50 border border-amber-200 rounded-lg p-3 text-sm text-amber-700 mb-4">
                    <?= icon('exclamation-triangle', 'w-4 h-4 mr-1') ?> <?= (int) $tf['noEmail'] ?> active <?= (int) $tf['noEmail'] === 1 ? 'account has' : 'accounts have' ?> no email address, so <?= (int) $tf['noEmail'] === 1 ? 'it' : 'they' ?> cannot receive codes and <?= (int) $tf['noEmail'] === 1 ? 'is' : 'are' ?> never asked for one. Add an address in Users.
                </div>
                <?php endif; ?>
                <?php if (!empty($tf['emergency'])): ?>
                <div class="bg-amber-50 border border-amber-200 rounded-lg p-3 text-sm text-amber-700 mb-4">
                    <?= icon('exclamation-triangle', 'w-4 h-4 mr-1') ?> Two-step verification is suspended for everyone because the file <code class="px-1 bg-slate-100 rounded">storage/disable-2fa</code> exists. Delete it to turn codes back on.
                </div>
                <?php endif; ?>

                <fieldset class="space-y-2 mb-5">
                    <legend class="block text-sm font-medium text-slate-700 mb-2">Who is asked for a code</legend>
                    <?php foreach ($policies as $key => [$label, $help]): ?>
                    <label class="flex items-start gap-2 p-3 rounded-lg border border-slate-200 hover:bg-slate-50 cursor-pointer">
                        <input type="radio" name="two_factor" value="<?= $key ?>" <?= ($tf['policy'] ?? 'optional') === $key ? 'checked' : '' ?>
                            class="mt-0.5 border-slate-300 text-blue-600 focus:ring-blue-500">
                        <div>
                            <div class="text-sm font-medium text-slate-700"><?= htmlspecialchars($label) ?></div>
                            <div class="text-xs text-slate-500"><?= htmlspecialchars($help) ?></div>
                        </div>
                    </label>
                    <?php endforeach; ?>
                </fieldset>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1.5" for="two_factor_trust_days">Trusted devices</label>
                        <select name="two_factor_trust_days" id="two_factor_trust_days" class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500 outline-none">
                            <?php foreach ([0 => 'Ask every time', 7 => 'Remember a device for 7 days', 14 => 'Remember a device for 14 days', 30 => 'Remember a device for 30 days', 60 => 'Remember a device for 60 days', 90 => 'Remember a device for 90 days'] as $d => $label): ?>
                                <option value="<?= $d ?>" <?= (int) ($tf['trustDays'] ?? 30) === $d ? 'selected' : '' ?>><?= $label ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="text-xs text-slate-500 mt-1">People can tick "Don't ask on this device" on the code screen. A password change or "Sign out everywhere" ends it.</p>
                    </div>
                    <div class="text-xs text-slate-500 space-y-2">
                        <p><strong class="text-slate-700">Codes are sent by email</strong> through your <a class="text-blue-600 hover:text-blue-700" href="<?= $base ?>/admin/settings/email">Email settings</a> (sending with <?= ($tf['mailer'] ?? 'mail') === 'smtp' ? 'SMTP' : 'the server\'s PHP mail' ?>). Send yourself a test email before requiring codes.</p>
                        <p><strong class="text-slate-700">If email stops working</strong>, an administrator can turn codes off for one person in Users › Edit, and the site owner can suspend them for everyone by creating an empty file named <code class="px-1 bg-slate-100 rounded">disable-2fa</code> in the <code class="px-1 bg-slate-100 rounded">storage</code> folder with the hosting file manager.</p>
                    </div>
                </div>
            </div>

            <!-- Registration -->
            <div class="bg-white rounded-xl border border-slate-200 p-6">
                <h3 class="font-semibold text-slate-900 mb-4">Registration</h3>
                <label class="flex items-start gap-2 mb-4">
                    <input type="checkbox" name="allow_registration" value="1" <?= !empty($values['allow_registration']) ? 'checked' : '' ?>
                        class="mt-0.5 rounded border-slate-300 text-blue-600 focus:ring-blue-500">
                    <div>
                        <div class="text-sm font-medium text-slate-700">Allow public registration</div>
                        <div class="text-xs text-slate-500">Visitors can create an account at <code class="px-1 bg-slate-100 rounded">/admin/register</code> and through the API.</div>
                    </div>
                </label>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Default role for new users</label>
                    <select name="default_role" class="w-full sm:w-72 px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500 outline-none">
                        <?php foreach ($__roles as $slug => $r): if (($slug) === 'super_admin' || ($slug) === 'admin') continue; ?>
                            <option value="<?= htmlspecialchars($slug) ?>" <?= $defaultRole === $slug ? 'selected' : '' ?>><?= htmlspecialchars($r['label'] ?? ucfirst($slug)) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="text-xs text-slate-500 mt-1">Administrator roles are never given to self-registered accounts.</p>
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
                <h3 class="font-semibold text-slate-900 mb-4">Sign-in protection</h3>

                <label class="flex items-start gap-2 mb-4">
                    <input type="checkbox" name="remember_me" value="1" <?= !isset($values['remember_me']) || !empty($values['remember_me']) ? 'checked' : '' ?>
                        class="mt-0.5 rounded border-slate-300 text-blue-600 focus:ring-blue-500">
                    <div>
                        <div class="text-sm font-medium text-slate-700">Offer "Keep me signed in"</div>
                        <div class="text-xs text-slate-500">A checkbox on the sign-in page that keeps people signed in for 30 days with a secure cookie.</div>
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
                        <label class="block text-sm font-medium text-slate-700 mb-1.5">Wrong passwords before a security question</label>
                        <input type="number" name="login_attempt_limit" min="1" max="10" value="<?= htmlspecialchars((string) ($values['login_attempt_limit'] ?? 3)) ?>"
                            class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500 outline-none">
                        <p class="text-xs text-slate-500 mt-1">A simple sum must then be answered with the password. Default 3.</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1.5">Wrong passwords before sign-in pauses</label>
                        <input type="number" name="lockout_after" min="3" max="50" value="<?= htmlspecialchars((string) ($values['lockout_after'] ?? 10)) ?>"
                            class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500 outline-none">
                        <p class="text-xs text-slate-500 mt-1">For that account from that network. Default 10.</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1.5">Pause length (minutes)</label>
                        <input type="number" name="lockout_minutes" min="1" max="1440" value="<?= htmlspecialchars((string) ($values['lockout_minutes'] ?? 15)) ?>"
                            class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500 outline-none">
                        <p class="text-xs text-slate-500 mt-1">Grows by this much each further time, up to a day. Default 15.</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1.5">Wrong answers before an unlock code</label>
                        <input type="number" name="captcha_fail_limit" min="1" max="10" value="<?= htmlspecialchars((string) ($values['captcha_fail_limit'] ?? 3)) ?>"
                            class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500 outline-none">
                        <p class="text-xs text-slate-500 mt-1">Wrong answers to the security question before an unlock code is emailed. Default 3.</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1.5">Account-wide failures before a security question</label>
                        <input type="number" name="account_attempt_limit" min="3" max="100" value="<?= htmlspecialchars((string) ($values['account_attempt_limit'] ?? 10)) ?>"
                            class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500 outline-none">
                        <p class="text-xs text-slate-500 mt-1">Wrong passwords for one account across <em>all</em> networks before everyone signing in to it answers a security question. Stops distributed guessing. Default 10.</p>
                    </div>
                </div>

                <label class="flex items-start gap-2">
                    <input type="checkbox" name="otp_enabled" value="1" <?= !isset($values['otp_enabled']) || !empty($values['otp_enabled']) ? 'checked' : '' ?>
                        class="mt-0.5 rounded border-slate-300 text-blue-600 focus:ring-blue-500">
                    <div>
                        <div class="text-sm font-medium text-slate-700">Email an unlock code after repeated failures</div>
                        <div class="text-xs text-slate-500">The account owner receives a one-time code that unlocks sign-in. The password is still needed afterwards.</div>
                    </div>
                </label>
            </div>

            <button type="submit" class="px-5 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg shadow-sm">
                <?= icon('document-check', 'w-4 h-4 mr-1.5') ?>Save Authentication Settings
            </button>
        </form>
    </div>
</div>

<?php $this->endSection(); ?>
