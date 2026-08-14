<?php $this->extend('layouts.app'); ?>
<?php $this->section('content'); ?>

<?php
$isEdit = $post !== null;
$typeLabel = ucfirst($type);
$action = $isEdit ? "{$base}/admin/{$type}s/{$post['id']}" : "{$base}/admin/{$type}s";
$titleVal = $isEdit ? $post['title'] : '';
$contentVal = $isEdit ? $post['content'] : '';
$slugVal = $isEdit ? $post['slug'] : '';
$excerptVal = $isEdit ? ($post['excerpt'] ?? '') : '';
$statusVal = $isEdit ? $post['status'] : 'draft';
$commentVal = $isEdit ? $post['comment_status'] : 'open';
?>

<form method="POST" action="<?= $action ?>" class="space-y-5">
    <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">

    <div class="flex items-center justify-between flex-wrap gap-3">
        <div>
            <a href="<?= $base ?>/admin/<?= $type ?>s" class="text-sm text-slate-500 hover:text-blue-600">
                <?= icon('arrow-left', 'w-4 h-4 mr-1') ?> Back to <?= $typeLabel ?>s
            </a>
            <h2 class="text-xl font-semibold text-slate-900 mt-1"><?= $isEdit ? 'Edit' : 'New' ?> <?= $typeLabel ?></h2>
        </div>
        <div class="flex items-center gap-2">
            <?php if ($isEdit && $post['status'] === 'published'): ?>
                <a href="<?= $base ?>/<?= $type === 'post' ? 'posts/' : 'page/' ?><?= htmlspecialchars($post['slug']) ?>" target="_blank"
                    class="px-4 py-2 border border-slate-300 hover:bg-slate-50 rounded-lg text-sm font-medium text-slate-700">
                    <?= icon('eye', 'w-4 h-4 mr-1') ?> View
                </a>
            <?php endif; ?>
            <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium shadow-sm">
                <?= icon('document-check', 'w-4 h-4 mr-1') ?> Save
            </button>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-5">
        <!-- Main column -->
        <div class="lg:col-span-2 space-y-5">
            <div class="bg-white rounded-xl border border-slate-200 p-5">
                <label class="block text-xs font-medium text-slate-500 uppercase tracking-wide mb-2">Title</label>
                <input type="text" name="title" value="<?= htmlspecialchars($titleVal) ?>" required
                    class="w-full px-3 py-2.5 text-xl font-semibold border-0 border-b border-slate-200 focus:border-blue-500 focus:ring-0 outline-none"
                    placeholder="<?= $typeLabel ?> title">
                <div class="mt-3 flex items-center gap-2 text-xs text-slate-500">
                    <span>Slug:</span>
                    <input type="text" name="slug" value="<?= htmlspecialchars($slugVal) ?>"
                        class="flex-1 px-2 py-1 text-xs border border-slate-200 rounded focus:border-blue-500 focus:ring-1 focus:ring-blue-200 outline-none"
                        placeholder="auto-generated">
                </div>
            </div>

            <div class="bg-white rounded-xl border border-slate-200 p-5">
                <div class="flex items-center justify-between mb-2">
                    <label class="block text-sm font-medium text-slate-700">Content</label>
                    <select name="content_format" class="text-xs px-2 py-1 border border-slate-200 rounded">
                        <option value="html" <?= ($isEdit && $post['content_format']==='html') ? 'selected' : '' ?>>HTML</option>
                        <option value="markdown" <?= ($isEdit && $post['content_format']==='markdown') ? 'selected' : '' ?>>Markdown</option>
                        <option value="blocks" <?= ($isEdit && $post['content_format']==='blocks') ? 'selected' : '' ?>>Blocks (JSON)</option>
                    </select>
                </div>
                <textarea name="content" rows="18"
                    class="w-full px-3 py-2 border border-slate-200 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500 outline-none font-mono text-sm"
                    placeholder="Write your content here..."><?= htmlspecialchars($contentVal) ?></textarea>
            </div>

            <div class="bg-white rounded-xl border border-slate-200 p-5">
                <label class="block text-sm font-medium text-slate-700 mb-2">Excerpt</label>
                <textarea name="excerpt" rows="3"
                    class="w-full px-3 py-2 border border-slate-200 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500 outline-none text-sm"
                    placeholder="Short summary (auto-generated if blank)"><?= htmlspecialchars($excerptVal) ?></textarea>
            </div>

            <!-- SEO -->
            <div class="bg-white rounded-xl border border-slate-200 p-5">
                <h3 class="text-sm font-semibold text-slate-900 mb-3"><?= icon('document-magnifying-glass', 'w-4 h-4 text-blue-500 mr-2') ?>SEO</h3>
                <div class="space-y-3 text-sm">
                    <div>
                        <label class="block text-xs text-slate-500 mb-1">Meta Title</label>
                        <input type="text" name="seo_meta_title" value="<?= htmlspecialchars($seoData['meta_title'] ?? '') ?>" maxlength="200"
                            class="w-full px-3 py-2 border border-slate-200 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500 outline-none">
                    </div>
                    <div>
                        <label class="block text-xs text-slate-500 mb-1">Meta Description</label>
                        <textarea name="seo_meta_description" rows="2" maxlength="300"
                            class="w-full px-3 py-2 border border-slate-200 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500 outline-none"><?= htmlspecialchars($seoData['meta_description'] ?? '') ?></textarea>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs text-slate-500 mb-1">Focus Keyword</label>
                            <input type="text" name="seo_focus" value="<?= htmlspecialchars($seoData['focus_keyword'] ?? '') ?>"
                                class="w-full px-3 py-2 border border-slate-200 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500 outline-none">
                        </div>
                        <div>
                            <label class="block text-xs text-slate-500 mb-1">Robots</label>
                            <select name="seo_robots" class="w-full px-3 py-2 border border-slate-200 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500 outline-none">
                                <?php foreach (['index,follow', 'noindex,follow', 'index,nofollow', 'noindex,nofollow'] as $r): ?>
                                    <option value="<?= $r ?>" <?= ($seoData['robots'] ?? 'index,follow') === $r ? 'selected' : '' ?>><?= $r ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs text-slate-500 mb-1">Canonical URL</label>
                        <input type="url" name="seo_canonical" value="<?= htmlspecialchars($seoData['canonical_url'] ?? '') ?>"
                            class="w-full px-3 py-2 border border-slate-200 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500 outline-none">
                    </div>
                </div>
            </div>
        </div>

        <!-- Sidebar -->
        <div class="space-y-5">
            <div class="bg-white rounded-xl border border-slate-200 p-5">
                <h3 class="text-sm font-semibold text-slate-900 mb-3">Publish</h3>
                <div class="space-y-3 text-sm">
                    <div>
                        <label class="block text-xs text-slate-500 mb-1">Status</label>
                        <select name="status" class="w-full px-3 py-2 border border-slate-200 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500 outline-none">
                            <?php foreach (['draft', 'published', 'scheduled', 'private', 'pending'] as $s): ?>
                                <option value="<?= $s ?>" <?= $statusVal === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs text-slate-500 mb-1">Comments</label>
                        <select name="comment_status" class="w-full px-3 py-2 border border-slate-200 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500 outline-none">
                            <option value="open" <?= $commentVal === 'open' ? 'selected' : '' ?>>Open</option>
                            <option value="closed" <?= $commentVal === 'closed' ? 'selected' : '' ?>>Closed</option>
                        </select>
                    </div>
                </div>
            </div>

            <?php if ($type === 'post'): ?>
            <div class="bg-white rounded-xl border border-slate-200 p-5">
                <h3 class="text-sm font-semibold text-slate-900 mb-3">Categories</h3>
                <div class="space-y-2 max-h-48 overflow-y-auto">
                    <?php foreach ($categories as $cat): ?>
                        <label class="flex items-center gap-2 text-sm cursor-pointer">
                            <input type="checkbox" name="term_ids[]" value="<?= $cat['id'] ?>"
                                <?= in_array((int)$cat['id'], $selectedTermIds, true) ? 'checked' : '' ?>
                                class="rounded border-slate-300 text-blue-600 focus:ring-blue-500">
                            <span><?= htmlspecialchars($cat['name']) ?></span>
                        </label>
                    <?php endforeach; ?>
                    <?php if (empty($categories)): ?>
                        <p class="text-xs text-slate-500">No categories yet. <a href="<?= $base ?>/admin/taxonomies/category" class="text-blue-600 hover:underline">Create one</a>.</p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="bg-white rounded-xl border border-slate-200 p-5">
                <h3 class="text-sm font-semibold text-slate-900 mb-3">Tags</h3>
                <div class="space-y-2 max-h-48 overflow-y-auto">
                    <?php foreach ($tags as $tag): ?>
                        <label class="flex items-center gap-2 text-sm cursor-pointer">
                            <input type="checkbox" name="term_ids[]" value="<?= $tag['id'] ?>"
                                <?= in_array((int)$tag['id'], $selectedTermIds, true) ? 'checked' : '' ?>
                                class="rounded border-slate-300 text-blue-600 focus:ring-blue-500">
                            <span><?= htmlspecialchars($tag['name']) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <div class="bg-white rounded-xl border border-slate-200 p-5">
                <h3 class="text-sm font-semibold text-slate-900 mb-3">Featured Image</h3>
                <?php
                $currentMediaId = $post['featured_media_id'] ?? '';
                $currentMediaUrl = $post['featured_url'] ?? '';
                ?>
                <input type="hidden" id="featured_media_id" name="featured_media_id" value="<?= htmlspecialchars((string)$currentMediaId) ?>">

                <div id="featured-preview" class="<?= $currentMediaId ? '' : 'hidden' ?>">
                    <div class="aspect-video rounded-lg overflow-hidden bg-slate-100 border border-slate-200 mb-2">
                        <?php if ($currentMediaUrl): ?>
                            <img id="featured-thumb" src="<?= htmlspecialchars($currentMediaUrl) ?>" alt="" class="w-full h-full object-cover">
                        <?php else: ?>
                            <img id="featured-thumb" src="" alt="" class="w-full h-full object-cover">
                        <?php endif; ?>
                    </div>
                    <div class="flex items-center gap-2">
                        <button type="button" id="featured-change" class="flex-1 px-3 py-1.5 border border-slate-300 hover:bg-slate-50 rounded-lg text-xs font-medium text-slate-700">
                            <?= icon('arrow-path', 'w-4 h-4 mr-1') ?> Replace
                        </button>
                        <button type="button" id="featured-remove" class="px-3 py-1.5 border border-red-200 text-red-600 hover:bg-red-50 rounded-lg text-xs font-medium">
                            <?= icon('trash', 'w-4 h-4 mr-1') ?> Remove
                        </button>
                    </div>
                </div>

                <button type="button" id="featured-select" class="<?= $currentMediaId ? 'hidden' : '' ?> w-full px-4 py-6 border-2 border-dashed border-slate-300 hover:border-blue-400 hover:bg-blue-50/30 rounded-lg text-sm font-medium text-slate-600 hover:text-blue-700 transition">
                    <?= icon('photo', 'w-6 h-6 block mb-2 text-slate-400') ?>
                    Set Featured Image
                </button>
            </div>
        </div>
    </div>
