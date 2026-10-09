<?php $this->extend('layouts.app'); ?>
<?php $this->section('content'); ?>

<?php
/*
 * The post editor: a full-screen, Gutenberg-style writing surface.
 *
 * The page is still one ordinary form posting to the same URL with the same
 * field names as before (title, slug, content, content_format, excerpt,
 * status, comment_status, term_ids[], featured_media_id, seo_*), so saving,
 * revisions and every app hook work unchanged. The block editor
 * (admin/assets/js/block-editor.js) fills the canvas and writes its JSON into
 * the content field on submit.
 */
$isEdit = $post !== null;
$app = \App\Core\Application::getInstance();

// Labels and URLs for this content type. Custom types registered by apps live
// under /admin/content/{type}; post, page and template keep their own URLs.
$typeDef = null;
try { $typeDef = $app->make(\App\Services\PostTypeRegistry::class)->get($type); } catch (\Throwable) {}
$typeLabel  = (string) ($typeDef['singular'] ?? ucfirst($type));
$typePlural = (string) ($typeDef['label'] ?? (ucfirst($type) . 's'));
$adminPath  = in_array($type, ['post', 'page', 'template'], true) ? "/admin/{$type}s" : "/admin/content/{$type}";
$listUrl    = $base . $adminPath;
$action     = $isEdit ? "{$listUrl}/{$post['id']}" : $listUrl;

$titleVal   = $isEdit ? (string) $post['title'] : '';
$contentVal = $isEdit ? (string) $post['content'] : '';
$slugVal    = $isEdit ? (string) $post['slug'] : '';
$excerptVal = $isEdit ? (string) ($post['excerpt'] ?? '') : '';
$statusVal  = $isEdit ? (string) $post['status'] : 'draft';
$commentVal = $isEdit ? (string) $post['comment_status'] : 'open';
$formatVal  = $isEdit ? (string) ($post['content_format'] ?? 'blocks') : 'blocks';
if (!in_array($formatVal, ['blocks', 'html', 'markdown'], true)) $formatVal = 'html';
$isBlocks   = $formatVal === 'blocks';
$isLive     = $statusVal === 'published';

$viewUrl = '';
if ($isEdit && !empty($post['slug']) && $type !== 'template') {
    try { $viewUrl = \App\Core\Helpers::postUrl($post, (string) $base); } catch (\Throwable) { $viewUrl = ''; }
}
$statusLabels = ['draft' => 'Draft', 'pending' => 'Pending review', 'published' => 'Published', 'scheduled' => 'Scheduled', 'private' => 'Private'];

// ---- Block editor: app extension surface ----------------------------------
// Apps enqueue their editor scripts/styles and extend the runtime config via
// these hooks (see docs/BLOCK-EDITOR.md).
$nbeHooks = $app->make(\App\Core\HookRegistry::class);
$nbeSess  = $app->make(\App\Core\Session::class);
$nbeHooks->doAction('editor.enqueue', $isEdit ? $post : null);
$nbeStyles  = (array) $nbeHooks->applyFilters('editor.styles', []);
$nbeScripts = (array) $nbeHooks->applyFilters('editor.scripts', []);
$nbeWidgets = $app->make(\App\Core\WidgetRegistry::class)->all('editor');
$nbeConfig  = (array) $nbeHooks->applyFilters('editor.config', [
    'base'            => $base,
    'csrf'            => $nbeSess->csrfToken(),
    'postId'          => $isEdit ? (int) $post['id'] : null,
    'postType'        => $isEdit ? ($post['type'] ?? $type) : $type,
    'typeLabel'       => $typeLabel,
    'typePlural'      => $typePlural,
    'listUrl'         => $listUrl,
    'renderUrl'       => $base . '/admin/posts/editor/render',
    'templatesUrl'    => $base . '/admin/posts/editor/templates',
    'mediaUrl'        => $base . '/admin/media/json',
    'widgets'         => $nbeWidgets,
    'widgetRenderUrl' => $base . '/admin/widgets/render',
    'flash'           => $flash ?? null,
], $isEdit ? $post : null);
$v = urlencode(BASEHIM_VERSION);
?>
<?php foreach ($nbeStyles as $nbeCss): ?>
<link rel="stylesheet" href="<?= htmlspecialchars((string) $nbeCss) ?>" data-bhe-preview>
<?php endforeach; ?>
<link rel="stylesheet" href="<?= $base ?>/admin/assets/css/block-editor.css?v=<?= $v ?>">

