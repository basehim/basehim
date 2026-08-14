<?php $this->extend('layouts.app'); ?>
<?php $this->section('content'); ?>
<?php
    $s = $staged;
    $cmp = $s['comparison'] ?? 'unknown';
    $inst = $s['installed'];
    $inc  = $s['incoming'];

    // Badge describing the version relationship.
    $badge = [
        'newer'   => ['Upgrade', 'bg-green-100 text-green-700', 'fa-arrow-up'],
        'same'    => ['Reinstall (same version)', 'bg-slate-100 text-slate-600', 'fa-equals'],
        'older'   => ['Downgrade', 'bg-amber-100 text-amber-700', 'fa-arrow-down'],
        'unknown' => ['Version unknown', 'bg-slate-100 text-slate-600', 'fa-question'],
    ][$cmp] ?? ['Change', 'bg-slate-100 text-slate-600', 'fa-arrow-right'];
?>

<div class="mb-5">
    <a href="<?= $base ?>/admin/apps" class="text-sm text-slate-500 hover:text-slate-700">
        <?= icon('arrow-left', 'w-4 h-4 mr-1') ?> Back to apps
    </a>
    <h2 class="text-xl font-semibold text-slate-900 mt-2">Confirm app upgrade</h2>
    <p class="text-sm text-slate-500">
        <strong><?= htmlspecialchars($inc['name']) ?></strong> is already installed. Review the change below, then confirm to replace the current files.
    </p>
</div>

<?php if ($cmp === 'older'): ?>
<div class="mb-4 flex items-start gap-3 bg-amber-50 border border-amber-200 rounded-lg p-3 text-sm text-amber-800">
    <?= icon('exclamation-triangle', 'w-4 h-4 mt-0.5') ?>
    <div>You are about to install an <strong>older</strong> version than the one currently installed. Downgrades can fail if the app's data was migrated to a newer format.</div>
</div>
<?php elseif ($cmp === 'same'): ?>
<div class="mb-4 flex items-start gap-3 bg-slate-50 border border-slate-200 rounded-lg p-3 text-sm text-slate-600">
    <?= icon('information-circle', 'w-4 h-4 mt-0.5') ?>
    <div>The uploaded archive is the <strong>same version</strong> that's already installed. Continue only if you want to reinstall the files (e.g. to repair a broken install).</div>
</div>
<?php endif; ?>

<div class="bg-white border border-slate-200 rounded-xl overflow-hidden">
    <div class="px-5 py-3 border-b border-slate-100 flex items-center justify-between">
        <div class="font-medium text-slate-800"><?= icon('puzzle-piece', 'w-4 h-4 mr-2 text-slate-400') ?><?= htmlspecialchars($inc['name']) ?></div>
        <span class="text-xs px-2.5 py-1 rounded-full font-medium <?= $badge[1] ?>"><?= icon($badge[2], 'w-4 h-4 mr-1') ?><?= $badge[0] ?></span>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 divide-y sm:divide-y-0 sm:divide-x divide-slate-100">
        <!-- Installed -->
        <div class="p-5">
            <div class="text-xs uppercase tracking-wide text-slate-400 font-semibold mb-2">Currently installed</div>
            <div class="flex items-baseline gap-2">
                <span class="text-2xl font-semibold text-slate-900">v<?= htmlspecialchars($inst['version']) ?></span>
                <span class="text-xs px-2 py-0.5 rounded-full <?= ($inst['status'] ?? '') === 'active' ? 'bg-green-100 text-green-700' : 'bg-slate-100 text-slate-500' ?>">
                    <?= ($inst['status'] ?? '') === 'active' ? 'Active' : 'Inactive' ?>
                </span>
            </div>
            <?php if (!empty($inst['author'])): ?>
                <div class="text-xs text-slate-500 mt-2">by <?= htmlspecialchars($inst['author']) ?></div>
            <?php endif; ?>
            <?php if (!empty($inst['description'])): ?>
                <p class="text-sm text-slate-600 mt-2"><?= htmlspecialchars($inst['description']) ?></p>
            <?php endif; ?>
        </div>

        <!-- Incoming -->
        <div class="p-5 bg-slate-50/50">
            <div class="text-xs uppercase tracking-wide text-slate-400 font-semibold mb-2">Uploaded archive</div>
            <div class="flex items-baseline gap-2">
                <span class="text-2xl font-semibold <?= $cmp === 'newer' ? 'text-green-700' : 'text-slate-900' ?>">v<?= htmlspecialchars($inc['version']) ?></span>
            </div>
            <?php if (!empty($inc['author'])): ?>
                <div class="text-xs text-slate-500 mt-2">by <?= htmlspecialchars($inc['author']) ?></div>
            <?php endif; ?>
            <?php if (!empty($inc['description'])): ?>
                <p class="text-sm text-slate-600 mt-2"><?= htmlspecialchars($inc['description']) ?></p>
            <?php endif; ?>
        </div>
    </div>

    <div class="px-5 py-3 border-t border-slate-100 bg-slate-50 text-xs text-slate-500">
        <?= icon('shield-check', 'w-4 h-4 mr-1') ?>
        The current files are backed up during the swap and restored automatically if anything fails.
        <?php if (($inst['status'] ?? '') === 'active'): ?>
            Because this app is active, its migrations will run and it will be reactivated after the upgrade.
        <?php endif; ?>
    </div>
</div>

<div class="mt-5 flex items-center gap-3">
    <form method="POST" action="<?= $base ?>/admin/apps/upgrade/apply">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="slug" value="<?= htmlspecialchars($s['slug']) ?>">
        <input type="hidden" name="token" value="<?= htmlspecialchars($s['token']) ?>">
        <button type="submit"
                class="px-4 py-2 text-sm rounded-lg font-medium text-white inline-flex items-center gap-2 <?= $cmp === 'older' ? 'bg-amber-600 hover:bg-amber-700' : 'bg-blue-600 hover:bg-blue-700' ?>">
            <?= icon('cloud-arrow-up', 'w-4 h-4') ?>
            <?= $cmp === 'newer' ? 'Upgrade now' : ($cmp === 'older' ? 'Downgrade anyway' : 'Reinstall') ?>
        </button>
    </form>
    <form method="POST" action="<?= $base ?>/admin/apps/upgrade/cancel">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="slug" value="<?= htmlspecialchars($s['slug']) ?>">
        <input type="hidden" name="token" value="<?= htmlspecialchars($s['token']) ?>">
        <button type="submit" class="px-4 py-2 text-sm rounded-lg font-medium text-slate-600 bg-white border border-slate-300 hover:bg-slate-50">
            Cancel
        </button>
    </form>
</div>

<?php $this->endSection(); ?>
