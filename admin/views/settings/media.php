<?php $this->extend('layouts.app'); ?>
<?php $this->section('content'); ?>
<?php
require_once __DIR__ . '/_ui.php';
// $media holds resolved current values (with defaults) from MediaService.
$m = $media ?? [];
$num = static fn(string $name, int|string $value, array $a = []): string =>
    '<input type="number" id="' . bh_set_id($name) . '" name="' . bh_set_e($name) . '" class="bh-input" value="' . bh_set_e($value) . '"'
    . (isset($a['min']) ? ' min="' . (int) $a['min'] . '"' : '') . (isset($a['max']) ? ' max="' . (int) $a['max'] . '"' : '')
    . (isset($a['step']) ? ' step="' . bh_set_e($a['step']) . '"' : '') . '>';
$size = static function (string $w, string $h, int $wv, int $hv, string $wl, string $hl) use ($num): string {
    return '<div class="bh-set-fields">'
        . '<div><label class="bh-set-fields__label" for="' . bh_set_id($w) . '">' . $wl . '</label>' . $num($w, $wv, ['min' => 1]) . '</div>'
        . '<div><label class="bh-set-fields__label" for="' . bh_set_id($h) . '">' . $hl . '</label>' . $num($h, $hv, ['min' => 1]) . '</div></div>';
};
$ic = $imageCounts ?? ['images' => 0, 'missing' => 0];
?>
<div class="bh-set">
    <?php $this->include('settings._head', compact('tab', 'base')); ?>

    <div class="bh-set__stack">
        <?php if (empty($gdAvailable)): ?>
        <div class="bh-note bh-note--warn"><?= icon('exclamation-triangle', 'w-4 h-4') ?><span>The <strong>GD image extension</strong> isn't available on this server, so thumbnails can't be generated. Other media settings still apply. Ask your host to enable <code>php-gd</code>.</span></div>
        <?php endif; ?>

        <form method="POST" action="<?= $base ?>/admin/settings/media" class="bh-set__form">
            <input type="hidden" name="_csrf" value="<?= bh_set_e($csrf) ?>">

            <?= bh_set_card_open('Uploading files', 'What can be uploaded and where it is stored.') ?>
                <?= bh_set_row('Largest upload', '<div class="flex items-center gap-2">' . str_replace('class="bh-input"', 'class="bh-input max-w-[8rem]"', $num('max_upload_mb', (int) ($m['max_upload_mb'] ?? 64), ['min' => 1])) . '<span class="text-sm text-slate-500">MB</span></div>', 'Also limited by your server\'s <code>upload_max_filesize</code>.', ['for' => bh_set_id('max_upload_mb')]) ?>
                <?= bh_set_row('Largest image', '<div class="flex items-center gap-2">' . str_replace('class="bh-input"', 'class="bh-input max-w-[8rem]"', $num('max_image_megapixels', (string) (float) ($m['max_image_megapixels'] ?? 50), ['min' => 1, 'max' => 100, 'step' => '1'])) . '<span class="text-sm text-slate-500">megapixels</span></div>', 'Width × height. Larger images are refused: a small file can still need a lot of memory to process. 50 suits most phone and camera photos.', ['for' => bh_set_id('max_image_megapixels')]) ?>
                <?= bh_set_row('Allowed file types', '<input type="text" id="set-allowed-types" name="allowed_types" class="bh-input" value="' . bh_set_e($m['allowed_types'] ?? 'jpg, jpeg, png, gif, webp, svg, pdf, mp3, mp4, webm, doc, docx, xls, xlsx, zip') . '">', 'Extensions without dots, separated by commas.', ['for' => 'set-allowed-types', 'wide' => true]) ?>
                <?= bh_set_toggle('organize_uploads', !empty($m['organize_uploads'] ?? true), 'Organise uploads into year and month folders') ?>
            <?= bh_set_card_close() ?>

            <?= bh_set_card_open('Image sizes', 'The thumbnails made for each uploaded image, in pixels.') ?>
                <?= bh_set_toggle('generate_thumbnails', !empty($m['generate_thumbnails'] ?? true), 'Make thumbnails on upload') ?>
                <?= bh_set_row('Thumbnail', $size('thumb_w', 'thumb_h', (int) ($m['thumb_w'] ?? 150), (int) ($m['thumb_h'] ?? 150), 'Width', 'Height')) ?>
                <?= bh_set_toggle('thumb_crop', !empty($m['thumb_crop'] ?? true), 'Crop thumbnails to exact size', 'Off: the image is fitted inside the size instead.', ['sub' => true]) ?>
                <?= bh_set_row('Medium', $size('medium_w', 'medium_h', (int) ($m['medium_w'] ?? 300), (int) ($m['medium_h'] ?? 300), 'Max width', 'Max height'), 'Fitted inside this size.') ?>
                <?= bh_set_row('Large', $size('large_w', 'large_h', (int) ($m['large_w'] ?? 1024), (int) ($m['large_h'] ?? 1024), 'Max width', 'Max height'), 'Fitted inside this size.') ?>
                <?= bh_set_row('Quality', '<div class="flex items-center gap-2">' . str_replace('class="bh-input"', 'class="bh-input max-w-[8rem]"', $num('jpeg_quality', (int) ($m['jpeg_quality'] ?? 82), ['min' => 1, 'max' => 100])) . '<span class="text-sm text-slate-500">of 100</span></div>', 'Compression for JPEG and WebP thumbnails.', ['for' => bh_set_id('jpeg_quality')]) ?>
                <?= bh_set_toggle('convert_webp', !empty($m['convert_webp'] ?? false), 'Save thumbnails as WebP', empty($gdWebp) ? 'Not supported on this server.' : 'Smaller files than JPEG, supported by every current browser.', ['disabled' => empty($gdWebp)]) ?>
            <?= bh_set_card_close() ?>

            <?= bh_set_save() ?>
        </form>

        <?= bh_set_card_open('Regenerate thumbnails', 'Builds the thumbnail, medium and large versions themes use for cards, lists and widgets.', ['id' => 'bh-regen']) ?>
            <div class="bh-set-card__block grid gap-4">
                <p class="m-0 text-sm text-slate-600"><strong class="font-semibold text-slate-900"><?= number_format((int) $ic['images']) ?></strong> image<?= (int) $ic['images'] === 1 ? '' : 's' ?> in the library; <strong class="font-semibold text-slate-900"><?= number_format((int) $ic['missing']) ?></strong> without thumbnails.</p>
                <div class="bh-choices sm:grid-cols-2" role="radiogroup" aria-label="Which images">
                    <label class="bh-choice"><input type="radio" name="regen_scope" value="missing" checked><span><span class="bh-choice__title">Only images without thumbnails</span></span></label>
                    <label class="bh-choice"><input type="radio" name="regen_scope" value="all"><span><span class="bh-choice__title">All images</span><span class="bh-choice__text">After changing the sizes above.</span></span></label>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <button type="button" data-regen-start class="bh-btn bh-btn--secondary" <?= empty($gdAvailable) ? 'disabled' : '' ?>><?= icon('arrow-path', 'w-4 h-4') ?> Regenerate</button>
                    <button type="button" data-regen-stop hidden class="bh-btn bh-btn--ghost">Stop</button>
                    <?php if (empty($gdAvailable)): ?><span class="text-sm text-slate-500">The GD image extension is not available on this server.</span><?php endif; ?>
                </div>
                <div data-regen-progress hidden>
                    <div class="h-2 overflow-hidden rounded-full bg-slate-100"><div data-regen-bar class="h-full bg-accent-600 transition-all" style="width:0%"></div></div>
                    <p data-regen-status class="mt-2 text-sm text-slate-600" aria-live="polite"></p>
                </div>
                <noscript>
                    <form method="POST" action="<?= $base ?>/admin/settings/media/regenerate" onsubmit="return confirm('Regenerate thumbnails for all images in one request? On a large library this can time out.')">
                        <input type="hidden" name="_csrf" value="<?= bh_set_e($csrf) ?>">
                        <button type="submit" class="bh-btn bh-btn--secondary">Regenerate all (one request)</button>
                    </form>
                </noscript>
            </div>
        <?= bh_set_card_close() ?>
        <script>
        (function () {
            // Regenerate in batches: each request works for a few seconds and
            // says where to continue, so a large library never times out.
            var box = document.getElementById('bh-regen'); if (!box) return;
            var start = box.querySelector('[data-regen-start]'), stop = box.querySelector('[data-regen-stop]');
            var wrap = box.querySelector('[data-regen-progress]'), bar = box.querySelector('[data-regen-bar]'), status = box.querySelector('[data-regen-status]');
            var url = <?= json_encode($base . '/admin/settings/media/regenerate-batch') ?>, csrf = <?= json_encode($csrf) ?>;
            var running = false, stopped = false;
            start.addEventListener('click', async function () {
                if (running) return;
                var scope = (box.querySelector('input[name=regen_scope]:checked') || {}).value || 'missing';
                if (scope === 'all' && !(await bhConfirm('Rebuild the thumbnails of every image? Existing thumbnails are replaced.', { confirmLabel: 'Rebuild all' }))) return;
                running = true; stopped = false; start.disabled = true; stop.hidden = false; wrap.hidden = false;
                var tot = { processed: 0, skipped: 0, failed: 0, variants: 0 }, total = null, after = 0;
                var finish = function (msg) { running = false; start.disabled = false; stop.hidden = true; status.textContent = msg; };
                var step = function () {
                    if (stopped) return finish('Stopped after ' + (tot.processed + tot.skipped + tot.failed) + ' images. Start again to continue where it left off.');
                    // media.regenerate_batch through the AJAX gateway (1.2.47).
                    Basehim.ajax.request('media.regenerate_batch', { after: +after || 0, scope: scope === 'all' ? 'all' : 'missing' }, { timeout: 90000 })
                        .then(function (j) {
                            ['processed', 'skipped', 'failed', 'variants'].forEach(function (k) { tot[k] += j[k] || 0; });
                            after = j.next;
                            var done = tot.processed + tot.skipped + tot.failed;
                            if (total === null) total = done + j.remaining;
                            bar.style.width = (total ? Math.round(done / total * 100) : 100) + '%';
                            var line = done + ' of ' + total + ' images · ' + tot.variants + ' thumbnails made'
                                + (tot.failed ? ' · ' + tot.failed + ' failed' : '')
                                + (tot.skipped ? ' · ' + tot.skipped + ' skipped (file missing or not resizable)' : '');
                            if (j.done) finish('Done. ' + line); else { status.textContent = line; step(); }
                        })
                        .catch(function (e) { finish('Stopped: ' + e.message + '. Start again to continue.'); });
                };
                step();
            });
            stop.addEventListener('click', function () { stopped = true; stop.hidden = true; });
        })();
        </script>
    </div>
</div>

<?php $this->endSection(); ?>
