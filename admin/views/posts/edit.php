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

<form method="POST" action="<?= $action ?>" class="space-y-5" id="bh-post-form" data-bh-post-form>
    <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">

    <div class="flex items-center justify-between flex-wrap gap-3">
        <div>
            <a href="<?= $base ?>/admin/<?= $type ?>s" class="text-sm text-slate-500 hover:text-blue-600">
                <?= icon('arrow-left', 'w-4 h-4 mr-1') ?> Back to <?= $typeLabel ?>s
            </a>
            <h2 class="text-xl font-semibold text-slate-900 mt-1"><?= $isEdit ? 'Edit' : 'New' ?> <?= $typeLabel ?></h2>
        </div>
        <div class="flex items-center gap-2">
            <?php if ($isEdit && !empty($post['slug'])):
                $isLive = ($post['status'] ?? '') === 'published';
                $viewUrl = $base . '/' . ($type === 'post' ? 'posts/' : 'page/') . rawurlencode((string) $post['slug']);
            ?>
                <?php // Drafts get a Preview link — visible only to signed-in users who may edit. ?>
                <a href="<?= htmlspecialchars($viewUrl) ?>" target="_blank" rel="noopener"
                    class="inline-flex items-center px-4 py-2 border rounded-lg text-sm font-medium <?= $isLive
                        ? 'border-slate-300 hover:bg-slate-50 text-slate-700'
                        : 'border-amber-300 bg-amber-50 hover:bg-amber-100 text-amber-800' ?>"
                    title="<?= $isLive ? 'View the live post' : 'Preview this draft — only signed-in editors can see it' ?>">
                    <?= icon('eye', 'w-4 h-4 mr-1') ?> <?= $isLive ? 'View' : 'Preview' ?>
                </a>
            <?php endif; ?>
            <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium shadow-sm">
                <?= icon('document-check', 'w-4 h-4 mr-1') ?> Save
            </button>
        </div>
    </div>

    <!-- Title + full-width editor -->
    <div class="bg-white rounded-xl border border-slate-200 p-5 mb-5">
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

    <div class="grid grid-cols-1 lg:grid-cols-4 gap-5">
        <!-- Editor (full width of main area) -->
        <div class="lg:col-span-3 space-y-5">
            <div class="bg-white rounded-xl border border-slate-200 p-5">
                <div class="flex items-center justify-between mb-2">
                    <label class="block text-sm font-medium text-slate-700">Content</label>
                    <select name="content_format" id="nbe-format" class="text-xs px-2 py-1 border border-slate-200 rounded">
                        <option value="blocks" <?= (!$isEdit || $post['content_format']==='blocks') ? 'selected' : '' ?>>Blocks (visual)</option>
                        <option value="html" <?= ($isEdit && $post['content_format']==='html') ? 'selected' : '' ?>>HTML</option>
                        <option value="markdown" <?= ($isEdit && $post['content_format']==='markdown') ? 'selected' : '' ?>>Markdown</option>
                    </select>
                </div>
                <?php
                // ---- Block editor: app extension surface ----------------
                // Apps enqueue their editor scripts/styles and extend the
                // runtime config via these hooks (see BLOCK-EDITOR.md).
                $nbeApp    = \App\Core\Application::getInstance();
                $nbeHooks  = $nbeApp->make(\App\Core\HookRegistry::class);
                $nbeSess   = $nbeApp->make(\App\Core\Session::class);
                $nbeIsBlocks = (!$isEdit || ($post['content_format'] ?? 'blocks') === 'blocks');
                $nbeHooks->doAction('editor.enqueue', $isEdit ? $post : null);
                $nbeStyles  = (array) $nbeHooks->applyFilters('editor.styles', []);
                $nbeScripts = (array) $nbeHooks->applyFilters('editor.scripts', []);
                $nbeWidgets = \App\Core\Application::getInstance()
                    ->make(\App\Core\WidgetRegistry::class)->all('editor');
                $nbeConfig  = (array) $nbeHooks->applyFilters('editor.config', [
                    'base'      => $base,
                    'csrf'      => $nbeSess->csrfToken(),
                    'postId'    => $isEdit ? (int) $post['id'] : null,
                    'postType'  => $isEdit ? ($post['type'] ?? 'post') : 'post',
                    'renderUrl' => $base . '/admin/posts/editor/render',
                    'templatesUrl' => $base . '/admin/posts/editor/templates',
                    'mediaUrl'  => $base . '/admin/media/json',
                    'widgets'   => $nbeWidgets,
                    'widgetRenderUrl' => $base . '/admin/widgets/render',
                    'sidebarTarget' => 'bh-editor-sidebar',
                ], $isEdit ? $post : null);
                foreach ($nbeStyles as $nbeCss): ?>
                    <link rel="stylesheet" href="<?= htmlspecialchars((string) $nbeCss) ?>">
                <?php endforeach; ?>
                <link rel="stylesheet" href="<?= $base ?>/admin/assets/css/block-editor.css?v=<?= urlencode(BASEHIM_VERSION) ?>">
                <link rel="stylesheet" href="<?= $base ?>/admin/assets/css/block-editor-ux.css?v=<?= urlencode(BASEHIM_VERSION) ?>">

                <div id="bh-block-editor" <?= $nbeIsBlocks ? '' : 'style="display:none"' ?>></div>
                <textarea name="content" rows="18" id="nbe-raw" <?= $nbeIsBlocks ? 'style="display:none"' : '' ?>
                    class="w-full px-3 py-2 border border-slate-200 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500 outline-none font-mono text-sm"
                    placeholder="Write your content here..."><?= htmlspecialchars($contentVal) ?></textarea>

                <script>window.BasehimEditorConfig = <?= json_encode($nbeConfig, JSON_UNESCAPED_SLASHES) ?>;</script>
                <script src="<?= $base ?>/admin/assets/js/block-editor.js?v=<?= urlencode(BASEHIM_VERSION) ?>"></script>
                <script src="<?= $base ?>/admin/assets/js/block-editor-ux.js?v=<?= urlencode(BASEHIM_VERSION) ?>"></script>
                <?php foreach ($nbeScripts as $nbeJs): ?>
                    <script src="<?= htmlspecialchars((string) $nbeJs) ?>"></script>
                <?php endforeach; ?>
                <script>
                (function () {
                    // Switch between the visual block editor and the raw textarea.
                    var sel = document.getElementById('nbe-format');
                    var mountEl = document.getElementById('bh-block-editor');
                    var raw = document.getElementById('nbe-raw');
                    if (!sel || !mountEl || !raw) return;
                    sel.addEventListener('change', function () {
                        var isBlocks = sel.value === 'blocks';
                        mountEl.style.display = isBlocks ? '' : 'none';
                        raw.style.display = isBlocks ? 'none' : '';
                        if (isBlocks && window.BasehimEditor && BasehimEditor.setBlocks) {
                            // Re-sync editor from whatever is now in the textarea.
                            var val = raw.value || '';
                            try {
                                var doc = JSON.parse(val);
                                if (doc && Array.isArray(doc.blocks)) { BasehimEditor.setBlocks(doc.blocks); return; }
                            } catch (e) { /* not JSON */ }
                            BasehimEditor.setBlocks(val.trim() !== ''
                                ? [{ type: 'html', data: { html: val } }]
                                : [{ type: 'paragraph', data: {} }]);
                        }
                    });
                })();
                </script>
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

        <!-- Sidebar: the block editor renders its Post/Block tabs here.
             The real post-settings cards live in #bh-post-settings and are
             adopted into the Post tab by block-editor.js. When the editor is
             not active (HTML/Markdown mode) they show as normal cards.

             Below lg this whole column becomes an off-canvas drawer (see
             #nbe-aside in the CSS) so the writing area gets the full screen. -->
        <div id="nbe-aside-backdrop" aria-hidden="true"></div>
        <div id="nbe-aside" class="space-y-5">
            <div class="nbe-aside-head lg:hidden">
                <span class="text-sm font-semibold text-slate-800">Settings</span>
                <button type="button" id="nbe-aside-close" class="p-2 -mr-1 rounded-lg text-slate-400 hover:text-slate-700 hover:bg-slate-100" aria-label="Close settings">
                    <?= icon('x-mark', 'w-5 h-5') ?>
                </button>
            </div>
            <div id="bh-editor-sidebar" class="bg-white rounded-xl border border-slate-200 overflow-hidden"></div>
            <div id="bh-post-settings" class="space-y-5">
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
            </div><!-- /#bh-post-settings -->
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

<script>
(function () {
    // ── Unsaved-work guard ────────────────────────────────────────────────
    // Refreshing or navigating away used to silently discard everything typed.
    // Browsers only honour beforeunload after a real user interaction, and they
    // show their own wording — returnValue just opts in.
    var form = document.querySelector('form[data-bh-post-form], #bh-post-form') ||
               document.querySelector('main form');
    if (!form) return;

    var baseline = null;
    var saving = false;

    function snapshot() {
        try { return new URLSearchParams(new FormData(form)).toString(); }
        catch (e) { return null; }
    }
    function isDirty() {
        if (window.BasehimEditorDirty) return true;
        var now = snapshot();
        return baseline !== null && now !== null && now !== baseline;
    }

    // Take the baseline after the editor has populated its hidden field.
    window.setTimeout(function () {
        baseline = snapshot();
        window.BasehimEditorDirty = false;
    }, 600);

    form.addEventListener('submit', function () {
        saving = true;                 // a real save — never warn
        window.BasehimEditorDirty = false;
    });

    window.addEventListener('beforeunload', function (e) {
        if (saving || !isDirty()) return;
        e.preventDefault();
        e.returnValue = '';            // required for the native prompt
        return '';
    });

    // Warn when leaving via an admin link too (beforeunload can be suppressed).
    document.addEventListener('click', function (e) {
        var a = e.target.closest('a[href]');
        if (!a || saving || !isDirty()) return;
        if (a.target === '_blank' || a.hasAttribute('download')) return;
        var href = a.getAttribute('href') || '';
        if (href.charAt(0) === '#' || href.indexOf('javascript:') === 0) return;
        if (!window.confirm('You have unsaved changes. Leave without saving?')) {
            e.preventDefault();
        } else {
            saving = true;             // user chose to leave
        }
    });
})();
</script>

<?php // Mobile: floating button to open the settings drawer. ?>
<button type="button" id="nbe-aside-open" class="lg:hidden" aria-label="Open settings" aria-expanded="false">
    <?= icon('adjustments-horizontal', 'w-5 h-5') ?>
    <span>Settings</span>
</button>

<script>
(function () {
    // Off-canvas settings drawer for the editor on phones/tablets.
    var aside = document.getElementById('nbe-aside');
    var back  = document.getElementById('nbe-aside-backdrop');
    var openB = document.getElementById('nbe-aside-open');
    var closeB= document.getElementById('nbe-aside-close');
    if (!aside || !back || !openB) return;

    function open() {
        aside.classList.add('is-open'); back.classList.add('is-open');
        openB.setAttribute('aria-expanded', 'true');
        document.body.style.overflow = 'hidden';
    }
    function close() {
        aside.classList.remove('is-open'); back.classList.remove('is-open');
        openB.setAttribute('aria-expanded', 'false');
        document.body.style.overflow = '';
    }
    openB.addEventListener('click', open);
    closeB && closeB.addEventListener('click', close);
    back.addEventListener('click', close);
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });
    // Crossing the breakpoint should never leave the drawer stuck open.
    window.addEventListener('resize', function () {
        if (window.matchMedia('(min-width: 1024px)').matches) close();
    });
})();
</script>

<?php $this->endSection(); ?>
