<?php $this->extend('layouts.app'); ?>
<?php $this->section('content'); ?>
<?php
$riskStyles = [
    'high'    => 'bg-red-50 text-red-700 border-red-200',
    'medium'  => 'bg-amber-50 text-amber-700 border-amber-200',
    'low'     => 'bg-slate-50 text-slate-600 border-slate-200',
    'unknown' => 'bg-slate-100 text-slate-500 border-slate-200',
];
$scan = $scan ?? null;
$unknown = $unknown ?? [];
?>

<div class="mb-5">
    <a href="<?= $base ?>/admin/apps" class="text-sm text-slate-500 hover:text-slate-700">&larr; Apps</a>
    <h2 class="text-xl font-semibold text-slate-900 mt-1">
        <?= htmlspecialchars($app['name'] ?? $app['slug']) ?> is asking for permission
    </h2>
    <p class="text-sm text-slate-500">
        v<?= htmlspecialchars($app['version'] ?? '?') ?>
        <?php if (!empty($app['author'])): ?> · by <?= htmlspecialchars($app['author']) ?><?php endif; ?>
    </p>
</div>

<!--
  The honest framing matters more than the UI here. An operator who believes
  this is a sandbox will approve things they shouldn't.
-->
<div class="mb-5 rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-700">
    <div class="flex items-start gap-3">
        <?= icon('information-circle', 'w-5 h-5 text-slate-400 shrink-0 mt-0.5') ?>
        <div>
            <p class="font-medium text-slate-900">What approving this does, and what it doesn't.</p>
            <p class="mt-1">
                Basehim checks these permissions when the app uses the core App API. That makes an
                app's intentions visible and its overreach traceable in the logs.
            </p>
            <p class="mt-1">
                It is <strong>not</strong> a sandbox. An app is PHP running in the same process as
                the rest of your site, so a determined one can work around this layer entirely.
                Install apps you have reason to trust; treat this list as a statement of intent,
                not a cage.
            </p>
        </div>
    </div>
</div>

<?php if (!empty($scan) && (($scan['high'] ?? 0) > 0 || ($scan['medium'] ?? 0) > 0)): ?>
<div class="mb-5 rounded-xl border <?= ($scan['high'] ?? 0) > 0 ? 'border-red-200 bg-red-50' : 'border-amber-200 bg-amber-50' ?> p-4">
    <div class="flex items-start gap-3">
        <?= icon('exclamation-triangle', 'w-5 h-5 shrink-0 mt-0.5 ' . (($scan['high'] ?? 0) > 0 ? 'text-red-600' : 'text-amber-600')) ?>
        <div class="text-sm <?= ($scan['high'] ?? 0) > 0 ? 'text-red-900' : 'text-amber-900' ?>">
            <p class="font-medium">
                The code scan flagged
                <?= (int) ($scan['high'] ?? 0) ?> high and
                <?= (int) ($scan['medium'] ?? 0) ?> worth reviewing
                across <?= (int) ($scan['files_scanned'] ?? 0) ?> file(s).
            </p>
            <p class="mt-0.5 opacity-90">
                This is a pattern match over source, not a verdict — several of these have entirely
                proper uses. It is here so a surprise is a decision rather than a discovery.
            </p>
            <ul class="mt-2 space-y-1">
                <?php foreach (array_slice($scan['findings'] ?? [], 0, 12) as $f): ?>
                <li class="flex items-start gap-2">
                    <span class="font-mono text-xs px-1.5 py-0.5 rounded border <?= $f['severity'] === 'high' ? 'bg-red-100 border-red-300' : 'bg-amber-100 border-amber-300' ?>">
                        <?= htmlspecialchars($f['severity']) ?>
                    </span>
                    <span>
                        <strong><?= htmlspecialchars($f['label']) ?></strong>
                        <code class="text-xs opacity-75"><?= htmlspecialchars($f['file']) ?>:<?= (int) $f['line'] ?></code>
                        <br><span class="opacity-90"><?= htmlspecialchars($f['why']) ?></span>
                    </span>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
</div>
<?php endif; ?>

<form method="POST" action="<?= $base ?>/admin/apps/<?= urlencode($app['slug']) ?>/consent">
    <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">

    <div class="bg-white rounded-xl border border-slate-200 divide-y divide-slate-100">
        <?php if (empty($permissions)): ?>
            <div class="p-5 text-sm text-slate-500">
                This app declares no permissions, so there is nothing to approve — it runs
                unrestricted.
            </div>
        <?php else: ?>
            <?php foreach ($permissions as $perm): ?>
            <?php $checked = !$consented || in_array($perm['key'], $granted, true); ?>
            <label class="flex items-start gap-3 p-4 hover:bg-slate-50 cursor-pointer">
                <input type="checkbox"
                       name="permissions[]"
                       value="<?= htmlspecialchars($perm['key']) ?>"
                       <?= $checked ? 'checked' : '' ?>
                       class="mt-1 rounded border-slate-300 text-blue-600 focus:ring-blue-500">
                <span class="flex-1 min-w-0">
                    <span class="flex items-center gap-2 flex-wrap">
                        <span class="font-medium text-slate-900"><?= htmlspecialchars($perm['label']) ?></span>
                        <span class="text-xs px-1.5 py-0.5 rounded border <?= $riskStyles[$perm['risk']] ?? $riskStyles['unknown'] ?>">
                            <?= htmlspecialchars($perm['risk']) ?>
                        </span>
                        <code class="text-xs text-slate-400"><?= htmlspecialchars($perm['key']) ?></code>
                    </span>
                    <span class="block text-sm text-slate-600 mt-0.5"><?= htmlspecialchars($perm['description']) ?></span>
                </span>
            </label>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <?php if (!empty($unknown)): ?>
    <p class="mt-3 text-sm text-amber-700">
        <?= icon('exclamation-triangle', 'w-4 h-4 inline') ?>
        This app declares <?= count($unknown) ?> permission(s) Basehim does not recognise
        (<code><?= htmlspecialchars(implode(', ', $unknown)) ?></code>). They grant nothing —
        most likely a typo in the manifest, or the app expects a newer Basehim.
    </p>
    <?php endif; ?>

    <p class="mt-3 text-sm text-slate-500">
        You can untick anything you would rather not grant. The app will run with less, which may
        mean parts of it stop working — each refusal is written to the app's log, so the cause is
        easy to find.
    </p>

    <div class="mt-5 flex items-center gap-2 flex-wrap">
        <button type="submit" name="activate" value="1"
                class="px-4 py-2 text-sm bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium">
            <?= icon('bolt', 'w-4 h-4 mr-1') ?> Approve &amp; activate
        </button>
        <button type="submit"
                class="px-4 py-2 text-sm border border-slate-300 hover:bg-slate-50 rounded-lg font-medium text-slate-700">
            Save without activating
        </button>
        <a href="<?= $base ?>/admin/apps"
           class="px-4 py-2 text-sm text-slate-500 hover:text-slate-700">Cancel</a>

        <span class="ml-auto flex items-center gap-2">
            <a href="<?= $base ?>/admin/apps/<?= urlencode($app['slug']) ?>/logs"
               class="text-sm text-slate-500 hover:text-slate-700 underline">View logs</a>
        </span>
    </div>
</form>

<form method="POST" action="<?= $base ?>/admin/apps/<?= urlencode($app['slug']) ?>/rescan" class="mt-4">
    <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
    <button class="text-sm text-slate-500 hover:text-slate-700 underline">Re-run the code scan</button>
</form>

<?php $this->endSection(); ?>
