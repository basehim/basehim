<?php $this->extend('layouts.app'); ?>
<?php $this->section('content'); ?>
<?php
/*
 * Categories, Tags and apps' taxonomies (1.2.123).
 *
 * The list is the core Data Table (terms.{slug}), paged and searched in SQL:
 * the old screen printed every term twice (a row and a hidden edit form)
 * with a parent list in each, so a site with thousands of tags stopped
 * responding. Editing is the table's Quick Edit; deleting its row menu and
 * bulk bar. Adding is the New dialog: one at a time, or several pasted at
 * once with existing names skipped (terms.create / terms.create_many). The
 * One form also posts normally, so it works without the script.
 */
$__one  = (string) $taxonomy['singular'];
$__many = (string) $taxonomy['label'];
$__lc1  = strtolower($__one);
$__lcN  = strtolower($__many);
$__hier = !empty($taxonomy['hierarchical']);
$__reg  = \App\Core\Application::getInstance()->make(\App\Services\TaxonomyRegistry::class);
// The address a slug gets, for the live preview under the name ("/tag/" + slug).
$__prefix = $__reg->termUrl((string) $taxonomy['slug'], '§', $base);
$__prefix = $__prefix !== '' ? substr($__prefix, 0, -strlen(rawurlencode('§'))) : '';
?>

<div class="flex items-center justify-between mb-5 flex-wrap gap-3">
    <div>
        <h2 class="text-xl font-semibold text-slate-900"><?= htmlspecialchars($__many) ?></h2>
        <p class="text-sm text-slate-500"><?= $__hier
            ? 'Group posts into sections. A ' . htmlspecialchars($__lc1) . ' can sit under another.'
            : 'Label posts by topic so readers can find related ones.' ?></p>
    </div>
    <button type="button" class="bh-btn bh-btn--primary" data-bh-dialog-open="bh-term-new">
        <?= icon('plus', 'w-4 h-4') ?> New <?= htmlspecialchars($__lc1) ?>
    </button>
</div>

<?php $this->include('partials.datatable', [
    'tableKey'     => $tableKey,
    'bulkEndpoint' => $base . '/admin/taxonomies/' . rawurlencode((string) $taxonomy['slug']) . '/bulk',
    'editBase'     => '',
]); ?>

