<?php $this->extend('layouts.app'); ?>
<?php $this->section('content'); ?>
<?php
require_once __DIR__ . '/_ui.php';
$df = $values['default_format'] ?? 'html';
$ds = $values['default_status'] ?? 'draft';
// Categories for the default-category picker.
$bhCats = [];
try {
    $bhCats = \App\Core\Application::getInstance()->make(\App\Core\Database::class)->select(
        "SELECT t.id, t.name FROM {terms} t JOIN {taxonomies} x ON x.id = t.taxonomy_id AND x.slug = 'category' ORDER BY t.name"
    );
} catch (\Throwable) {}
$bhDefaultCat = (int) ($values['default_category'] ?? 0);
if ($bhDefaultCat <= 0) {
    foreach ($bhCats as $bhc) { if (strcasecmp((string) $bhc['name'], 'Uncategorized') === 0) { $bhDefaultCat = (int) $bhc['id']; break; } }
}
$catOptions = [];
foreach ($bhCats as $bhc) $catOptions[(int) $bhc['id']] = (string) $bhc['name'];
?>
<div class="bh-set">
    <?php $this->include('settings._head', compact('tab', 'base')); ?>

    <form method="POST" action="<?= $base ?>/admin/settings/writing" class="bh-set__form">
        <input type="hidden" name="_csrf" value="<?= bh_set_e($csrf) ?>">

        <?= bh_set_card_open('New posts', 'What a new post starts with.') ?>
            <?= bh_set_row('Content format', '<select id="set-default-format" name="default_format" class="bh-select">' . bh_set_options(['html' => 'HTML', 'markdown' => 'Markdown', 'blocks' => 'Blocks'], $df) . '</select>', '', ['for' => 'set-default-format']) ?>
            <?= bh_set_row('Status', '<select id="set-default-status" name="default_status" class="bh-select">' . bh_set_options(['draft' => 'Draft', 'published' => 'Published', 'pending' => 'Pending review'], $ds) . '</select>', '', ['for' => 'set-default-status']) ?>
            <?= bh_set_row('Category', '<select id="set-default-category" name="default_category" class="bh-select">' . bh_set_options($catOptions, $bhDefaultCat) . '</select>', 'A post saved without a category is filed here, so it always has a category address.', ['for' => 'set-default-category']) ?>
        <?= bh_set_card_close() ?>

        <?= bh_set_card_open('Editor') ?>
            <?= bh_set_row('Auto-save every', '<div class="flex items-center gap-2"><input type="number" id="set-autosave" name="autosave_interval" min="0" class="bh-input max-w-[8rem]" value="' . bh_set_e($values['autosave_interval'] ?? 60) . '"><span class="text-sm text-slate-500">seconds</span></div>', '0 turns auto-save off.', ['for' => 'set-autosave']) ?>
        <?= bh_set_card_close() ?>

        <?= bh_set_save() ?>
    </form>
</div>

<?php $this->endSection(); ?>
