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
            <h3 class="font-semibold text-slate-900 mb-5">Discussion Settings</h3>
            <form method="POST" action="<?= $base ?>/admin/settings/discussion" class="space-y-5">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">

                <label class="flex items-start gap-2">
                    <input type="hidden" name="allow_comments" value="0">
                    <input type="checkbox" name="allow_comments" value="1" <?= !empty($values['allow_comments']) ? 'checked' : '' ?>
                        class="mt-0.5 rounded border-slate-300 text-blue-600 focus:ring-blue-500">
                    <div>
                        <div class="text-sm font-medium text-slate-700">Allow comments on new posts</div>
                        <div class="text-xs text-slate-500">Visitors can leave comments on posts and pages.</div>
                    </div>
                </label>

                <label class="flex items-start gap-2">
                    <input type="hidden" name="moderate_first" value="0">
                    <input type="checkbox" name="moderate_first" value="1" <?= !empty($values['moderate_first']) ? 'checked' : '' ?>
                        class="mt-0.5 rounded border-slate-300 text-blue-600 focus:ring-blue-500">
                    <div>
                        <div class="text-sm font-medium text-slate-700">Hold new comments for moderation</div>
                        <div class="text-xs text-slate-500">New comments stay pending until an editor approves them.</div>
                    </div>
                </label>

                <label class="flex items-start gap-2">
                    <input type="hidden" name="require_email" value="0">
                    <input type="checkbox" name="require_email" value="1" <?= !empty($values['require_email']) ? 'checked' : '' ?>
                        class="mt-0.5 rounded border-slate-300 text-blue-600 focus:ring-blue-500">
                    <div>
                        <div class="text-sm font-medium text-slate-700">Require name and email</div>
                    </div>
                </label>

                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Close comments after (days)</label>
                    <input type="number" name="close_after_days" min="0" value="<?= htmlspecialchars($values['close_after_days'] ?? 0) ?>"
                        class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500 outline-none">
                    <p class="text-xs text-slate-500 mt-1">Set to 0 to never close.</p>
                </div>

                <!-- Email notifications -->
                <div class="pt-5 border-t border-slate-100 space-y-4">
                    <h4 class="text-sm font-semibold text-slate-900">Email notifications</h4>
                    <label class="flex items-start gap-2">
                        <input type="hidden" name="notify_new_comment" value="0">
                        <input type="checkbox" name="notify_new_comment" value="1" <?= (!isset($values['notify_new_comment']) || !empty($values['notify_new_comment'])) ? 'checked' : '' ?>
                            class="mt-0.5 rounded border-slate-300 text-blue-600 focus:ring-blue-500">
                        <div>
                            <div class="text-sm font-medium text-slate-700">Email me when a new comment is posted</div>
                            <div class="text-xs text-slate-500">Sent to the admin email (Settings → General) with moderation links.</div>
                        </div>
                    </label>
                    <label class="flex items-start gap-2">
                        <input type="hidden" name="notify_reply" value="0">
                        <input type="checkbox" name="notify_reply" value="1" <?= (!isset($values['notify_reply']) || !empty($values['notify_reply'])) ? 'checked' : '' ?>
                            class="mt-0.5 rounded border-slate-300 text-blue-600 focus:ring-blue-500">
                        <div>
                            <div class="text-sm font-medium text-slate-700">Email commenters when someone replies to them</div>
                            <div class="text-xs text-slate-500">Only sent once the reply is visible (approved).</div>
                        </div>
                    </label>
                </div>

                <!-- Anti-spam -->
                <div class="pt-5 border-t border-slate-100 space-y-4">
                    <h4 class="text-sm font-semibold text-slate-900">Anti-spam</h4>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1.5">Hold if more than N links</label>
                            <input type="number" name="comment_max_links" min="0" value="<?= (int)($values['comment_max_links'] ?? 2) ?>"
                                class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500 outline-none">
                            <p class="text-xs text-slate-500 mt-1">Comments with more links are held for moderation.</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1.5">Minimum seconds between comments</label>
                            <input type="number" name="comment_flood_seconds" min="0" value="<?= (int)($values['comment_flood_seconds'] ?? 15) ?>"
                                class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500 outline-none">
                            <p class="text-xs text-slate-500 mt-1">Flood control per IP address. 0 disables.</p>
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1.5">Moderation words</label>
                        <textarea name="comment_moderation_keys" rows="2" placeholder="One word or phrase per line"
                            class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500 outline-none text-sm"><?= htmlspecialchars((string)($values['comment_moderation_keys'] ?? '')) ?></textarea>
                        <p class="text-xs text-slate-500 mt-1">A comment containing any of these (in content, name, email or URL) is held for moderation.</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1.5">Blocklist</label>
                        <textarea name="comment_blocklist" rows="2" placeholder="One word or phrase per line"
                            class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500 outline-none text-sm"><?= htmlspecialchars((string)($values['comment_blocklist'] ?? '')) ?></textarea>
                        <p class="text-xs text-slate-500 mt-1">A comment matching any of these is sent straight to spam.</p>
                    </div>
                </div>

                <button type="submit" class="px-5 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg shadow-sm">
                    <?= icon('document-check', 'w-4 h-4 mr-1') ?> Save Changes
                </button>
            </form>
        </div>
    </div>
</div>

<?php $this->endSection(); ?>
