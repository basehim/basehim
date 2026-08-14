<?php $this->extend('layouts.app'); ?>
<?php $this->section('content'); ?>

<div class="mb-5">
    <h2 class="text-xl font-semibold text-slate-900">Settings</h2>
    <p class="text-sm text-slate-500">Configure your site.</p>
</div>

<div>
    <?php $this->include('settings._nav', compact('tab', 'base')); ?>
    <div class="mt-0">
        <div class="bg-white rounded-xl border border-slate-200 p-6">
            <h3 class="font-semibold text-slate-900 mb-5">Appearance Settings</h3>
            <form method="POST" action="<?= $base ?>/admin/settings/appearance" class="space-y-5">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">

                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-2">Active Theme</label>
                    <div class="text-sm bg-blue-50 border border-blue-200 text-blue-700 rounded-lg px-3 py-2">
                        <?= icon('swatch', 'w-4 h-4 mr-1') ?> <?= htmlspecialchars($activeTheme) ?>
                        <a href="<?= $base ?>/admin/themes" class="ml-2 underline">Change in Themes</a>
                    </div>
                </div>

                <?php
                    $logoUrl    = $values['logo_url'] ?? '';
                    $faviconUrl = $values['favicon_url'] ?? '';
                ?>

                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Custom Logo</label>
                    <div class="flex items-start gap-3">
                        <div id="logo-preview" class="shrink-0 w-24 h-16 rounded-lg border border-slate-200 bg-slate-50 flex items-center justify-center overflow-hidden">
                            <img id="logo-thumb" src="<?= htmlspecialchars($logoUrl) ?>" alt="" class="max-w-full max-h-full object-contain <?= $logoUrl ? '' : 'hidden' ?>">
                            <span id="logo-placeholder" class="text-slate-300 <?= $logoUrl ? 'hidden' : '' ?>"><?= icon('photo', 'w-6 h-6') ?></span>
                        </div>
                        <div class="flex-1 min-w-0 space-y-2">
                            <input type="url" id="logo_url" name="logo_url" value="<?= htmlspecialchars($logoUrl) ?>" placeholder="Select from the Media Library or paste a URL"
                                class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500 outline-none text-sm">
                            <div class="flex flex-wrap gap-2">
                                <button type="button" id="logo-select" class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-lg text-xs font-medium">
                                    <?= icon('photo', 'w-4 h-4') ?> Select from {media} Library
                                </button>
                                <button type="button" id="logo-remove" class="inline-flex items-center gap-1.5 px-3 py-1.5 border border-red-200 text-red-600 hover:bg-red-50 rounded-lg text-xs font-medium <?= $logoUrl ? '' : 'hidden' ?>">
                                    <?= icon('trash', 'w-4 h-4') ?> Remove
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Favicon</label>
                    <div class="flex items-start gap-3">
                        <div id="favicon-preview" class="shrink-0 w-12 h-12 rounded-lg border border-slate-200 bg-slate-50 flex items-center justify-center overflow-hidden">
                            <img id="favicon-thumb" src="<?= htmlspecialchars($faviconUrl) ?>" alt="" class="max-w-full max-h-full object-contain <?= $faviconUrl ? '' : 'hidden' ?>">
                            <span id="favicon-placeholder" class="text-slate-300 <?= $faviconUrl ? 'hidden' : '' ?>"><?= icon('globe-alt', 'w-5 h-5') ?></span>
                        </div>
                        <div class="flex-1 min-w-0 space-y-2">
                            <input type="url" id="favicon_url" name="favicon_url" value="<?= htmlspecialchars($faviconUrl) ?>" placeholder="Select from the Media Library or paste a URL"
                                class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500 outline-none text-sm">
                            <div class="flex flex-wrap gap-2">
                                <button type="button" id="favicon-select" class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-lg text-xs font-medium">
                                    <?= icon('photo', 'w-4 h-4') ?> Select from {media} Library
                                </button>
                                <button type="button" id="favicon-remove" class="inline-flex items-center gap-1.5 px-3 py-1.5 border border-red-200 text-red-600 hover:bg-red-50 rounded-lg text-xs font-medium <?= $faviconUrl ? '' : 'hidden' ?>">
                                    <?= icon('trash', 'w-4 h-4') ?> Remove
                                </button>
                            </div>
                            <p class="text-xs text-slate-400">A square PNG or ICO (e.g. 512×512) works best.</p>
                        </div>
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Footer Text</label>
                    <input type="text" name="footer_text" value="<?= htmlspecialchars($values['footer_text'] ?? '') ?>"
                        class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500 outline-none">
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Custom CSS</label>
                    <textarea name="custom_css" rows="6"
                        class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500 outline-none font-mono text-xs"><?= htmlspecialchars($values['custom_css'] ?? '') ?></textarea>
                </div>

                <button type="submit" class="px-5 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg shadow-sm">
                    <?= icon('document-check', 'w-4 h-4 mr-1') ?> Save Changes
                </button>
            </form>
        </div>
    </div>
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
                thumb.src = ''; thumb.classList.add('hidden');
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
