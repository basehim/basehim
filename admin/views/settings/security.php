<?php $this->extend('layouts.app'); ?>
<?php $this->section('content'); ?>
<?php
/*
 * Settings → Security (1.2.90): HTTPS (HSTS) and the Content-Security-Policy.
 * Everything is sent by App\Services\SecurityHeaders; the docblock there
 * explains each header.
 */
require_once __DIR__ . '/_ui.php';
$e = static fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$s = $security ?? \App\Services\SecurityHeaders::DEFAULTS;
$reports = $reports ?? [];
$modes = [
    'report'  => ['Report only', 'Scripts that the policy would block are reported below, and nothing is blocked. Start here.'],
    'enforce' => ['Enforce', 'Scripts the policy does not allow are blocked: inline event handlers, javascript: links, and scripts injected into a page after it loads.'],
    'off'     => ['Off', 'No Content-Security-Policy at all.'],
];
$hstsValue = 'max-age=' . (int) $s['hsts_max_age'] . (!empty($s['hsts_subdomains']) ? '; includeSubDomains' : '') . (!empty($s['hsts_preload']) ? '; preload' : '');
$htaccessNote = match ($htaccessXss ?? '') {
    'unwritable' => '<div class="bh-set-card__block"><div class="bh-note bh-note--warn">' . icon('exclamation-triangle', 'w-4 h-4') . '<span>Your <code>.htaccess</code> file still sets <code>X-XSS-Protection: 1; mode=block</code> and Basehim could not change it (the file is not writable). Edit it and change that line to <code>Header set X-XSS-Protection "0"</code>.</span></div></div>',
    default => '',
};
?>
<div class="bh-set">
    <?php $this->include('settings._head', compact('tab', 'base')); ?>

    <form method="POST" action="<?= $base ?>/admin/settings/security" class="bh-set__form">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">

        <?= bh_set_card_open('HTTPS', 'Strict-Transport-Security (HSTS) tells browsers to use only HTTPS for this site from then on, so the first visit and typed <code>http://</code> addresses can\'t be intercepted.') ?>
            <?php if (empty($secure)): ?>
            <div class="bh-set-card__block"><div class="bh-note bh-note--warn"><?= icon('exclamation-triangle', 'w-4 h-4') ?><span>This page was not opened over HTTPS, so the header is not being sent. It is only ever sent on HTTPS responses.</span></div></div>
            <?php endif; ?>
            <?= bh_set_toggle('hsts', (bool) $s['hsts'], 'Send Strict-Transport-Security', 'Only switch this off if the site must stay reachable over plain HTTP. Browsers remember it for the duration below.') ?>
            <?= bh_set_row('Remember for', '<select id="sec-hsts-age" name="hsts_max_age" class="bh-select max-w-xs">' . bh_set_options(\App\Services\SecurityHeaders::HSTS_AGES, (int) $s['hsts_max_age']) . '</select>', 'Six months is the usual minimum; one year is common.', ['for' => 'sec-hsts-age', 'sub' => true]) ?>
            <?= bh_set_toggle('hsts_subdomains', (bool) $s['hsts_subdomains'], 'Include subdomains', 'Every subdomain of <code>' . $e(preg_replace('/:\d+$/', '', (string) ($host ?? ''))) . '</code> must then work over HTTPS too. Check them (mail, cPanel, staging…) before switching this on.', ['sub' => true]) ?>
            <?= bh_set_toggle('hsts_preload', (bool) $s['hsts_preload'], 'Allow preloading', 'Lets you submit the site to <a class="text-accent-600 underline" href="https://hstspreload.org" target="_blank" rel="noopener">hstspreload.org</a>, built into browsers. Needs "Include subdomains" and at least one year; hard to undo.', ['sub' => true]) ?>
            <div class="bh-set-card__block"><p class="bh-help">Sent now: <code><?= !empty($s['hsts']) && !empty($secure) ? 'Strict-Transport-Security: ' . $e($hstsValue) : 'nothing (' . (empty($s['hsts']) ? 'switched off' : 'not HTTPS') . ')' ?></code></p></div>
        <?= bh_set_card_close() ?>

        <?= bh_set_card_open('Content-Security-Policy', 'Limits where scripts can come from, the main defence against cross-site scripting. Every script on your pages, from the theme, apps, analytics or ads, keeps working: each page gives its own scripts a one-time key (a nonce).') ?>
            <div class="bh-set-row bh-set-row--wide">
                <div class="bh-set-row__text"><span class="bh-set-row__label" id="sec-csp-label">Script policy on the public site</span>
                    <p class="bh-set-row__help">Always on, whatever you choose: no plugins (<code>object-src 'none'</code>), no <code>&lt;base&gt;</code> tricks, no framing by other sites, and HTTPS for every resource.</p></div>
                <div class="bh-set-row__control">
                    <div class="bh-choices sm:grid-cols-3" role="radiogroup" aria-labelledby="sec-csp-label">
                    <?php foreach ($modes as $key => [$label, $help]): ?>
                        <label class="bh-choice"><input type="radio" name="csp_mode" value="<?= $e($key) ?>" <?= $s['csp_mode'] === $key ? 'checked' : '' ?>><span class="min-w-0"><span class="bh-choice__title"><?= $e($label) ?></span><span class="bh-choice__text"><?= $e($help) ?></span></span></label>
                    <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <div class="bh-set-card__block">
                <p class="bh-set-row__label">Reports <?= $reports ? '(' . count($reports) . ')' : '' ?></p>
                <?php if (!$reports): ?>
                <p class="bh-help">No reports. Browsers report here what the policy blocks<?= $s['csp_mode'] === 'report' ? ', or would block,' : '' ?> on the public site. When this has stayed empty for a few days of normal traffic, choosing Enforce changes nothing visitors can see.</p>
                <?php else: ?>
                <p class="bh-help mb-2">Grouped by what was blocked and where. A one-off from a browser extension is normal; something on many pages is your site's own code and needs a look before you enforce.</p>
                <div class="overflow-x-auto border border-slate-200 rounded-lg">
                    <table class="w-full text-sm">
                        <thead><tr class="text-xs font-semibold text-slate-600 border-b border-slate-200"><th class="text-left py-2 pl-3 pr-3">Blocked</th><th class="text-left py-2 pr-3">Page</th><th class="text-left py-2 pr-3">Rule</th><th class="text-right py-2 pr-3">Times</th><th class="text-left py-2 pr-3">Last</th></tr></thead>
                        <tbody>
                        <?php foreach (array_slice($reports, 0, 50) as $r): ?>
                            <tr class="border-b border-slate-100 align-top">
                                <td class="font-mono break-all py-2 pl-3 pr-3"><?= $e($r['blocked'] !== '' ? $r['blocked'] : '(inline)') ?><?php if (($r['sample'] ?? '') !== ''): ?><br><span class="text-slate-500"><?= $e($r['sample']) ?></span><?php endif; ?><?php if (($r['source'] ?? '') !== ''): ?><br><span class="text-slate-500"><?= $e($r['source']) ?><?= !empty($r['line']) ? ':' . (int) $r['line'] : '' ?></span><?php endif; ?></td>
                                <td class="font-mono break-all py-2 pr-3"><?= $e($r['page']) ?></td>
                                <td class="font-mono py-2 pr-3"><?= $e($r['directive']) ?></td>
                                <td class="text-right py-2 pr-3"><?= (int) $r['count'] ?></td>
                                <td class="whitespace-nowrap py-2 pr-3"><?= $e(bh_time_ago($r['last'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        <?= bh_set_card_close() ?>

        <?= bh_set_card_open('Other headers', 'Sent on every page; nothing to set.') ?>
            <div class="bh-set-card__block">
                <ul class="text-sm space-y-1">
                    <li><code>X-Powered-By: Basehim</code>: replaces PHP's own header, which named the exact PHP version.</li>
                    <li><code>X-Content-Type-Options: nosniff</code>, <code>X-Frame-Options: SAMEORIGIN</code>, <code>Referrer-Policy: strict-origin-when-cross-origin</code>, <code>Permissions-Policy: geolocation=(), microphone=(), camera=()</code>.</li>
                    <li><code>X-XSS-Protection: 0</code>: turns off an obsolete browser filter that caused problems of its own.</li>
                </ul>
            </div>
            <?= $htaccessNote ?>
        <?= bh_set_card_close() ?>

        <?= bh_set_save() ?>
    </form>
    <?php if ($reports): ?>
    <form method="POST" action="<?= $base ?>/admin/settings/security/clear-reports" class="mt-3">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <button type="submit" class="bh-btn bh-btn--secondary bh-btn--sm">Clear reports</button>
    </form>
    <?php endif; ?>
</div>

<?php $this->endSection(); ?>