<dialog class="bh-dialog bh-dialog--lg bh-termnew" id="bh-term-new" aria-labelledby="bh-term-new-title" data-bh-dialog-focus="#bh-term-name"
        data-taxonomy="<?= htmlspecialchars((string) $taxonomy['slug'], ENT_QUOTES) ?>"
        data-one="<?= htmlspecialchars($__lc1, ENT_QUOTES) ?>" data-many="<?= htmlspecialchars($__lcN, ENT_QUOTES) ?>"
        data-prefix="<?= htmlspecialchars($__prefix, ENT_QUOTES) ?>">
    <header class="bh-dialog__head">
        <div>
            <h2 class="bh-dialog__title" id="bh-term-new-title">New <?= htmlspecialchars($__lc1) ?></h2>
            <p class="bh-dialog__sub">Add one, or paste a list to add several at once.</p>
        </div>
        <button type="button" class="bh-btn bh-btn--ghost bh-btn--icon bh-btn--sm" data-bh-dialog-close aria-label="Close"><?= icon('x-mark', 'w-5 h-5') ?></button>
    </header>

    <div class="bh-termnew__tabs">
        <div class="bh-seg" role="tablist" aria-label="How many">
            <button type="button" role="tab" id="bh-term-tab-one" aria-controls="bh-term-one" aria-selected="true" aria-pressed="true" data-term-tab="one">One</button>
            <button type="button" role="tab" id="bh-term-tab-many" aria-controls="bh-term-many" aria-selected="false" aria-pressed="false" data-term-tab="many" tabindex="-1">Several</button>
        </div>
    </div>

    <form method="POST" action="<?= $base ?>/admin/taxonomies/<?= rawurlencode((string) $taxonomy['slug']) ?>/terms" class="bh-dialog__form" id="bh-term-one" role="tabpanel" aria-labelledby="bh-term-tab-one" data-term-panel="one" novalidate>
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
        <div class="bh-dialog__body bh-termnew__body">
            <div class="bh-field">
                <label class="bh-label" for="bh-term-name">Name</label>
                <input type="text" id="bh-term-name" name="name" class="bh-input" required maxlength="200" autocomplete="off" aria-describedby="bh-term-name-help bh-term-name-error">
                <p class="bh-help" id="bh-term-name-help" data-term-address><?= $__prefix !== '' ? 'Its address: ' . htmlspecialchars($__prefix) . '…' : 'Shown wherever it labels a post.' ?></p>
                <p class="bh-field__error" id="bh-term-name-error" data-term-error="name" hidden></p>
            </div>
            <details class="bh-termnew__more">
                <summary>Slug<?= $__hier ? ', parent' : '' ?> and description</summary>
                <div class="bh-termnew__more-body">
                    <div class="bh-field">
                        <label class="bh-label" for="bh-term-slug">Slug</label>
                        <input type="text" id="bh-term-slug" name="slug" class="bh-input" maxlength="200" autocomplete="off" spellcheck="false" autocapitalize="off" placeholder="Made from the name" aria-describedby="bh-term-slug-help">
                        <p class="bh-help" id="bh-term-slug-help">Lowercase letters, numbers and dashes. Leave it empty to use the name.</p>
                        <p class="bh-field__error" data-term-error="slug" hidden></p>
                    </div>
                    <?php if ($__hier): ?>
                    <div class="bh-field">
                        <label class="bh-label" for="bh-term-parent">Parent <?= htmlspecialchars($__lc1) ?></label>
                        <select id="bh-term-parent" name="parent_id" class="bh-select">
                            <option value="">— None (top level) —</option>
                            <?php foreach ($parents as $__p): ?>
                            <option value="<?= (int) $__p['id'] ?>"><?= htmlspecialchars((string) $__p['name']) ?><?= !empty($__p['parent_name']) ? ' (under ' . htmlspecialchars((string) $__p['parent_name']) . ')' : '' ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="bh-field__error" data-term-error="parent_id" hidden></p>
                    </div>
                    <?php endif; ?>
                    <div class="bh-field">
                        <label class="bh-label" for="bh-term-desc">Description</label>
                        <textarea id="bh-term-desc" name="description" class="bh-textarea" rows="3" maxlength="5000"></textarea>
                        <p class="bh-help">Shown on the <?= htmlspecialchars($__lc1) ?>'s page by themes that support it.</p>
                    </div>
                </div>
            </details>
        </div>
        <footer class="bh-dialog__foot">
            <button type="button" class="bh-btn bh-btn--secondary" data-bh-dialog-close>Cancel</button>
            <button type="submit" class="bh-btn bh-btn--secondary" data-term-again>Add &amp; add another</button>
            <button type="submit" class="bh-btn bh-btn--primary" data-term-add>Add <?= htmlspecialchars($__lc1) ?></button>
        </footer>
    </form>

    <form class="bh-dialog__form" id="bh-term-many" role="tabpanel" aria-labelledby="bh-term-tab-many" data-term-panel="many" hidden novalidate>
        <div class="bh-dialog__body bh-termnew__body">
            <div class="bh-field">
                <label class="bh-label" for="bh-term-names">Names</label>
                <textarea id="bh-term-names" name="names" class="bh-textarea bh-termnew__names" rows="7" aria-describedby="bh-term-names-help bh-term-names-error" placeholder="<?= htmlspecialchars($__hier ? "News\nReviews\nHow-to guides" : "web design\nphotography, travel\nrecipes", ENT_QUOTES) ?>"></textarea>
                <p class="bh-help" id="bh-term-names-help">One per line, or separated by commas. Up to <?= \App\Http\Controllers\Ajax\TermManageActions::MANY_MAX ?> at a time. Names you already have are skipped.</p>
                <p class="bh-field__error" id="bh-term-names-error" data-term-error="names" hidden></p>
            </div>
            <?php if ($__hier): ?>
            <div class="bh-field">
                <label class="bh-label" for="bh-term-many-parent">Put them under</label>
                <select id="bh-term-many-parent" name="parent_id" class="bh-select">
                    <option value="">— None (top level) —</option>
                    <?php foreach ($parents as $__p): ?>
                    <option value="<?= (int) $__p['id'] ?>"><?= htmlspecialchars((string) $__p['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div class="bh-termnew__summary" data-term-summary aria-live="polite" hidden></div>
        </div>
        <footer class="bh-dialog__foot">
            <button type="button" class="bh-btn bh-btn--secondary" data-bh-dialog-close>Cancel</button>
            <button type="submit" class="bh-btn bh-btn--primary" data-term-many-add disabled>Add <?= htmlspecialchars($__lcN) ?></button>
        </footer>
    </form>
</dialog>

<script src="<?= $base ?>/admin/assets/js/term-new.js?v=<?= urlencode((string) (@filemtime(BASEHIM_ROOT . '/admin/assets/js/term-new.js') ?: BASEHIM_VERSION)) ?>" defer></script>

<?php $this->endSection(); ?>
