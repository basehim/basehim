<?php $this->extend('layouts.app'); ?>
<?php $this->section('content'); ?>
<?php
    // $media holds resolved current values (with defaults) from MediaService.
    $m = $media ?? [];
    $chk = fn($v) => !empty($v) ? 'checked' : '';
?>

<div class="mb-5">
    <h2 class="text-xl font-semibold text-slate-900">Settings</h2>
    <p class="text-sm text-slate-500">Configure your site.</p>
</div>

<div>
    <?php $this->include('settings._nav', compact('tab', 'base')); ?>
    <div class="mt-0 space-y-5">

        <?php if (empty($gdAvailable)): ?>
        <div class="bg-amber-50 border border-amber-200 text-amber-800 rounded-lg px-4 py-3 text-sm flex items-start gap-2">
            <?= icon('exclamation-triangle', 'w-5 h-5 shrink-0') ?>
            <div>The <strong>GD image extension</strong> isn't available on this server, so thumbnails can't be generated. Other media settings still apply. Ask your host to enable <code>php-gd</code>.</div>
        </div>
        <?php endif; ?>

        <form method="POST" action="<?= $base ?>/admin/settings/media" class="space-y-5">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">

            <!-- Uploading -->
            <div class="bg-white rounded-xl border border-slate-200 p-6">
                <h3 class="font-semibold text-slate-900 mb-1">Uploading files</h3>
                <p class="text-sm text-slate-500 mb-5">Control what can be uploaded and where it's stored.</p>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1.5">Maximum upload size (MB)</label>
                        <input type="number" min="1" name="max_upload_mb" value="<?= (int)($m['max_upload_mb'] ?? 64) ?>"
                            class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500 outline-none">
                        <p class="text-xs text-slate-400 mt-1">Also limited by your server's <code>upload_max_filesize</code>.</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1.5">Image quality (1–100)</label>
                        <input type="number" min="1" max="100" name="jpeg_quality" value="<?= (int)($m['jpeg_quality'] ?? 82) ?>"
                            class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500 outline-none">
                        <p class="text-xs text-slate-400 mt-1">Compression for generated JPEG/WebP thumbnails.</p>
                    </div>
                </div>

                <div class="mt-5">
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Allowed file types</label>
                    <input type="text" name="allowed_types" value="<?= htmlspecialchars((string)($m['allowed_types'] ?? 'jpg, jpeg, png, gif, webp, svg, pdf, mp3, mp4, webm, doc, docx, xls, xlsx, zip')) ?>"
                        class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500 outline-none">
                    <p class="text-xs text-slate-400 mt-1">Comma-separated extensions (without dots).</p>
                </div>

                <div class="mt-5">
                    <label class="inline-flex items-center gap-2 text-sm text-slate-700">
                        <input type="hidden" name="organize_uploads" value="0">
                        <input type="checkbox" name="organize_uploads" value="1" <?= $chk($m['organize_uploads'] ?? true) ?> class="rounded border-slate-300 text-blue-600 focus:ring-blue-500">
                        Organize uploads into month- and year-based folders
                    </label>
                </div>
            </div>

            <!-- Image sizes -->
            <div class="bg-white rounded-xl border border-slate-200 p-6">
                <h3 class="font-semibold text-slate-900 mb-1">Image sizes</h3>
                <p class="text-sm text-slate-500 mb-5">The dimensions (in pixels) of thumbnails generated for each uploaded image.</p>

                <label class="inline-flex items-center gap-2 text-sm text-slate-700 mb-5">
                    <input type="hidden" name="generate_thumbnails" value="0">
                    <input type="checkbox" name="generate_thumbnails" value="1" <?= $chk($m['generate_thumbnails'] ?? true) ?> class="rounded border-slate-300 text-blue-600 focus:ring-blue-500">
                    Generate thumbnail sizes on upload
                </label>

                <div class="space-y-5">
                    <!-- Thumbnail -->
                    <div class="grid grid-cols-1 sm:grid-cols-[120px_1fr_1fr_auto] gap-3 items-end">
                        <div class="text-sm font-medium text-slate-700 sm:pb-2">Thumbnail</div>
                        <div>
                            <label class="block text-xs text-slate-500 mb-1">Width</label>
                            <input type="number" min="1" name="thumb_w" value="<?= (int)($m['thumb_w'] ?? 150) ?>" class="w-full px-3 py-2 border border-slate-300 rounded-lg outline-none focus:ring-2 focus:ring-blue-200 focus:border-blue-500">
                        </div>
                        <div>
                            <label class="block text-xs text-slate-500 mb-1">Height</label>
                            <input type="number" min="1" name="thumb_h" value="<?= (int)($m['thumb_h'] ?? 150) ?>" class="w-full px-3 py-2 border border-slate-300 rounded-lg outline-none focus:ring-2 focus:ring-blue-200 focus:border-blue-500">
                        </div>
                        <label class="inline-flex items-center gap-2 text-xs text-slate-600 sm:pb-2 whitespace-nowrap">
                            <input type="hidden" name="thumb_crop" value="0">
                            <input type="checkbox" name="thumb_crop" value="1" <?= $chk($m['thumb_crop'] ?? true) ?> class="rounded border-slate-300 text-blue-600 focus:ring-blue-500">
                            Crop to exact size
                        </label>
                    </div>
                    <!-- Medium -->
                    <div class="grid grid-cols-1 sm:grid-cols-[120px_1fr_1fr_auto] gap-3 items-end">
                        <div class="text-sm font-medium text-slate-700 sm:pb-2">Medium</div>
                        <div>
                            <label class="block text-xs text-slate-500 mb-1">Max width</label>
                            <input type="number" min="1" name="medium_w" value="<?= (int)($m['medium_w'] ?? 300) ?>" class="w-full px-3 py-2 border border-slate-300 rounded-lg outline-none focus:ring-2 focus:ring-blue-200 focus:border-blue-500">
                        </div>
                        <div>
                            <label class="block text-xs text-slate-500 mb-1">Max height</label>
                            <input type="number" min="1" name="medium_h" value="<?= (int)($m['medium_h'] ?? 300) ?>" class="w-full px-3 py-2 border border-slate-300 rounded-lg outline-none focus:ring-2 focus:ring-blue-200 focus:border-blue-500">
                        </div>
                        <div class="text-xs text-slate-400 sm:pb-2">fit inside</div>
                    </div>
                    <!-- Large -->
                    <div class="grid grid-cols-1 sm:grid-cols-[120px_1fr_1fr_auto] gap-3 items-end">
                        <div class="text-sm font-medium text-slate-700 sm:pb-2">Large</div>
                        <div>
                            <label class="block text-xs text-slate-500 mb-1">Max width</label>
                            <input type="number" min="1" name="large_w" value="<?= (int)($m['large_w'] ?? 1024) ?>" class="w-full px-3 py-2 border border-slate-300 rounded-lg outline-none focus:ring-2 focus:ring-blue-200 focus:border-blue-500">
                        </div>
                        <div>
                            <label class="block text-xs text-slate-500 mb-1">Max height</label>
                            <input type="number" min="1" name="large_h" value="<?= (int)($m['large_h'] ?? 1024) ?>" class="w-full px-3 py-2 border border-slate-300 rounded-lg outline-none focus:ring-2 focus:ring-blue-200 focus:border-blue-500">
                        </div>
                        <div class="text-xs text-slate-400 sm:pb-2">fit inside</div>
                    </div>
                </div>

                <div class="mt-6">
                    <label class="inline-flex items-center gap-2 text-sm text-slate-700">
                        <input type="hidden" name="convert_webp" value="0">
                        <input type="checkbox" name="convert_webp" value="1" <?= $chk($m['convert_webp'] ?? false) ?> <?= empty($gdWebp) ? 'disabled' : '' ?> class="rounded border-slate-300 text-blue-600 focus:ring-blue-500 disabled:opacity-50">
                        Save generated thumbnails as WebP<?= empty($gdWebp) ? ' (not supported on this server)' : '' ?>
                    </label>
                </div>
            </div>

            <button type="submit" class="px-5 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg shadow-sm">
                <?= icon('document-check', 'w-4 h-4 mr-1') ?> Save Changes
            </button>
        </form>

        <!-- Regenerate thumbnails -->
        <div class="bg-white rounded-xl border border-slate-200 p-6">
            <h3 class="font-semibold text-slate-900 mb-1">Regenerate thumbnails</h3>
            <p class="text-sm text-slate-500 mb-4">Rebuild every image's thumbnails using the sizes above — useful after changing them. Currently <strong><?= (int)($mediaCount ?? 0) ?></strong> item(s) in the Media Library.</p>
            <form method="POST" action="<?= $base ?>/admin/settings/media/regenerate" onsubmit="return confirm('Regenerate thumbnails for all images? This may take a while on large libraries.')">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
                <button type="submit" <?= empty($gdAvailable) ? 'disabled' : '' ?>
                    class="inline-flex items-center gap-2 px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-lg text-sm font-medium disabled:opacity-50 disabled:cursor-not-allowed">
                    <?= icon('arrow-path', 'w-4 h-4') ?> Regenerate all thumbnails
                </button>
            </form>
        </div>

    </div>
</div>

<?php $this->endSection(); ?>
