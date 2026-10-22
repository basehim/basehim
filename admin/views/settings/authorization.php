<?php $this->extend('layouts.app'); ?>
<?php $this->section('content'); ?>
<?php
require_once __DIR__ . '/_ui.php';
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
$roleOptions = [];
foreach ($__roles as $slug => $r) { if ($slug === 'super_admin' || $slug === 'admin') continue; $roleOptions[$slug] = $r['label'] ?? ucfirst($slug); }
// These switches post nothing when off, as before; the saver reads a missing key as off.
$onDefault = static fn(string $k): bool => !isset($values[$k]) || !empty($values[$k]);
$num = static fn(string $name, int $value, int $min, int $max): string =>
    '<input type="number" id="' . bh_set_id($name) . '" name="' . $name . '" min="' . $min . '" max="' . $max . '" class="bh-input max-w-[8rem]" value="' . $value . '">';
$users = (int) ($tf['users'] ?? 0);
?>
<div class="bh-set">
    <?php $this->include('settings._head', compact('tab', 'base')); ?>

    <form method="POST" action="<?= $base ?>/admin/settings/authorization" class="bh-set__form">
        <input type="hidden" name="_csrf" value="<?= bh_set_e($csrf) ?>">

        <?= bh_set_card_open('Two-step verification', 'After the password, ask for a 6-digit code sent to the account\'s email. A stolen password alone is then not enough. ' . $users . ' ' . ($users === 1 ? 'person has' : 'people have') . ' turned it on.', ['id' => 'two-factor']) ?>
            <?php if (!empty($tf['appUrlMissing']) || !empty($tf['noEmail']) || !empty($tf['emergency'])): ?>
            <div class="bh-set-card__block grid gap-2">
                <?php if (!empty($tf['appUrlMissing'])): ?>
                <div class="bh-note bh-note--warn"><?= icon('exclamation-triangle', 'w-4 h-4') ?><span><code>APP_URL</code> in the <code>.env</code> file is empty or points to this computer. Set it to the site's address (for example https://example.com) so password-reset links in emails always point here.</span></div>
                <?php endif; ?>
                <?php if (!empty($tf['noEmail'])): $n = (int) $tf['noEmail']; ?>
                <div class="bh-note bh-note--warn"><?= icon('exclamation-triangle', 'w-4 h-4') ?><span><?= $n ?> active <?= $n === 1 ? 'account has' : 'accounts have' ?> no email address, so <?= $n === 1 ? 'it' : 'they' ?> cannot receive codes and <?= $n === 1 ? 'is' : 'are' ?> never asked for one. Add an address in Users.</span></div>
                <?php endif; ?>
                <?php if (!empty($tf['emergency'])): ?>
                <div class="bh-note bh-note--warn"><?= icon('exclamation-triangle', 'w-4 h-4') ?><span>Two-step verification is suspended for everyone because the file <code>storage/disable-2fa</code> exists. Delete it to turn codes back on.</span></div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            <div class="bh-set-row bh-set-row--wide">
                <div class="bh-set-row__text"><span class="bh-set-row__label" id="set-2fa-label">Who is asked for a code</span></div>
                <div class="bh-set-row__control">
                    <div class="bh-choices sm:grid-cols-2" role="radiogroup" aria-labelledby="set-2fa-label">
                    <?php foreach ($policies as $key => [$label, $help]): ?>
                        <label class="bh-choice"><input type="radio" name="two_factor" value="<?= $key ?>" <?= ($tf['policy'] ?? 'optional') === $key ? 'checked' : '' ?>><span class="min-w-0"><span class="bh-choice__title"><?= bh_set_e($label) ?></span><span class="bh-choice__text"><?= bh_set_e($help) ?></span></span></label>
                    <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?= bh_set_row('Trusted devices', '<select name="two_factor_trust_days" id="two_factor_trust_days" class="bh-select">' . bh_set_options([0 => 'Ask every time', 7 => 'Remember a device for 7 days', 14 => 'Remember a device for 14 days', 30 => 'Remember a device for 30 days', 60 => 'Remember a device for 60 days', 90 => 'Remember a device for 90 days'], (int) ($tf['trustDays'] ?? 30)) . '</select>',
                'People can choose "Don\'t ask on this device" on the code screen. A password change or "Sign out everywhere" ends it.', ['for' => 'two_factor_trust_days']) ?>
            <div class="bh-set-card__block">
                <div class="bh-note"><?= icon('information-circle', 'w-4 h-4') ?><span><strong class="font-medium text-slate-900">Codes are sent by email</strong> through your <a href="<?= $base ?>/admin/settings/email">Email settings</a> (sending with <?= ($tf['mailer'] ?? 'mail') === 'smtp' ? 'SMTP' : 'the server\'s PHP mail' ?>). Send yourself a test email before requiring codes. <strong class="font-medium text-slate-900">If email stops working</strong>, an administrator can turn codes off for one person in Users › Edit, and the site owner can suspend them for everyone by creating an empty file named <code>disable-2fa</code> in the <code>storage</code> folder with the hosting file manager.</span></div>
            </div>
        <?= bh_set_card_close() ?>

        <?= bh_set_card_open('Registration') ?>
            <?= bh_set_toggle('allow_registration', !empty($values['allow_registration']), 'Allow public registration', 'Visitors can create an account at <code>/admin/register</code> and through the API.', ['hidden0' => false]) ?>
            <?= bh_set_row('Default role for new users', '<select id="set-default-role" name="default_role" class="bh-select">' . bh_set_options($roleOptions, $defaultRole) . '</select>', 'Administrator roles are never given to self-registered accounts.', ['for' => 'set-default-role']) ?>
            <?= bh_set_toggle('welcome_email', !empty($values['welcome_email']), 'Send a welcome email to new users', 'Sent when an account is created, by registration or by an administrator.', ['hidden0' => false]) ?>
        <?= bh_set_card_close() ?>

        <?= bh_set_card_open('Sign-in protection') ?>
            <?= bh_set_toggle('remember_me', $onDefault('remember_me'), 'Offer "Keep me signed in"', 'A choice on the sign-in page that keeps people signed in for 30 days with a secure cookie.', ['hidden0' => false]) ?>
            <?= bh_set_toggle('honeypot', $onDefault('honeypot'), 'Honeypot bot protection', 'Adds a hidden field that automated bots fill in; submissions with it set are silently rejected.', ['hidden0' => false]) ?>
            <?= bh_set_row('Wrong passwords before a security question', $num('login_attempt_limit', (int) ($values['login_attempt_limit'] ?? 3), 1, 10), 'A simple sum must then be answered with the password. Default 3.', ['for' => bh_set_id('login_attempt_limit')]) ?>
            <?= bh_set_row('Wrong passwords before sign-in pauses', $num('lockout_after', (int) ($values['lockout_after'] ?? 10), 3, 50), 'For that account from that network. Default 10.', ['for' => bh_set_id('lockout_after')]) ?>
            <?= bh_set_row('Pause length', '<div class="flex items-center gap-2">' . $num('lockout_minutes', (int) ($values['lockout_minutes'] ?? 15), 1, 1440) . '<span class="text-sm text-slate-500">minutes</span></div>', 'Grows by this much each further time, up to a day. Default 15.', ['for' => bh_set_id('lockout_minutes')]) ?>
            <?= bh_set_row('Wrong answers before an unlock code', $num('captcha_fail_limit', (int) ($values['captcha_fail_limit'] ?? 3), 1, 10), 'Wrong answers to the security question before an unlock code is emailed. Default 3.', ['for' => bh_set_id('captcha_fail_limit')]) ?>
            <?= bh_set_row('Account-wide failures before a security question', $num('account_attempt_limit', (int) ($values['account_attempt_limit'] ?? 10), 3, 100), 'Wrong passwords for one account across <em>all</em> networks before everyone signing in to it answers a security question. Stops distributed guessing. Default 10.', ['for' => bh_set_id('account_attempt_limit')]) ?>
            <?= bh_set_toggle('otp_enabled', $onDefault('otp_enabled'), 'Email an unlock code after repeated failures', 'The account owner receives a one-time code that unlocks sign-in. The password is still needed afterwards.', ['hidden0' => false]) ?>
        <?= bh_set_card_close() ?>

        <?= bh_set_save() ?>
    </form>
</div>

<?php $this->endSection(); ?>
