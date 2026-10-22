<?php $this->extend('layouts.app'); ?>
<?php $this->section('content'); ?>
<?php
require_once __DIR__ . '/_ui.php';
$onByDefault = static fn(string $k): bool => !isset($values[$k]) || !empty($values[$k]);
?>
<div class="bh-set">
    <?php $this->include('settings._head', compact('tab', 'base')); ?>

    <form method="POST" action="<?= $base ?>/admin/settings/discussion" class="bh-set__form">
        <input type="hidden" name="_csrf" value="<?= bh_set_e($csrf) ?>">

        <?= bh_set_card_open('Comments', 'Who can comment, and when.') ?>
            <?= bh_set_toggle('allow_comments', !empty($values['allow_comments']), 'Allow comments on new posts', 'Visitors can leave comments on posts and pages.') ?>
            <?= bh_set_toggle('moderate_first', !empty($values['moderate_first']), 'Hold new comments for moderation', 'New comments stay pending until an editor approves them.') ?>
            <?= bh_set_toggle('require_email', !empty($values['require_email']), 'Require name and email') ?>
            <?= bh_set_row('Close comments after', '<div class="flex items-center gap-2"><input type="number" id="set-close-after" name="close_after_days" min="0" class="bh-input max-w-[8rem]" value="' . bh_set_e($values['close_after_days'] ?? 0) . '"><span class="text-sm text-slate-500">days</span></div>', '0 keeps comments open.', ['for' => 'set-close-after']) ?>
        <?= bh_set_card_close() ?>

        <?= bh_set_card_open('Email notifications') ?>
            <?= bh_set_toggle('notify_new_comment', $onByDefault('notify_new_comment'), 'Email me when a new comment is posted', 'Sent to the administration email (Settings › General) with moderation links.') ?>
            <?= bh_set_toggle('notify_reply', $onByDefault('notify_reply'), 'Email commenters when someone replies to them', 'Only sent once the reply is visible (approved).') ?>
        <?= bh_set_card_close() ?>

        <?= bh_set_card_open('Spam protection') ?>
            <?= bh_set_row('Hold comments with more links than', '<input type="number" id="set-max-links" name="comment_max_links" min="0" class="bh-input max-w-[8rem]" value="' . (int) ($values['comment_max_links'] ?? 2) . '">', 'They wait for moderation.', ['for' => 'set-max-links']) ?>
            <?= bh_set_row('Seconds between comments', '<input type="number" id="set-flood" name="comment_flood_seconds" min="0" class="bh-input max-w-[8rem]" value="' . (int) ($values['comment_flood_seconds'] ?? 15) . '">', 'Per IP address. 0 turns this off.', ['for' => 'set-flood']) ?>
            <?= bh_set_row('Moderation words', '<textarea id="set-mod-keys" name="comment_moderation_keys" rows="3" class="bh-textarea" placeholder="One word or phrase per line">' . bh_set_e($values['comment_moderation_keys'] ?? '') . '</textarea>', 'A comment containing any of these (in content, name, email or URL) is held for moderation.', ['for' => 'set-mod-keys']) ?>
            <?= bh_set_row('Blocklist', '<textarea id="set-blocklist" name="comment_blocklist" rows="3" class="bh-textarea" placeholder="One word or phrase per line">' . bh_set_e($values['comment_blocklist'] ?? '') . '</textarea>', 'A comment matching any of these is sent straight to spam.', ['for' => 'set-blocklist']) ?>
        <?= bh_set_card_close() ?>

        <?= bh_set_save() ?>
    </form>
</div>

<?php $this->endSection(); ?>
