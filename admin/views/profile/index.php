<?php $this->extend('layouts.app'); ?>
<?php $this->section('content'); ?>

<div class="mb-5">
    <h2 class="text-xl font-semibold text-slate-900">My Profile</h2>
    <p class="text-sm text-slate-500">Update your account details.</p>
</div>

<?php if (!empty($emailChange)): ?>
<div class="max-w-5xl mb-5 bg-white rounded-xl border border-amber-200 p-5">
    <h3 class="text-sm font-semibold text-slate-900">Confirm your new email address</h3>
    <p class="text-xs text-slate-500 mt-1">Two-step verification sends sign-in codes to your email, so the change waits until you enter the code we sent to <?= htmlspecialchars($emailChange) ?>.</p>
    <form method="POST" action="<?= $base ?>/admin/profile/email/confirm" class="flex flex-wrap items-center gap-2 mt-3">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
        <input type="text" name="code" required inputmode="numeric" maxlength="7" autocomplete="one-time-code" aria-label="Code"
            class="w-40 px-3 py-2 border border-slate-300 rounded-lg text-center font-mono text-xl focus:ring-2 focus:ring-blue-200 focus:border-blue-500 outline-none">
        <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium shadow-sm">Confirm new email</button>
        <button type="submit" name="cancel" value="1" formnovalidate class="px-3 py-2 text-sm text-slate-500 hover:text-slate-700">Keep the current address</button>
    </form>
</div>
<?php endif; ?>

<form method="POST" action="<?= $base ?>/admin/profile" class="grid grid-cols-1 lg:grid-cols-3 gap-5 max-w-5xl">
    <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">

    <div class="lg:col-span-2 space-y-5">
        <div class="bg-white rounded-xl border border-slate-200 p-5 space-y-4">
            <h3 class="text-sm font-semibold text-slate-900">Account Info</h3>
            <div class="pb-4 border-b border-slate-100">
                <div class="text-xs text-slate-500 mb-3">Signed in as <span class="font-medium text-slate-700"><?= htmlspecialchars($currentUser['display_name'] ?? $currentUser['username']) ?></span> · @<?= htmlspecialchars($currentUser['username']) ?> · <?= ucwords(str_replace('_', ' ', $currentUser['role'])) ?></div>
                <?php
                    $avatarUser = $currentUser;
                    $avatarUrl  = $base . '/admin/profile/avatar';
                    include BASEHIM_ROOT . '/admin/views/partials/avatar-field.php';
                ?>
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

                <?php
                    // Public author address: display-name based, never the login name.
                    $bhAuthorSvc = \App\Core\Application::getInstance()->make(\App\Services\AuthorService::class);
                    $bhAuthorSlug = !empty($currentUser['id']) ? $bhAuthorSvc->slugFor($currentUser) : '';
                ?>
                <div class="md:col-span-2">
                    <label class="block text-xs text-slate-500 mb-1">Author page address</label>
                    <div class="flex items-center rounded-lg border border-slate-300 focus-within:ring-2 focus-within:ring-blue-200 focus-within:border-blue-500 overflow-hidden">
                        <span class="px-3 py-2 text-sm text-slate-500 bg-slate-50 border-r border-slate-300 whitespace-nowrap">/author/</span>
                        <input type="text" name="author_slug" value="<?= htmlspecialchars($bhAuthorSlug) ?>" pattern="[a-z0-9-]+" maxlength="180"
                            class="flex-1 min-w-0 px-3 py-2 outline-none text-sm">
                    </div>
                    <p class="text-xs text-slate-500 mt-1">Lowercase letters, numbers and hyphens. Shown publicly; it should not be the login name.</p>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-xl border border-slate-200 p-5 space-y-4">
            <h3 class="text-sm font-semibold text-slate-900"><?= icon('lock-closed', 'w-4 h-4 text-blue-500 mr-2') ?>Password</h3>
            <p class="text-xs text-slate-500">Your current password is needed to change your password or your email address. Leave the new password blank to keep it. A new password signs out your other devices.</p>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs text-slate-500 mb-1">Current Password</label>
                    <input type="password" name="current_password" autocomplete="current-password" class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500 outline-none">
                </div>
                <div>
                    <label class="block text-xs text-slate-500 mb-1">New Password (8+ chars)</label>
                    <input type="password" name="new_password" minlength="8" autocomplete="new-password" class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500 outline-none">
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
                <div class="flex justify-between"><span>Member since</span><span class="text-slate-500"><?= bh_date($currentUser['created_at'], 'M Y') ?></span></div>
                <?php if (!empty($currentUser['last_login_at'])): ?>
                <div class="flex justify-between"><span>Last login</span><span class="text-slate-500"><?= bh_datetime($currentUser['last_login_at'], 'M j, Y g:i a') ?></span></div>
                <?php endif; ?>
            </div>
        </div>

        <button type="submit" class="w-full px-4 py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium shadow-sm">
            <?= icon('document-check', 'w-4 h-4 mr-1') ?> Save Profile
        </button>
    </div>
</form>

