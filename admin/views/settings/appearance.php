<?php $this->extend('layouts.app'); ?>
<?php $this->section('content'); ?>
<?php
require_once __DIR__ . '/_ui.php';
$logoUrl    = $values['logo_url'] ?? '';
$faviconUrl = $values['favicon_url'] ?? '';
$picker = static function (string $name, string $url, string $box, string $emptyIcon, string $help = ''): string {
    return '<div class="flex items-start gap-3">'
        . '<div id="' . $name . '-preview" class="grid ' . $box . ' flex-none place-items-center overflow-hidden rounded-xl border border-slate-200 bg-slate-50">'
        . '<img id="' . $name . '-thumb"' . ($url !== '' ? ' src="' . bh_set_e($url) . '"' : '') . ' alt="" class="max-h-full max-w-full object-contain' . ($url ? '' : ' hidden') . '">'
        . '<span id="' . $name . '-placeholder" class="text-slate-300' . ($url ? ' hidden' : '') . '">' . icon($emptyIcon, 'w-6 h-6') . '</span></div>'
        . '<div class="grid min-w-0 flex-1 gap-2">'
        . '<input type="url" id="' . $name . '_url" name="' . $name . '_url" class="bh-input" value="' . bh_set_e($url) . '" placeholder="Choose from the Media Library or paste a URL">'
        . '<div class="flex flex-wrap gap-2">'
        . '<button type="button" id="' . $name . '-select" class="bh-btn bh-btn--secondary bh-btn--sm">' . icon('photo', 'w-4 h-4') . 'Choose from Media</button>'
        . '<button type="button" id="' . $name . '-remove" class="bh-btn bh-btn--ghost bh-btn--sm text-red-600 hover:!bg-red-50 hover:!text-red-700' . ($url ? '' : ' hidden') . '">' . icon('trash', 'w-4 h-4') . 'Remove</button>'
        . '</div>' . ($help !== '' ? '<p class="bh-help m-0">' . $help . '</p>' : '') . '</div></div>';
};
?>
<div class="bh-set">
    <?php $this->include('settings._head', compact('tab', 'base')); ?>

    <form method="POST" action="<?= $base ?>/admin/settings/appearance" class="bh-set__form">
        <input type="hidden" name="_csrf" value="<?= bh_set_e($csrf) ?>">

        <?= bh_set_card_open('Theme') ?>
            <?= bh_set_row('Active theme', '<div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200 bg-slate-50 px-3.5 py-2.5"><span class="inline-flex items-center gap-2 text-sm font-medium text-slate-900">' . icon('swatch', 'w-4 h-4 text-accent-600') . bh_set_e($activeTheme) . '</span><a href="' . $base . '/admin/themes" class="bh-btn bh-btn--secondary bh-btn--sm">Change theme</a></div>') ?>
        <?= bh_set_card_close() ?>

        <?= bh_set_card_open('Site identity', 'Shown by themes in the header and by browsers in tabs.') ?>
            <?= bh_set_row('Logo', $picker('logo', $logoUrl, 'h-16 w-24', 'photo'), '', ['for' => 'logo_url']) ?>
            <?= bh_set_row('Favicon', $picker('favicon', $faviconUrl, 'h-12 w-12', 'globe-alt'), 'A square PNG or ICO (for example 512×512) works best.', ['for' => 'favicon_url']) ?>
        <?= bh_set_card_close() ?>

        <?= bh_set_card_open('Footer and styles') ?>
            <?= bh_set_row('Footer text', '<input type="text" id="set-footer-text" name="footer_text" class="bh-input" value="' . bh_set_e($values['footer_text'] ?? '') . '">', '', ['for' => 'set-footer-text']) ?>
            <?= bh_set_row('Custom CSS', '<textarea id="set-custom-css" name="custom_css" rows="8" class="bh-textarea font-mono text-xs">' . bh_set_e($values['custom_css'] ?? '') . '</textarea>', 'Added after the theme\'s own styles on every page.', ['for' => 'set-custom-css', 'wide' => true]) ?>
        <?= bh_set_card_close() ?>

        <?= bh_set_save() ?>
    </form>
</div>

<script>
(function () {
    // Wire a Media Library picker to a URL input + preview. Reuses the global
    // BasehimMedia picker (same one the post editor uses for featured images).
    function wireMediaField(name) {
        var input   = document.getElementById(name + '_url');
        var thumb   = document.getElementById(name + '-thumb');
        var holder  = document.getElementById(name + '-placeholder');
        var btnPick = document.getElementById(name + '-select');
        var btnDrop = document.getElementById(name + '-remove');
        if (!input || !btnPick) return;

        function setUrl(u) {
            input.value = u || '';
            if (u) {
                thumb.src = u; thumb.classList.remove('hidden');
                if (holder) holder.classList.add('hidden');
                btnDrop.classList.remove('hidden');
            } else {
                thumb.removeAttribute('src'); thumb.classList.add('hidden');
                if (holder) holder.classList.remove('hidden');
                btnDrop.classList.add('hidden');
            }
        }

        btnPick.addEventListener('click', function () {
            if (!window.BasehimMedia || !window.BasehimMedia.openPicker) {
                alert('Media picker failed to load. Please hard-refresh (Ctrl+Shift+R) and try again.');
                return;
            }
            window.BasehimMedia.openPicker({
                types: 'image/*',
                title: name === 'favicon' ? 'Choose a favicon' : 'Choose a logo',
                onSelect: function (media) {
                    if (media && media.url) setUrl(media.url);
                }
            });
        });

        if (btnDrop) btnDrop.addEventListener('click', function () { setUrl(''); });

        // Keep the preview in sync if someone pastes/edits a URL by hand.
        input.addEventListener('input', function () { setUrl(input.value.trim()); });
    }

    wireMediaField('logo');
    wireMediaField('favicon');
})();
</script>

<?php $this->endSection(); ?>
