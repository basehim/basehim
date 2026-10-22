<?php $this->extend('layouts.app'); ?>
<?php $this->section('content'); ?>
<?php
require_once __DIR__ . '/_ui.php';

// Administration email (1.2.66): where site notices go. When it is your own
// account's address, you can change your account with it.
$adminEmailNow = strtolower(trim((string) ($values['admin_email'] ?? '')));
$myEmail = strtolower(trim((string) ($currentUser['email'] ?? '')));

// Timezone, date and time formats (1.2.45). Times are stored in UTC and shown
// in this zone everywhere: admin, themes and apps.
$tzNow  = \App\Core\Time::timezone();
$dfNow  = (string) ($values['date_format'] ?? \App\Core\Time::DEFAULT_DATE_FORMAT) ?: \App\Core\Time::DEFAULT_DATE_FORMAT;
$tfNow  = (string) ($values['time_format'] ?? \App\Core\Time::DEFAULT_TIME_FORMAT) ?: \App\Core\Time::DEFAULT_TIME_FORMAT;
$wkNow  = (int) ($values['week_starts_on'] ?? 1);
$sample = time();
$dateFormats = ['F j, Y', 'M j, Y', 'j F Y', 'd/m/Y', 'm/d/Y', 'Y-m-d', 'd.m.Y'];
$timeFormats = ['g:i a', 'g:i A', 'H:i', 'H:i:s'];
if (!in_array($dfNow, $dateFormats, true)) $dateFormats[] = $dfNow;
if (!in_array($tfNow, $timeFormats, true)) $timeFormats[] = $tfNow;
$lang = $values['language'] ?? 'en_US';