</form>

<?php $this->endSection(); ?>

<?php $this->section('scripts'); ?>
<script>
(function () {
    var idInput = document.getElementById('featured_media_id');
    var preview = document.getElementById('featured-preview');
    var thumb   = document.getElementById('featured-thumb');
    var btnSelect = document.getElementById('featured-select');
    var btnChange = document.getElementById('featured-change');
    var btnRemove = document.getElementById('featured-remove');
    if (!idInput) { console.warn('[Basehim] featured_media_id input not found'); return; }
    if (!window.BasehimMedia) {
        console.error('[Basehim] window.BasehimMedia is undefined — /admin/assets/js/media.js failed to load. Check the network tab.');
        if (btnSelect) btnSelect.addEventListener('click', function () {
            alert('Media picker script failed to load. Please clear your browser cache (Ctrl+Shift+R) and try again.');
        });
        return;
    }

    function pickImage() {
        BasehimMedia.openPicker({
            onSelect: function (media) {
                idInput.value = media.id;
                thumb.src = media.url;
                preview.classList.remove('hidden');
                btnSelect.classList.add('hidden');
            }
        });
    }

    if (btnSelect) btnSelect.addEventListener('click', pickImage);
    if (btnChange) btnChange.addEventListener('click', pickImage);
    if (btnRemove) btnRemove.addEventListener('click', function () {
        idInput.value = '';
        thumb.src = '';
        preview.classList.add('hidden');
        btnSelect.classList.remove('hidden');
    });
})();
</script>

<?php $this->endSection(); ?>
