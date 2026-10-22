<?php $this->extend('layouts.app'); ?>
<?php $this->section('content'); ?>
<?php
require_once __DIR__ . '/_ui.php';
$driver = $values['driver'] ?? 'mail';
?>
<div class="bh-set">
    <?php $this->include('settings._head', compact('tab', 'base')); ?>

    <div class="bh-set__stack">
        <form method="POST" action="<?= $base ?>/admin/settings/email" class="bh-set__form">
            <input type="hidden" name="_csrf" value="<?= bh_set_e($csrf) ?>">

            <div data-settings-live="mail-warning">
            <?php if (!empty($mail['foreignFrom'])): ?>
                <div class="bh-note bh-note--warn"><?= icon('exclamation-triangle', 'w-4 h-4') ?><span>
                    Emails are sent from <strong><?= bh_set_e($mail['from']) ?></strong>, which isn't on this site's domain.
                    Most mail servers refuse or spam-filter mail sent as another domain, so sign-in codes and password resets may not arrive.
                    <?php if ($driver !== 'smtp'): ?>Change it to <strong><?= bh_set_e($mail['defaultFrom']) ?></strong>, or send through that provider's SMTP server.<?php else: ?>Make sure your SMTP account is allowed to send as this address.<?php endif; ?>
                </span></div>
            <?php elseif (empty($mail['realDomain'])): ?>
                <div class="bh-note bh-note--warn"><?= icon('exclamation-triangle', 'w-4 h-4') ?><span>
                    This site's address (<?= bh_set_e($mail['domain'] ?? '') ?>) isn't a public domain, so there is no automatic sender address. Set APP_URL in .env to the site's real address, or type a From email.
                </span></div>
            <?php endif; ?>
            </div>

            <?= bh_set_card_open('Sender', 'Who your site\'s email comes from.') ?>
                <?= bh_set_row('From email', '<input type="email" id="set-from-email" name="from_email" class="bh-input" placeholder="' . bh_set_e($mail['defaultFrom'] ?? 'noreply@yourdomain.com') . '" value="' . bh_set_e($values['from_email'] ?? '') . '">',
                    'Use an address on <strong class="font-medium text-slate-700">' . bh_set_e($mail['domain'] ?? '') . '</strong>. Empty: ' . bh_set_e($mail['defaultFrom'] ?? '') . '.', ['for' => 'set-from-email']) ?>
                <?= bh_set_row('From name', '<input type="text" id="set-from-name" name="from_name" class="bh-input" placeholder="' . bh_set_e($mail['siteTitle'] ?? 'Site name') . '" value="' . bh_set_e($values['from_name'] ?? '') . '">',
                    'The name people see next to the address. Empty: your site name.', ['for' => 'set-from-name']) ?>
            <?= bh_set_card_close() ?>

            <?= bh_set_card_open('Sending', 'How the mail leaves the server.') ?>
                <?= bh_set_row('Send with', '<select name="driver" id="email-driver" class="bh-select">' . bh_set_options(['mail' => 'PHP mail(): works on most cPanel hosts', 'smtp' => 'SMTP: recommended for deliverability'], $driver) . '</select>', '', ['for' => 'email-driver']) ?>
                <div id="smtp-fields" class="border-t border-slate-100"<?= $driver === 'smtp' ? '' : ' hidden' ?>>
                    <?= bh_set_row('SMTP host', '<input type="text" id="set-smtp-host" name="smtp_host" class="bh-input" placeholder="smtp.yourdomain.com" value="' . bh_set_e($values['smtp_host'] ?? '') . '">', '', ['for' => 'set-smtp-host']) ?>
                    <?= bh_set_row('Port', '<input type="number" id="set-smtp-port" name="smtp_port" class="bh-input max-w-[8rem]" value="' . bh_set_e($values['smtp_port'] ?? '587') . '">', '', ['for' => 'set-smtp-port']) ?>
                    <?= bh_set_row('Encryption', '<select id="set-smtp-enc" name="smtp_encryption" class="bh-select">' . bh_set_options(['tls' => 'STARTTLS (port 587)', 'ssl' => 'SSL (port 465)', 'none' => 'None'], $values['smtp_encryption'] ?? 'tls') . '</select>', '', ['for' => 'set-smtp-enc']) ?>
                    <?= bh_set_row('Username', '<input type="text" id="set-smtp-user" name="smtp_username" autocomplete="off" class="bh-input" value="' . bh_set_e($values['smtp_username'] ?? '') . '">', '', ['for' => 'set-smtp-user']) ?>
                    <div data-settings-live="smtp-password" class="border-t border-slate-100">
                        <?= bh_set_row('Password', '<input type="password" id="set-smtp-pass" name="smtp_password" value="" autocomplete="new-password" class="bh-input" placeholder="' . (!empty($mail['hasPassword']) ? 'Saved: leave empty to keep it' : '') . '">', '', ['for' => 'set-smtp-pass']) ?>
                        <?php if (!empty($mail['hasPassword'])): ?>
                            <div class="border-t border-slate-100"><?= bh_set_toggle('smtp_password_clear', false, 'Remove the saved password', '', ['hidden0' => false, 'sub' => true]) ?></div>
                        <?php endif; ?>
                    </div>
                </div>
            <?= bh_set_card_close() ?>

            <?= bh_set_save() ?>
        </form>

        <?= bh_set_card_open('Send a test email', 'Sends a message to <strong class="font-medium text-slate-700">' . bh_set_e($currentUser['email'] ?? '') . '</strong> with the saved settings above.', [
            'action' => '<form method="POST" action="' . $base . '/admin/settings/email/test" class="m-0"><input type="hidden" name="_csrf" value="' . bh_set_e($csrf) . '"><button type="submit" class="bh-btn bh-btn--secondary bh-btn--sm">' . icon('paper-airplane', 'w-4 h-4') . 'Send test</button></form>',
        ]) ?>
        <?= bh_set_card_close() ?>
    </div>
</div>

<script>
(function () {
    var driver = document.getElementById('email-driver');
    var smtp = document.getElementById('smtp-fields');
    if (driver && smtp) {
        driver.addEventListener('change', function () { smtp.hidden = driver.value !== 'smtp'; });
    }
})();
</script>

<?php $this->endSection(); ?>
