<?php $this->extend('layouts.app'); ?>
<?php $this->section('content'); ?>

<div class="mb-5">
    <h2 class="text-xl font-semibold text-slate-900">Settings</h2>
    <p class="text-sm text-slate-500">Configure your site.</p>
</div>

<div>
    <?php $this->include('settings._nav', compact('tab', 'base')); ?>

    <!-- Form -->
    <div class="mt-0">
        <div class="bg-white rounded-xl border border-slate-200 p-6">
            <h3 class="font-semibold text-slate-900 mb-5">General Settings</h3>
            <form method="POST" action="<?= $base ?>/admin/settings/general" class="space-y-5">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">

                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Site Title</label>
                    <input type="text" name="site_title" value="<?= htmlspecialchars($values['site_title'] ?? '') ?>"
                        class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500 outline-none">
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Tagline</label>
                    <input type="text" name="tagline" value="<?= htmlspecialchars($values['tagline'] ?? '') ?>"
                        class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500 outline-none">
                    <p class="text-xs text-slate-500 mt-1">A short description of your site.</p>
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Admin Email</label>
                    <input type="email" name="admin_email" value="<?= htmlspecialchars($values['admin_email'] ?? '') ?>"
                        class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500 outline-none">
                </div>

<?php
                    // Timezone, date and time formats (1.2.45). Times are stored in UTC
                    // and shown in this zone everywhere: admin, themes and apps.
                    $tzNow  = \App\Core\Time::timezone();
                    $dfNow  = (string) ($values['date_format'] ?? \App\Core\Time::DEFAULT_DATE_FORMAT) ?: \App\Core\Time::DEFAULT_DATE_FORMAT;
                    $tfNow  = (string) ($values['time_format'] ?? \App\Core\Time::DEFAULT_TIME_FORMAT) ?: \App\Core\Time::DEFAULT_TIME_FORMAT;
                    $wkNow  = (int) ($values['week_starts_on'] ?? 1);
                    $sample = time();
                    $dateFormats = ['F j, Y', 'M j, Y', 'j F Y', 'd/m/Y', 'm/d/Y', 'Y-m-d', 'd.m.Y'];
                    $timeFormats = ['g:i a', 'g:i A', 'H:i', 'H:i:s'];
                    if (!in_array($dfNow, $dateFormats, true)) $dateFormats[] = $dfNow;
                    if (!in_array($tfNow, $timeFormats, true)) $timeFormats[] = $tfNow;
                    $inputCls = 'w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500 outline-none bg-white';
                ?>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label for="bh-timezone" class="block text-sm font-medium text-slate-700 mb-1.5">Timezone</label>
                        <select id="bh-timezone" name="timezone" class="<?= $inputCls ?>">
                            <?php foreach (\App\Core\Time::grouped() as $region => $zones): ?>
                                <optgroup label="<?= htmlspecialchars($region) ?>">
                                    <?php foreach ($zones as $id => $label): ?>
                                        <option value="<?= htmlspecialchars($id) ?>" <?= $id === $tzNow ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                                    <?php endforeach; ?>
                                </optgroup>
                            <?php endforeach; ?>
                        </select>
                        <p class="text-xs text-slate-500 mt-1">
                            Local time now: <strong class="text-slate-700"><?= htmlspecialchars(\App\Core\Time::format($sample)) ?></strong>
                            (UTC<?= htmlspecialchars(\App\Core\Time::offset()) ?>). Every date on the site, in the admin, themes and apps, is shown in this timezone.
                        </p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1.5">Language</label>
                        <select name="language" class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500 outline-none">
                            <?php $lang = $values['language'] ?? 'en_US'; ?>
                            <option value="en_US" <?= $lang === 'en_US' ? 'selected' : '' ?>>English (US)</option>
                            <option value="en_GB" <?= $lang === 'en_GB' ? 'selected' : '' ?>>English (UK)</option>
                            <option value="es_ES" <?= $lang === 'es_ES' ? 'selected' : '' ?>>Spanish</option>
                            <option value="fr_FR" <?= $lang === 'fr_FR' ? 'selected' : '' ?>>French</option>
                            <option value="de_DE" <?= $lang === 'de_DE' ? 'selected' : '' ?>>German</option>
                        </select>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <label for="bh-date-format" class="block text-sm font-medium text-slate-700 mb-1.5">Date format</label>
                        <select id="bh-date-format" name="date_format" class="<?= $inputCls ?>">
                            <?php foreach ($dateFormats as $f): ?>
                                <option value="<?= htmlspecialchars($f) ?>" <?= $f === $dfNow ? 'selected' : '' ?>><?= htmlspecialchars(\App\Core\Time::format($sample, $f)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="bh-time-format" class="block text-sm font-medium text-slate-700 mb-1.5">Time format</label>
                        <select id="bh-time-format" name="time_format" class="<?= $inputCls ?>">
                            <?php foreach ($timeFormats as $f): ?>
                                <option value="<?= htmlspecialchars($f) ?>" <?= $f === $tfNow ? 'selected' : '' ?>><?= htmlspecialchars(\App\Core\Time::format($sample, $f)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="bh-week" class="block text-sm font-medium text-slate-700 mb-1.5">Week starts on</label>
                        <select id="bh-week" name="week_starts_on" class="<?= $inputCls ?>">
                            <?php foreach (['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'] as $i => $d): ?>
                                <option value="<?= $i ?>" <?= $i === $wkNow ? 'selected' : '' ?>><?= $d ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <button type="submit" class="px-5 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg shadow-sm">
                    <?= icon('document-check', 'w-4 h-4 mr-1') ?> Save changes
                </button>
            </form>
        </div>
    </div>
</div>

<?php $this->endSection(); ?>
