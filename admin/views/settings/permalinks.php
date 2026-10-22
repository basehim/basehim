<?php $this->extend('layouts.app'); ?>
<?php $this->section('content'); ?>
<?php
require_once __DIR__ . '/_ui.php';
$struct = $values['structure'] ?? 'pretty';
$host  = $values['canonical_host'] ?? 'none';
$https = !empty($values['force_https']);
$ht    = $htaccess ?? ['exists' => false, 'writable' => false, 'managed' => false];
// Show the choices against the address actually in use, so "with www" is
// concrete rather than an abstraction.
$bare  = preg_replace('/^www\./i', '', (string) ($currentHost ?? 'example.com'));
$choice = static function (string $name, string $value, bool $checked, string $title, string $text): string {
    return '<label class="bh-choice"><input type="radio" name="' . bh_set_e($name) . '" value="' . bh_set_e($value) . '"' . ($checked ? ' checked' : '') . '>'
        . '<span class="min-w-0"><span class="bh-choice__title">' . $title . '</span><span class="bh-choice__text">' . $text . '</span></span></label>';
};
?>
<div class="bh-set">
    <?php $this->include('settings._head', compact('tab', 'base')); ?>

    <form method="POST" action="<?= $base ?>/admin/settings/permalinks" class="bh-set__form">
        <input type="hidden" name="_csrf" value="<?= bh_set_e($csrf) ?>">

        <?= bh_set_card_open('Post addresses', 'How the address of each post is built.') ?>
            <div class="bh-set-card__block">
                <div class="bh-choices" role="radiogroup" aria-label="Post addresses">
                    <?= $choice('structure', 'pretty', $struct === 'pretty', 'Default', 'Posts use <code>/posts/sample-post</code>, pages use <code>/sample-page</code>.') ?>
                    <?= $choice('structure', 'category', $struct === 'category', 'Category and post name', 'Posts use <code>/electronics/sample-post</code> (primary category, then the post). Pages use <code>/sample-page</code>.') ?>
                    <?= $choice('structure', 'flat', $struct === 'flat', 'Post name only', 'Posts and pages both live at <code>/sample-name</code>, without the <code>/posts/</code> prefix.') ?>
                </div>
                <div class="bh-note bh-note--warn mt-3"><?= icon('exclamation-triangle', 'w-4 h-4') ?><span>Changing this changes every post's address. Links from search engines and other sites to the old addresses may stop working.</span></div>
            </div>
        <?= bh_set_card_close() ?>

        <?= bh_set_card_open('Preferred address', 'One address for the whole site.', [
            'intro' => 'A site reachable at more than one address splits its search ranking between them and can sign visitors out when they cross from one to the other. Choosing one here writes a permanent redirect into <code>.htaccess</code>, so every visit lands on the same address.',
        ]) ?>
            <div class="bh-set-card__block">
                <div class="bh-choices" role="radiogroup" aria-label="Preferred address">
                    <?= $choice('canonical_host', 'none', $host === 'none', 'Leave it alone', 'No redirect. Choose this if your host or a service like Cloudflare already handles it: two sets of redirect rules can loop.') ?>
                    <?= $choice('canonical_host', 'www', $host === 'www', 'With <code>www</code>', bh_set_e($bare) . ' &rarr; <strong class="font-medium text-slate-700">www.' . bh_set_e($bare) . '</strong>') ?>
                    <?= $choice('canonical_host', 'root', $host === 'root', 'Without <code>www</code>', 'www.' . bh_set_e($bare) . ' &rarr; <strong class="font-medium text-slate-700">' . bh_set_e($bare) . '</strong>') ?>
                </div>
            </div>
            <?= bh_set_toggle('force_https', $https, 'Always use HTTPS', 'Redirects <code>http://</code> to <code>https://</code>. Switch this on only once a certificate is installed and working, or the site becomes unreachable. The rule understands proxies, so it will not loop behind Cloudflare.', ['hidden0' => false]) ?>
            <div data-settings-live="htaccess">
            <?php if (!$ht['exists'] || !$ht['writable'] || !empty($htaccessBlock)): ?>
                <div class="bh-set-card__block grid gap-3">
                <?php if (!$ht['exists']): ?>
                    <div class="bh-note"><?= icon('information-circle', 'w-4 h-4') ?><span>There is no <code>.htaccess</code> at the site root. That is normal on nginx, where these rules belong in the server configuration instead. Your choice is still saved, and the rules to copy are shown below once you pick one.</span></div>
                <?php elseif (!$ht['writable']): ?>
                    <div class="bh-note bh-note--warn"><?= icon('exclamation-triangle', 'w-4 h-4') ?><span><code>.htaccess</code> is not writable, so the rules cannot be saved into it. Either grant write permission, or paste the block below in by hand.</span></div>
                <?php endif; ?>
                <?php if (!empty($htaccessBlock)): ?>
                    <details class="rounded-xl border border-slate-200">
                        <summary class="cursor-pointer select-none px-3.5 py-2.5 text-sm font-medium text-slate-700">Show the rules currently in use</summary>
                        <pre class="overflow-x-auto px-3.5 pb-3.5 text-[11px] leading-relaxed text-slate-600"><?= bh_set_e($htaccessBlock) ?></pre>
                    </details>
                <?php endif; ?>
                </div>
            <?php endif; ?>
            </div>
        <?= bh_set_card_close() ?>

        <?= bh_set_save() ?>
    </form>
</div>

<?php $this->endSection(); ?>