$tzOptions = '';
foreach (\App\Core\Time::grouped() as $region => $zones) {
    $tzOptions .= '<optgroup label="' . bh_set_e($region) . '">';
    foreach ($zones as $id => $label) $tzOptions .= '<option value="' . bh_set_e($id) . '"' . ($id === $tzNow ? ' selected' : '') . '>' . bh_set_e($label) . '</option>';
    $tzOptions .= '</optgroup>';
}
$fmt = static function (array $formats, string $now) use ($sample): string {
    $h = '';
    foreach ($formats as $f) $h .= '<option value="' . bh_set_e($f) . '"' . ($f === $now ? ' selected' : '') . '>' . bh_set_e(\App\Core\Time::format($sample, $f)) . '</option>';
    return $h;
};
?>
<div class="bh-set<?= !empty($settingsHome) ? ' bh-set--home' : '' ?>">
    <?php $this->include('settings._head', compact('tab', 'base') + ['settingsHome' => $settingsHome ?? false]); ?>

    <form method="POST" action="<?= $base ?>/admin/settings/general" class="bh-set__form">
        <input type="hidden" name="_csrf" value="<?= bh_set_e($csrf) ?>">

        <?= bh_set_card_open('Site', 'How your site is named.') ?>
            <?= bh_set_row('Site title', '<input type="text" id="set-site-title" name="site_title" class="bh-input" value="' . bh_set_e($values['site_title'] ?? '') . '">', '', ['for' => 'set-site-title']) ?>
            <?= bh_set_row('Tagline', '<input type="text" id="set-tagline" name="tagline" class="bh-input" value="' . bh_set_e($values['tagline'] ?? '') . '">', 'A short description of your site.', ['for' => 'set-tagline']) ?>
        <?= bh_set_card_close() ?>

        <?= bh_set_card_open('Notices', 'Where the site sends its own email.') ?>
            <div class="bh-set-row" data-admin-email data-initial="<?= bh_set_e($adminEmailNow) ?>" data-account="<?= bh_set_e($myEmail) ?>">
                <div class="bh-set-row__text">
                    <label class="bh-set-row__label" for="bh-admin-email">Administration email</label>
                    <p class="bh-set-row__help">Where site notices go, such as new comments. Also saved as ADMIN_EMAIL in .env. Your sign-in email is set on <a href="<?= $base ?>/admin/profile">My Profile</a>.</p>
                </div>
                <div class="bh-set-row__control">
                    <input type="email" id="bh-admin-email" name="admin_email" class="bh-input" value="<?= bh_set_e($values['admin_email'] ?? '') ?>">
                    <div data-admin-email-account hidden class="mt-3 grid gap-3 rounded-xl border border-slate-200 bg-slate-50 p-3.5">
                        <label class="flex items-start justify-between gap-4 text-sm text-slate-700">
                            <span>Also change my account email (<strong data-admin-email-mine><?= bh_set_e($myEmail) ?></strong>), so I sign in with the new address</span>
                            <span class="bh-switch"><input type="checkbox" role="switch" class="bh-switch__input" name="admin_email_account" value="1" checked><span class="bh-switch__track" aria-hidden="true"></span></span>
                        </label>
                        <div data-admin-email-password>
                            <label for="bh-admin-email-password" class="bh-set-fields__label">Current password</label>
                            <input type="password" id="bh-admin-email-password" name="admin_email_password" autocomplete="current-password" class="bh-input max-w-xs">
                            <p class="bh-help">Needed to change your account email, as on My Profile. With two-step verification on, a code is sent to the new address first.</p>
                        </div>
                    </div>
                </div>
            </div>
        <?= bh_set_card_close() ?>

        <?= bh_set_card_open('Language and time', 'Every date on the site, in the admin, themes and apps, is shown this way.') ?>
            <?= bh_set_row('Timezone', '<select id="bh-timezone" name="timezone" class="bh-select">' . $tzOptions . '</select>',
                'Local time now: <strong class="font-medium text-slate-700">' . bh_set_e(\App\Core\Time::format($sample)) . '</strong> (UTC' . bh_set_e(\App\Core\Time::offset()) . ').', ['for' => 'bh-timezone']) ?>
            <?= bh_set_row('Language', '<select id="set-language" name="language" class="bh-select">' . bh_set_options(['en_US' => 'English (US)', 'en_GB' => 'English (UK)', 'es_ES' => 'Spanish', 'fr_FR' => 'French', 'de_DE' => 'German'], $lang) . '</select>', '', ['for' => 'set-language']) ?>
            <?= bh_set_row('Date format', '<select id="bh-date-format" name="date_format" class="bh-select">' . $fmt($dateFormats, $dfNow) . '</select>', '', ['for' => 'bh-date-format']) ?>
            <?= bh_set_row('Time format', '<select id="bh-time-format" name="time_format" class="bh-select">' . $fmt($timeFormats, $tfNow) . '</select>', '', ['for' => 'bh-time-format']) ?>
            <?= bh_set_row('Week starts on', '<select id="bh-week" name="week_starts_on" class="bh-select">' . bh_set_options(['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'], $wkNow) . '</select>', '', ['for' => 'bh-week']) ?>
        <?= bh_set_card_close() ?>

        <?= bh_set_save() ?>
    </form>
</div>
<script>
(function () {
    var box = document.querySelector('[data-admin-email]');
    if (!box) return;
    var input = box.querySelector('input[name="admin_email"]');
    var panel = box.querySelector('[data-admin-email-account]');
    var check = box.querySelector('input[name="admin_email_account"]');
    var pw = box.querySelector('[data-admin-email-password]');
    function norm(v) { return String(v || '').trim().toLowerCase(); }
    function sync() {
        var initial = box.getAttribute('data-initial'), mine = box.getAttribute('data-account');
        // Only when the address being replaced is your own account's.
        panel.hidden = !(mine && initial === mine && norm(input.value) !== initial && norm(input.value) !== '');
        pw.hidden = !check.checked;
    }
    input.addEventListener('input', sync);
    check.addEventListener('change', sync);
    // After an in-page save (settings-save.js), what was saved is the new starting point.
    box.closest('form').addEventListener('bh:settings-saved', function (e) {
        var d = (e && e.detail) || {};
        if (d.values && typeof d.values.admin_email === 'string') box.setAttribute('data-initial', norm(d.values.admin_email));
        if (typeof d.account_email === 'string') {
            box.setAttribute('data-account', norm(d.account_email));
            var m = box.querySelector('[data-admin-email-mine]'); if (m) m.textContent = norm(d.account_email);
        }
        sync();
    });
    sync();
})();
</script>

<?php $this->endSection(); ?>
