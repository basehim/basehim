<?php $this->extend('layouts.app'); ?>
<?php $this->section('content'); ?>
<?php
/**
 * Colour a log line by its level. The level is written by AppLogger as the
 * token after the timestamp, so a simple match is enough — and if the format
 * ever changes, lines just render in the default colour rather than breaking.
 */
$lineClass = static function (string $line): string {
    if (str_contains($line, '] ERROR:') || str_contains($line, '] CRITICAL:')) return 'text-red-400';
    if (str_contains($line, '] WARNING:')) return 'text-amber-300';
    if (str_contains($line, '] DEBUG:')) return 'text-slate-500';
    return 'text-slate-300';
};
$dates = $dates ?? [];
$lines = $lines ?? [];
?>

<div class="mb-5 flex items-start justify-between gap-4 flex-wrap">
    <div>
        <a href="<?= $base ?>/admin/apps" class="text-sm text-slate-500 hover:text-slate-700">&larr; Apps</a>
        <h2 class="text-xl font-semibold text-slate-900 mt-1">
            <?= htmlspecialchars($app['name'] ?? $app['slug']) ?> — logs
        </h2>
        <p class="text-sm text-slate-500">
            <code>storage/logs/apps/<?= htmlspecialchars($app['slug']) ?>-<?= htmlspecialchars($date) ?>.log</code>
            · kept for 7 days
        </p>
    </div>

    <?php if (count($dates) > 1): ?>
    <form method="GET" class="flex items-center gap-2">
        <label class="text-sm text-slate-600">Date</label>
        <select name="date" onchange="this.form.submit()"
                class="text-sm border border-slate-300 rounded-lg px-2 py-1.5">
            <?php foreach ($dates as $d): ?>
            <option value="<?= htmlspecialchars($d) ?>" <?= $d === $date ? 'selected' : '' ?>>
                <?= htmlspecialchars($d) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </form>
    <?php endif; ?>
</div>

<?php if (empty($lines)): ?>
    <div class="bg-white rounded-xl border border-slate-200 text-center py-16 text-slate-500">
        <?= icon('document-text', 'w-12 h-12 text-slate-300 mb-3') ?>
        <p>Nothing logged<?= $dates ? ' on ' . htmlspecialchars($date) : ' yet' ?>.</p>
        <p class="text-xs text-slate-400 mt-2">
            Apps write here via <code>$this-&gt;log()</code>, and Basehim records any permission
            it refuses.
        </p>
    </div>
<?php else: ?>
    <div class="bg-slate-900 rounded-xl border border-slate-800 overflow-hidden">
        <div class="px-4 py-2 border-b border-slate-800 flex items-center justify-between">
            <span class="text-xs text-slate-400"><?= count($lines) ?> most recent line(s), oldest first</span>
        </div>
        <pre class="p-4 overflow-x-auto text-xs leading-relaxed font-mono"><?php
            foreach ($lines as $line):
        ?><span class="block <?= $lineClass($line) ?>"><?= htmlspecialchars($line) ?></span><?php
            endforeach;
        ?></pre>
    </div>
<?php endif; ?>

<?php $this->endSection(); ?>