<form method="POST" action="<?= htmlspecialchars($action) ?>" id="bh-post-form" data-bh-post-form>
<input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">

<div class="bhe-root has-sidebar<?= $isBlocks ? '' : ' is-code-mode' ?>" id="bhe-root">
    <header class="bhe-header" id="bhe-header" role="region" aria-label="Editor top bar">
        <div class="bhe-header__left">
            <a class="bhe-back" href="<?= htmlspecialchars($listUrl) ?>" title="Back to <?= htmlspecialchars($typePlural) ?>" aria-label="Back to <?= htmlspecialchars($typePlural) ?>">
                <?= brand_logo(32) ?>
            </a>
            <button type="button" class="bhe-hbtn bhe-hbtn--primary" id="bhe-inserter-toggle" aria-pressed="false" title="Block Inserter" aria-label="Toggle block inserter"><?= icon('plus', 'bhe-icon') ?></button>
            <button type="button" class="bhe-hbtn bhe-hide-mobile" id="bhe-undo" disabled title="Undo (Ctrl+Z)" aria-label="Undo"><?= icon('arrow-uturn-left', 'bhe-icon') ?></button>
            <button type="button" class="bhe-hbtn bhe-hide-mobile" id="bhe-redo" disabled title="Redo (Ctrl+Shift+Z)" aria-label="Redo"><?= icon('arrow-uturn-right', 'bhe-icon') ?></button>
            <button type="button" class="bhe-hbtn" id="bhe-listview-toggle" aria-pressed="false" title="Document Overview (Shift+Alt+O)" aria-label="Document Overview">
                <svg class="bhe-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" aria-hidden="true"><path d="M4 6h3M10 6h10M4 12h3M10 12h10M4 18h3M10 18h10"/></svg>
            </button>
        </div>
        <div class="bhe-header__center">
            <div class="bhe-docbar" title="<?= htmlspecialchars($typeLabel) ?>">
                <span class="bhe-docbar__title" id="bhe-doctitle"><?= htmlspecialchars($titleVal !== '' ? $titleVal : 'Untitled') ?></span>
                <span class="bhe-docbar__type">· <?= htmlspecialchars($typeLabel) ?></span>
            </div>
        </div>
        <div class="bhe-header__right">
            <span class="bhe-savestate bhe-hide-mobile" id="bhe-save-state" aria-live="polite"></span>
            <?php if (!$isLive && $statusVal !== 'scheduled' && $statusVal !== 'private'): ?>
                <button type="submit" class="bhe-btn bhe-btn--tertiary" id="bhe-save-draft" title="Save (Ctrl+S)">Save draft</button>
            <?php endif; ?>
            <?php if ($viewUrl !== ''): ?>
                <a class="bhe-btn bhe-btn--tertiary bhe-hide-mobile" href="<?= htmlspecialchars($viewUrl) ?>" target="_blank" rel="noopener"
                   title="<?= $isLive ? 'View the live ' . htmlspecialchars(strtolower($typeLabel)) : 'Preview — only signed-in editors can see it' ?>">
                    <?= $isLive ? 'View' : 'Preview' ?> <?= icon('arrow-top-right-on-square', 'bhe-icon') ?>
                </a>
            <?php endif; ?>
            <?php if ($type === 'template'): ?>
                <button type="submit" class="bhe-btn bhe-btn--primary" id="bhe-publish">Save</button>
            <?php elseif ($isLive || $statusVal === 'scheduled' || $statusVal === 'private'): ?>
                <button type="submit" class="bhe-btn bhe-btn--primary" id="bhe-publish">Update</button>
            <?php else: ?>
                <button type="submit" class="bhe-btn bhe-btn--primary" id="bhe-publish" data-status="published">Publish</button>
            <?php endif; ?>
            <div id="bhe-plugin-buttons" class="bhe-plugin-buttons"></div>
            <button type="button" class="bhe-hbtn is-active" id="bhe-settings-toggle" aria-pressed="true" title="Settings (Ctrl+Shift+,)" aria-label="Settings">
                <svg class="bhe-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M15 4v16"/></svg>
            </button>
            <button type="button" class="bhe-hbtn" id="bhe-more" aria-haspopup="menu" title="Options" aria-label="Options"><?= icon('ellipsis-vertical', 'bhe-icon') ?></button>
        </div>
    </header>

    <div class="bhe-body">
        <aside class="bhe-left" id="bhe-left" hidden aria-label="Block library"></aside>

        <div class="bhe-canvas" id="bhe-canvas">
            <div class="bhe-canvas__inner" id="bhe-canvas-inner">
                <div class="bhe-doc">
                    <div class="bhe-modebar" id="bhe-mode-label" <?= $isBlocks ? 'hidden' : '' ?>>
                        <span>Editing <?= $formatVal === 'markdown' ? 'Markdown' : 'HTML' ?> source</span>
                        <button type="button" class="bhe-btn bhe-btn--tertiary bhe-btn--small" id="bhe-exit-code">Exit code editor</button>
                    </div>
                    <textarea name="title" class="bhe-title" rows="1" placeholder="Add title" aria-label="Add title"><?= htmlspecialchars($titleVal) ?></textarea>
                    <div id="bh-block-editor" <?= $isBlocks ? '' : 'hidden' ?>></div>
                    <textarea name="content" id="nbe-raw" class="bhe-raw" spellcheck="false" placeholder="Write your content here…" aria-label="Content source" <?= $isBlocks ? 'hidden' : '' ?>><?= htmlspecialchars($contentVal) ?></textarea>
                </div>
            </div>
        </div>

        <aside class="bhe-sidebar" id="bhe-sidebar" aria-label="Editor settings">
            <div class="bhe-sidebar__tabs" role="tablist">
                <button type="button" class="bhe-sidebar__tab is-active" data-tab="post" role="tab" aria-selected="true"><?= htmlspecialchars($typeLabel) ?></button>
                <button type="button" class="bhe-sidebar__tab" data-tab="block" role="tab" aria-selected="false">Block</button>
                <button type="button" class="bhe-iconbtn bhe-sidebar__close" id="bhe-sidebar-close" aria-label="Close settings"><?= icon('x-mark', 'bhe-icon') ?></button>
            </div>

            <div class="bhe-sidebar__panel" id="bhe-post-panel" role="tabpanel">
                <div id="bh-post-settings">
                    <?php // Present whenever this panel is submitted. The server only
                          // updates status, categories, tags, featured image and comment
                          // setting when it arrives, so a save that somehow misses the
                          // panel can never reset them. ?>
                    <input type="hidden" name="_post_settings" value="1">

                    <section class="bhe-panel is-open" data-bhe-panel="summary">
                        <button type="button" class="bhe-panel__head" aria-expanded="true"><span>Summary</span><?= icon('chevron-up', 'bhe-icon bhe-panel__chev') ?></button>
                        <div class="bhe-panel__body">
                            <div class="bhe-row">
                                <label class="bhe-row__label" for="bhe-status">Status</label>
                                <div class="bhe-row__control">
                                    <select name="status" id="bhe-status" class="bhe-input">
                                        <?php foreach ($statusLabels as $s => $label): ?>
                                            <option value="<?= $s ?>" <?= $statusVal === $s ? 'selected' : '' ?>><?= $label ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="bhe-row">
                                <label class="bhe-row__label" for="bhe-slug">Slug</label>
                                <div class="bhe-row__control">
                                    <input type="text" name="slug" id="bhe-slug" value="<?= htmlspecialchars($slugVal) ?>" class="bhe-input" placeholder="auto-generated">
                                </div>
                            </div>
                            <?php if ($viewUrl !== ''): ?>
                                <div class="bhe-row">
                                    <span class="bhe-row__label">Link</span>
                                    <div class="bhe-row__control" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                                        <a href="<?= htmlspecialchars($viewUrl) ?>" target="_blank" rel="noopener" style="color:var(--bhe-accent)"><?= htmlspecialchars(preg_replace('#^https?://#', '', $viewUrl)) ?></a>
                                    </div>
                                </div>
                            <?php endif; ?>
                            <div class="bhe-row">
                                <label class="bhe-row__label" for="nbe-format">Editor</label>
                                <div class="bhe-row__control">
                                    <select name="content_format" id="nbe-format" class="bhe-input">
                                        <option value="blocks" <?= $formatVal === 'blocks' ? 'selected' : '' ?>>Blocks (visual)</option>
                                        <option value="html" <?= $formatVal === 'html' ? 'selected' : '' ?>>HTML</option>
                                        <option value="markdown" <?= $formatVal === 'markdown' ? 'selected' : '' ?>>Markdown</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </section>

                    <section class="bhe-panel is-open" data-bhe-panel="featured">
                        <button type="button" class="bhe-panel__head" aria-expanded="true"><span>Featured image</span><?= icon('chevron-up', 'bhe-icon bhe-panel__chev') ?></button>
                        <div class="bhe-panel__body">
                            <?php
                            $currentMediaId = $post['featured_media_id'] ?? '';
                            $currentMediaUrl = $post['featured_url'] ?? '';
                            ?>
                            <input type="hidden" id="featured_media_id" name="featured_media_id" value="<?= htmlspecialchars((string) $currentMediaId) ?>">
                            <div id="featured-preview" class="<?= $currentMediaId ? '' : 'hidden' ?>">
                                <img id="featured-thumb" class="bhe-featured__img" src="<?= htmlspecialchars((string) $currentMediaUrl) ?>" alt="">
                                <div class="bhe-featured__actions">
                                    <button type="button" id="featured-change" class="bhe-btn bhe-btn--secondary bhe-btn--small">Replace</button>
                                    <button type="button" id="featured-remove" class="bhe-btn bhe-btn--tertiary bhe-btn--small is-danger">Remove</button>
                                </div>
                            </div>
                            <button type="button" id="featured-select" class="bhe-featured__drop <?= $currentMediaId ? 'hidden' : '' ?>">
                                <?= icon('photo', 'bhe-icon') ?>
                                Set featured image
                            </button>
                        </div>
                    </section>

                    <?php if ($type === 'post'): ?>
                    <section class="bhe-panel is-open" data-bhe-panel="categories">
                        <button type="button" class="bhe-panel__head" aria-expanded="true"><span>Categories</span><?= icon('chevron-up', 'bhe-icon bhe-panel__chev') ?></button>
                        <div class="bhe-panel__body">
                            <div class="bhe-checklist">
                                <?php foreach ($categories as $cat): ?>
                                    <label class="bhe-check">
                                        <input type="checkbox" name="term_ids[]" value="<?= (int) $cat['id'] ?>" <?= in_array((int) $cat['id'], $selectedTermIds, true) ? 'checked' : '' ?>>
                                        <span><?= htmlspecialchars($cat['name']) ?></span>
                                    </label>
                                <?php endforeach; ?>
                                <?php if (empty($categories)): ?>
                                    <p class="bhe-muted">No categories yet. <a href="<?= $base ?>/admin/taxonomies/category" style="color:var(--bhe-accent)">Create one</a>.</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </section>

                    <section class="bhe-panel is-open" data-bhe-panel="tags">
                        <button type="button" class="bhe-panel__head" aria-expanded="true"><span>Tags</span><?= icon('chevron-up', 'bhe-icon bhe-panel__chev') ?></button>
                        <div class="bhe-panel__body">
                            <div class="bhe-checklist">
                                <?php foreach ($tags as $tag): ?>
                                    <label class="bhe-check">
                                        <input type="checkbox" name="term_ids[]" value="<?= (int) $tag['id'] ?>" <?= in_array((int) $tag['id'], $selectedTermIds, true) ? 'checked' : '' ?>>
                                        <span><?= htmlspecialchars($tag['name']) ?></span>
                                    </label>
                                <?php endforeach; ?>
                                <?php if (empty($tags)): ?>
                                    <p class="bhe-muted">No tags yet. <a href="<?= $base ?>/admin/taxonomies/tag" style="color:var(--bhe-accent)">Create one</a>.</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </section>
                    <?php endif; ?>

                    <section class="bhe-panel is-open" data-bhe-panel="excerpt">
                        <button type="button" class="bhe-panel__head" aria-expanded="true"><span>Excerpt</span><?= icon('chevron-up', 'bhe-icon bhe-panel__chev') ?></button>
                        <div class="bhe-panel__body">
                            <div class="bhe-field">
                                <label class="bhe-label" for="bhe-excerpt">Write an excerpt (optional)</label>
                                <textarea name="excerpt" id="bhe-excerpt" rows="4" class="bhe-input" placeholder="Auto-generated from the content if left empty"><?= htmlspecialchars($excerptVal) ?></textarea>
                            </div>
                        </div>
                    </section>

                    <section class="bhe-panel" data-bhe-panel="discussion">
                        <button type="button" class="bhe-panel__head" aria-expanded="false"><span>Discussion</span><?= icon('chevron-up', 'bhe-icon bhe-panel__chev') ?></button>
                        <div class="bhe-panel__body" hidden>
                            <div class="bhe-row">
                                <label class="bhe-row__label" for="bhe-comments">Comments</label>
                                <div class="bhe-row__control">
                                    <select name="comment_status" id="bhe-comments" class="bhe-input">
                                        <option value="open" <?= $commentVal === 'open' ? 'selected' : '' ?>>Open</option>
                                        <option value="closed" <?= $commentVal === 'closed' ? 'selected' : '' ?>>Closed</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </section>

                    <section class="bhe-panel" data-bhe-panel="seo">
                        <button type="button" class="bhe-panel__head" aria-expanded="false"><span>SEO</span><?= icon('chevron-up', 'bhe-icon bhe-panel__chev') ?></button>
                        <div class="bhe-panel__body" hidden>
                            <div class="bhe-field">
                                <label class="bhe-label" for="bhe-seo-title">Meta title</label>
                                <input type="text" id="bhe-seo-title" name="seo_meta_title" value="<?= htmlspecialchars($seoData['meta_title'] ?? '') ?>" maxlength="200" class="bhe-input" data-bhe-count="60">
                            </div>
                            <div class="bhe-field">
                                <label class="bhe-label" for="bhe-seo-desc">Meta description</label>
                                <textarea id="bhe-seo-desc" name="seo_meta_description" rows="3" maxlength="300" class="bhe-input" data-bhe-count="160"><?= htmlspecialchars($seoData['meta_description'] ?? '') ?></textarea>
                            </div>
                            <div class="bhe-field">
                                <label class="bhe-label" for="bhe-seo-focus">Focus keyword</label>
                                <input type="text" id="bhe-seo-focus" name="seo_focus" value="<?= htmlspecialchars($seoData['focus_keyword'] ?? '') ?>" class="bhe-input">
                            </div>
                            <div class="bhe-field">
                                <label class="bhe-label" for="bhe-seo-robots">Robots</label>
                                <select id="bhe-seo-robots" name="seo_robots" class="bhe-input">
                                    <?php foreach (['index,follow', 'noindex,follow', 'index,nofollow', 'noindex,nofollow'] as $r): ?>
                                        <option value="<?= $r ?>" <?= ($seoData['robots'] ?? 'index,follow') === $r ? 'selected' : '' ?>><?= $r ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="bhe-field">
                                <label class="bhe-label" for="bhe-seo-canonical">Canonical URL</label>
                                <input type="url" id="bhe-seo-canonical" name="seo_canonical" value="<?= htmlspecialchars($seoData['canonical_url'] ?? '') ?>" class="bhe-input" placeholder="https://">
                            </div>
                        </div>
                    </section>

                    <div id="bhe-app-panels"></div>
                </div><!-- /#bh-post-settings -->
            </div>

            <div class="bhe-sidebar__panel" id="bhe-block-inspector" role="tabpanel" hidden></div>
        </aside>
    </div>

    <div class="bhe-footer" id="bhe-breadcrumb" aria-label="Block breadcrumb"></div>