<?php
$tfa = $twoFactor ?? ['policy' => 'optional', 'enabled' => false, 'required' => false, 'active' => false, 'emergency' => false, 'setup' => false, 'email' => '', 'since' => ''];
$tfaOn = !empty($tfa['enabled']) || !empty($tfa['required']);
?>
<div class="grid grid-cols-1 lg:grid-cols-3 gap-5 max-w-5xl mt-5">
    <div class="lg:col-span-2 bg-white rounded-xl border border-slate-200 p-5 space-y-4" id="two-factor">
        <div class="flex items-start justify-between gap-3">
            <div>
                <h3 class="text-sm font-semibold text-slate-900"><?= icon('shield-check', 'w-4 h-4 text-blue-500 mr-2') ?>Two-step verification</h3>
                <p class="text-xs text-slate-500 mt-1">After your password, enter a 6-digit code we email to <?= htmlspecialchars((string) $tfa['email']) ?>. Someone who learns your password still can't sign in.</p>
            </div>
            <?php if ($tfa['policy'] === 'off'): ?>
                <span class="shrink-0 text-[11px] font-semibold px-2 py-0.5 rounded-full bg-slate-100 text-slate-600">Unavailable</span>
            <?php elseif ($tfaOn): ?>
                <span class="shrink-0 text-[11px] font-semibold px-2 py-0.5 rounded-full bg-emerald-100 text-emerald-700">On</span>
            <?php else: ?>
                <span class="shrink-0 text-[11px] font-semibold px-2 py-0.5 rounded-full bg-amber-100 text-amber-700">Off</span>
            <?php endif; ?>
        </div>

        <?php if ($tfa['policy'] === 'off'): ?>
            <p class="text-sm text-slate-600">An administrator has turned two-step verification off for this site.</p>
        <?php elseif (!empty($tfa['setup'])): ?>
            <form method="POST" action="<?= $base ?>/admin/profile/two-factor/confirm" class="space-y-3">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
                <label class="block text-xs text-slate-500" for="tfa-code">Code from the email we just sent</label>
                <div class="flex flex-wrap items-center gap-2">
                    <input type="text" name="code" id="tfa-code" required inputmode="numeric" maxlength="7" autocomplete="one-time-code" autofocus
                        class="w-40 px-3 py-2 border border-slate-300 rounded-lg text-center font-mono text-xl focus:ring-2 focus:ring-blue-200 focus:border-blue-500 outline-none">
                    <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium shadow-sm">Turn on</button>
                </div>
            </form>
            <div class="flex flex-wrap items-center gap-4 text-sm">
                <form method="POST" action="<?= $base ?>/admin/profile/two-factor/start">
                    <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
                    <button type="submit" class="text-blue-600 hover:text-blue-700">Send a new code</button>
                </form>
                <form method="POST" action="<?= $base ?>/admin/profile/two-factor/cancel">
                    <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
                    <button type="submit" class="text-slate-500 hover:text-slate-700">Cancel</button>
                </form>
            </div>
        <?php elseif (!empty($tfa['required']) && empty($tfa['enabled'])): ?>
            <p class="text-sm text-slate-600">This site requires two-step verification for your account, so you are asked for a code whenever you sign in on a new device.</p>
        <?php elseif ($tfaOn): ?>
            <p class="text-sm text-slate-600">
                On<?= !empty($tfa['since']) ? ' since ' . htmlspecialchars(bh_date((string) $tfa['since'], 'M j, Y')) : '' ?>: we email you a code when you sign in on a new device.
                <?php if (!empty($tfa['required'])): ?>Required for your account by the site's settings.<?php endif; ?>
                <?php if (!empty($tfa['emergency'])): ?><span class="text-amber-700">Codes are suspended site-wide at the moment (storage/disable-2fa).</span><?php endif; ?>
            </p>
            <?php if (empty($tfa['required'])): ?>
            <form method="POST" action="<?= $base ?>/admin/profile/two-factor/disable" class="flex flex-wrap items-end gap-2">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
                <div>
                    <label class="block text-xs text-slate-500 mb-1" for="tfa-pass">Current password</label>
                    <input type="password" name="current_password" id="tfa-pass" required autocomplete="current-password"
                        class="w-56 px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500 outline-none">
                </div>
                <button type="submit" class="px-4 py-2 border border-red-200 text-red-600 hover:bg-red-50 rounded-lg text-sm font-medium">Turn off</button>
            </form>
            <?php endif; ?>
        <?php else: ?>
            <form method="POST" action="<?= $base ?>/admin/profile/two-factor/start">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
                <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium shadow-sm">Set up two-step verification</button>
                <p class="text-xs text-slate-500 mt-2">We'll email a code to confirm the address works before turning it on.</p>
            </form>
        <?php endif; ?>
    </div>

    <div class="bg-white rounded-xl border border-slate-200 p-5 space-y-3" id="sessions">
        <h3 class="text-sm font-semibold text-slate-900"><?= icon('computer-desktop', 'w-4 h-4 text-blue-500 mr-2') ?>Signed-in devices</h3>
        <p class="text-xs text-slate-500">Lost a phone, or signed in on a shared computer? Sign out everywhere else: other browsers, "keep me signed in" cookies, app sign-ins, connected AI clients and trusted devices stop working. API keys are kept. This browser stays signed in.</p>
        <form method="POST" action="<?= $base ?>/admin/profile/sessions/revoke" onsubmit="return confirm('Sign out of every other device and browser?');">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
            <button type="submit" class="w-full px-4 py-2 border border-slate-300 hover:bg-slate-50 text-slate-700 rounded-lg text-sm font-medium">Sign out everywhere else</button>
        </form>
    </div>
</div>

<?php $this->endSection(); ?>