</div>
</form>

<script>window.BasehimEditorConfig = <?= json_encode($nbeConfig, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>
<script src="<?= $base ?>/admin/assets/js/block-editor.js?v=<?= $v ?>"></script>
<?php foreach ($nbeScripts as $nbeJs): ?>
<script src="<?= htmlspecialchars((string) $nbeJs) ?>"></script>
<?php endforeach; ?>
<script>
(function () {
    // Switching between the visual editor and HTML / Markdown source.
    var sel = document.getElementById('nbe-format');
    var mountEl = document.getElementById('bh-block-editor');
    var raw = document.getElementById('nbe-raw');
    var root = document.getElementById('bhe-root');
    var bar = document.getElementById('bhe-mode-label');
    if (!sel || !mountEl || !raw) return;
    var cfg = window.BasehimEditorConfig || {};
    var current = sel.value;

    function show(isBlocks) {
        mountEl.hidden = !isBlocks;
        raw.hidden = isBlocks;
        if (root) root.classList.toggle('is-code-mode', !isBlocks);
        if (bar) {
            bar.hidden = isBlocks;
            bar.firstElementChild.textContent = 'Editing ' + (sel.value === 'markdown' ? 'Markdown' : 'HTML') + ' source';
        }
    }
    var exit = document.getElementById('bhe-exit-code');
    if (exit) exit.addEventListener('click', function () { sel.value = 'blocks'; sel.dispatchEvent(new Event('change', { bubbles: true })); });

    // HTML → blocks: paragraphs, headings, images, lists, tables… What blocks
    // cannot hold without loss (a form, a script) stays a Custom HTML block.
    function convertInto(html) {
        var blocks = [];
        try { blocks = BasehimEditor.htmlToBlocks ? BasehimEditor.htmlToBlocks(html) : []; } catch (e) { blocks = []; }
        if (!blocks.length) blocks = [{ type: 'html', data: { html: html } }];
        BasehimEditor.setBlocks(blocks);
        var kept = blocks.filter(function (b) { return b.type === 'html'; }).length;
        note('Converted into ' + blocks.length + ' block' + (blocks.length === 1 ? '' : 's') + '.'
            + (kept ? ' ' + kept + ' part' + (kept === 1 ? '' : 's') + ' that blocks can\'t represent (forms, scripts…) ' + (kept === 1 ? 'was' : 'were') + ' kept as Custom HTML.' : '')
            + ' Not what you expected? Leave without saving to keep the original.');
    }
    function note(msg) {
        var n = document.getElementById('nbe-convert-note');
        if (!n) {
            n = document.createElement('div'); n.id = 'nbe-convert-note'; n.setAttribute('role', 'status');
            n.className = 'bhe-convert-note';
            mountEl.parentNode.insertBefore(n, mountEl);
        }
        n.innerHTML = '<span></span><button type="button" aria-label="Dismiss">&times;</button>';
        n.firstChild.textContent = msg;
        n.lastChild.onclick = function () { n.remove(); };
    }

    sel.addEventListener('change', function () {
        var from = current, to = sel.value;
        current = to;
        var isBlocks = to === 'blocks';
        show(isBlocks);

        if (isBlocks) {
            if (window.BasehimEditor && BasehimEditor.setBlocks) {
                var val = raw.value || '';
                try {
                    var doc = JSON.parse(val);
                    if (doc && Array.isArray(doc.blocks)) { BasehimEditor.setBlocks(doc.blocks); return; }
                } catch (e) { /* not JSON */ }
                if (val.trim() === '') { BasehimEditor.setBlocks([{ type: 'paragraph', data: {} }]); return; }
                if (from === 'markdown' && cfg.renderUrl) {
                    var mb = new FormData();
                    mb.append('_csrf', cfg.csrf || ''); mb.append('from', 'markdown'); mb.append('content', val);
                    fetch(cfg.renderUrl, { method: 'POST', body: mb, credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
                        .then(function (r) { return r.json(); })
                        .then(function (d) { convertInto(d && d.ok && typeof d.html === 'string' ? d.html : val); })
                        .catch(function () { convertInto(val); });
                    return;
                }
                convertInto(val);
            }
            return;
        }

        // Leaving the visual editor: the source box gets the post as HTML,
        // rendered by the same code that renders it for visitors.
        if (from !== 'blocks' || !window.BasehimEditor || !BasehimEditor.serialize || !cfg.renderUrl) return;
        var body = new FormData();
        body.append('_csrf', cfg.csrf || '');
        body.append('content', BasehimEditor.serialize());
        raw.readOnly = true;
        raw.value = '';
        raw.placeholder = 'Converting…';
        fetch(cfg.renderUrl, { method: 'POST', body: body, credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d || !d.ok || typeof d.html !== 'string') throw new Error((d && d.error) || 'render failed');
                // Strip the one-time block stylesheet link the renderer adds.
                raw.value = d.html.replace(/^<link[^>]+data-basehim-blocks[^>]*>\s*/i, '');
            })
            .catch(function () {
                // Never leave block JSON in a source box that will be saved as HTML.
                sel.value = current = 'blocks';
                show(true);
                var msg = 'Could not convert the content to ' + to.toUpperCase() + '. The visual editor has been kept.';
                if (window.bhToast) window.bhToast(msg, 'error'); else window.alert(msg);
            })
            .then(function () {
                raw.readOnly = false;
                raw.placeholder = 'Write your content here…';
            });
    });
})();
</script>

<?php $this->endSection(); ?>

<?php $this->section('scripts'); ?>
<script>
(function () {
    // Featured image.
    var idInput = document.getElementById('featured_media_id');
    var preview = document.getElementById('featured-preview');
    var thumb   = document.getElementById('featured-thumb');
    var btnSelect = document.getElementById('featured-select');
    var btnChange = document.getElementById('featured-change');
    var btnRemove = document.getElementById('featured-remove');
    // Uploads made from this screen (image blocks, the featured image, files
    // dropped or pasted on the canvas) are named after the post when the file's
    // own name is meaningless: a phone photo becomes best-hiking-trails.jpg.
    if (window.BasehimMedia) {
        BasehimMedia.nameHint = function () {
            var t = document.querySelector('textarea[name="title"]');
            return t ? t.value.trim() : '';
        };
    }
    if (!idInput) return;
    if (!window.BasehimMedia) {
        console.error('[Basehim] window.BasehimMedia is undefined — /admin/assets/js/media.js failed to load.');
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
                window.BasehimEditorDirty = true;
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
        window.BasehimEditorDirty = true;
    });
})();
</script>

<script>
(function () {
    // Character counters for the SEO fields.
    Array.prototype.forEach.call(document.querySelectorAll('[data-bhe-count]'), function (f) {
        var max = parseInt(f.getAttribute('data-bhe-count'), 10);
        var c = document.createElement('div'); c.className = 'bhe-counter';
        f.parentNode.appendChild(c);
        function paint() { var n = f.value.length; c.textContent = n + ' / ' + max + ' recommended'; c.style.color = n > max ? '#cc1818' : ''; }
        f.addEventListener('input', paint); paint();
    });
})();
</script>

<script>
(function () {
    // -- Unsaved-work guard
    // Browsers only honour beforeunload after a real user interaction, and they
    // show their own wording — returnValue just opts in.
    var form = document.querySelector('form[data-bh-post-form], #bh-post-form');
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
        saving = true;
        window.BasehimEditorDirty = false;
    });
    window.addEventListener('beforeunload', function (e) {
        if (saving || !isDirty()) return;
        e.preventDefault();
        e.returnValue = '';
        return '';
    });
    // Warn when leaving via a link too (beforeunload can be suppressed).
    document.addEventListener('click', function (e) {
        var a = e.target.closest('a[href]');
        if (!a || saving || !isDirty()) return;
        if (a.target === '_blank' || a.hasAttribute('download') || a.closest('[contenteditable="true"]')) return;
        var href = a.getAttribute('href') || '';
        if (href.charAt(0) === '#' || href.indexOf('javascript:') === 0) return;
        e.preventDefault();
        var ask = window.bhConfirm ? bhConfirm('You have unsaved changes. Leave without saving?', { danger: true, confirmLabel: 'Leave', cancelLabel: 'Stay' }) : Promise.resolve(window.confirm('You have unsaved changes. Leave without saving?'));
        ask.then(function (leave) {
            if (!leave) return;
            saving = true;
            window.location.href = a.href;
        });
    });
})();
</script>
<?php $this->endSection(); ?>
