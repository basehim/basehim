<?php $this->extend('layouts.app'); ?>
<?php $this->section('content'); ?>
<?php
/**
 * An app's health report (1.2.71): why it was switched off, its last
 * validation, earlier failures, and the way back. Rendered by core only.
 */
$record = $record ?? null;
$slug = (string) ($app['slug'] ?? '');
$name = (string) (($app['name'] ?? '') ?: $slug);
$state = (string) ($record['state'] ?? 'ok');
$status = (string) ($app['status'] ?? '');
$failure = $record['failure'] ?? null;
$history = (array) ($record['history'] ?? []);
$validation = $record['validation'] ?? null;
$isolation = $isolation ?? null;
$h = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES);

$when = static function (?string $iso): string {
    if (!$iso) return '';
    try {
        return function_exists('bh_datetime') ? bh_datetime($iso) : $iso;
    } catch (\Throwable) {
        return $iso;
    }
};

$stageLabel = static function (string $stage): string {
    return match (true) {
        $stage === 'validation'           => 'Validation (before loading)',
        $stage === 'autoload'             => 'Loading its classes',
        $stage === 'construct'            => 'Creating the app',
        $stage === 'boot'                 => 'Starting (boot)',
        $stage === 'load'                 => 'Loading',
        $stage === 'request'              => 'Serving a page',
        str_starts_with($stage, 'lifecycle:') => 'Running ' . substr($stage, 10) . '()',
        default                           => ucfirst($stage),
    };
};

$categoryLabel = [
    'declaration' => 'Code that PHP cannot load', 'syntax' => 'Syntax error', 'type-error' => 'Type error',
    'dependency' => 'Missing class or function', 'exception' => 'Exception', 'error' => 'PHP error',
    'fatal' => 'Fatal error', 'resource-limit' => 'Memory or time limit', 'manifest' => 'app.json problem',
];

$heading = match (true) {
    $state === 'quarantined' => 'Disabled — initialization error',
    $state === 'retrying'    => 'Being retried',
    $state === 'invalid'     => 'Did not pass validation',
    $state === 'off'         => 'Switched off after an error',
    $status === 'active'     => 'Running',
    default                  => 'Not active',
};
$tone = match (true) {
    in_array($state, ['quarantined', 'invalid'], true) => 'red',
    in_array($state, ['retrying', 'off'], true)        => 'amber',
    $status === 'active'                               => 'green',
    default                                            => 'slate',
};
$toneCls = [
    'red' => 'bg-red-50 border-red-200 text-red-800', 'amber' => 'bg-amber-50 border-amber-200 text-amber-800',
    'green' => 'bg-emerald-50 border-emerald-200 text-emerald-800', 'slate' => 'bg-slate-50 border-slate-200 text-slate-700',
][$tone];
$dot = ['red' => 'bg-red-500', 'amber' => 'bg-amber-500', 'green' => 'bg-emerald-500', 'slate' => 'bg-slate-400'][$tone];

$problemRow = static function (array $p) use ($h): string {
    $loc = trim(($p['file'] ?? '') . (!empty($p['line']) ? ':' . (int) $p['line'] : ''), ':');
    return '<li class="py-2.5 flex gap-3">'
        . '<span class="mt-1.5 w-1.5 h-1.5 rounded-full shrink-0 ' . (($p['severity'] ?? 'error') === 'error' ? 'bg-red-500' : 'bg-amber-400') . '"></span>'
        . '<div class="min-w-0"><div class="text-sm text-slate-800 break-words">' . $h($p['message'] ?? '') . '</div>'
        . ($loc !== '' ? '<div class="mt-0.5 text-xs font-mono text-slate-500 break-all">' . $h($loc) . '</div>' : '')
        . '</div></li>';
};
$canAct = $status !== 'missing';
?>

<div class="mb-5">
    <a href="<?= $base ?>/admin/apps" class="text-sm text-slate-500 hover:text-slate-700">&larr; Apps</a>
    <div class="mt-1 flex items-start justify-between gap-4 flex-wrap">
        <div class="min-w-0">
            <h2 class="text-xl font-semibold text-slate-900"><?= $h($name) ?> — report</h2>
            <p class="text-sm text-slate-500 font-mono"><?= $h($slug) ?><?php if (!empty($record['version'] ?? $app['version'] ?? '')): ?> · v<?= $h($record['version'] ?? $app['version']) ?><?php endif; ?></p>
        </div>
        <a href="<?= $base ?>/admin/apps/<?= urlencode($slug) ?>/logs" class="bh-btn bh-btn--secondary">
            <?= icon('document-text', 'w-4 h-4') ?> App log
        </a>
    </div>
</div>

<?php if ($from !== ''): ?>
<div class="mb-4 px-4 py-3 rounded-lg border bg-blue-50 border-blue-200 text-blue-800 text-sm flex gap-2">
    <?= icon('information-circle', 'w-4 h-4 mt-0.5 shrink-0') ?>
    <span>You opened <code class="font-mono"><?= $h($from) ?></code>, a screen of this app. The app is switched off, so Basehim shows its report instead.</span>
</div>
<?php endif; ?>

<!-- Status -->
<section class="rounded-xl border <?= $toneCls ?> p-4 sm:p-5 mb-5">
    <div class="flex items-start gap-3">
        <span class="mt-1.5 w-2.5 h-2.5 rounded-full shrink-0 <?= $dot ?>"></span>
        <div class="min-w-0 flex-1">
            <h3 class="font-semibold"><?= $h($heading) ?></h3>
            <?php if ($state === 'quarantined'): ?>
                <p class="text-sm mt-1">Basehim switched this app off to protect the rest of the site. Your pages, the admin and your other apps keep working. The app's files, settings and data are untouched.</p>
            <?php elseif ($state === 'retrying'): ?>
                <p class="text-sm mt-1">The app passed validation and was turned back on. If it fails again while starting, it is switched off again and this report is updated.</p>
            <?php elseif ($state === 'invalid'): ?>
                <p class="text-sm mt-1">Its code would stop the site from loading, so it cannot be activated until the problems below are fixed.</p>
            <?php elseif ($state === 'off'): ?>
                <p class="text-sm mt-1">An administrator switched the app off after its failure. The report is kept below.</p>
            <?php elseif (!empty($record['recovered_at'])): ?>
                <p class="text-sm mt-1">Recovered on <?= $h($when($record['recovered_at'])) ?> after an earlier failure.</p>
            <?php endif; ?>
            <?php if ($state === 'quarantined' && empty($record['deactivated'])): ?>
                <p class="text-sm mt-2 font-medium">The database could not be updated when this happened. The app stays switched off through this report; its status in the database will be corrected when the database is reachable.</p>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($canAct && in_array($state, ['quarantined', 'invalid', 'off', 'retrying'], true) || ($canAct && $status !== 'active')): ?>
    <div class="mt-4 pt-4 border-t border-current/10 flex flex-wrap items-center gap-2">
        <form method="POST" action="<?= $base ?>/admin/apps/<?= urlencode($slug) ?>/validate">
            <input type="hidden" name="_csrf" value="<?= $h($csrf) ?>">
            <button class="bh-btn bh-btn--secondary"><?= icon('magnifying-glass', 'w-4 h-4') ?> Check again</button>
        </form>
        <?php if ($status !== 'active'): ?>
        <form method="POST" action="<?= $base ?>/admin/apps/<?= urlencode($slug) ?>/validate">
            <input type="hidden" name="_csrf" value="<?= $h($csrf) ?>">
            <input type="hidden" name="activate" value="1">
            <button class="bh-btn bh-btn--primary"><?= icon('arrow-path', 'w-4 h-4') ?> Check and reactivate</button>
        </form>
        <?php endif; ?>
        <?php if (in_array($status, ['error', 'active'], true) && $state !== 'ok'): ?>
        <form method="POST" action="<?= $base ?>/admin/apps/<?= urlencode($slug) ?>/deactivate" class="ml-auto">
            <input type="hidden" name="_csrf" value="<?= $h($csrf) ?>">
            <button class="bh-btn bh-btn--secondary"><?= icon('power', 'w-4 h-4') ?> Keep it off</button>
        </form>
        <?php endif; ?>
    </div>
    <p class="text-xs mt-2 opacity-80">Fix the app first — install a corrected version, or edit its files — then check again. Reactivating runs the same checks; the app is turned on only if they pass.</p>
    <?php endif; ?>
</section>

<?php if ($failure): ?>
<!-- What happened -->
<section class="bg-white rounded-xl border border-slate-200 mb-5">
    <header class="px-4 sm:px-5 py-3 border-b border-slate-100">
        <h3 class="text-sm font-semibold text-slate-800">What happened</h3>
    </header>
    <div class="p-4 sm:p-5">
        <p class="text-sm text-slate-900 break-words"><?= $h($failure['message'] ?? '') ?></p>
        <dl class="mt-4 grid grid-cols-1 sm:grid-cols-2 text-sm" style="column-gap:1.5rem;row-gap:.875rem">
            <div><dt class="text-xs font-semibold text-slate-500">When</dt><dd class="text-slate-800"><?= $h($when($failure['at'] ?? null)) ?></dd></div>
            <div><dt class="text-xs font-semibold text-slate-500">Stage</dt><dd class="text-slate-800"><?= $h($stageLabel((string) ($failure['stage'] ?? ''))) ?></dd></div>
            <div><dt class="text-xs font-semibold text-slate-500">Kind</dt><dd class="text-slate-800"><?= $h($categoryLabel[$failure['category'] ?? ''] ?? ($failure['category'] ?? '')) ?> <span class="text-slate-400 font-mono text-xs"><?= $h($failure['error_type'] ?? '') ?></span></dd></div>
            <div><dt class="text-xs font-semibold text-slate-500">Where</dt><dd class="text-slate-800 font-mono text-xs break-all"><?= $h(trim(($failure['file'] ?? '') . (!empty($failure['line']) ? ':' . (int) $failure['line'] : ''), ':') ?: '—') ?></dd></div>
            <div><dt class="text-xs font-semibold text-slate-500">Request</dt><dd class="text-slate-800 font-mono text-xs"><?= $h($failure['request_id'] ?? '') ?><?php if (!empty($failure['url'])): ?> · <?= $h($failure['url']) ?><?php endif; ?></dd></div>
            <div><dt class="text-xs font-semibold text-slate-500">Switched off in the database</dt><dd class="text-slate-800"><?= !empty($record['deactivated']) ? 'Yes (status “error”)' : 'Not yet — kept off by this report' ?></dd></div>
        </dl>
        <?php if (!empty($failure['trace'])): ?>
        <details class="mt-4">
            <summary class="text-xs font-medium text-slate-600 cursor-pointer">Call stack (<?= count($failure['trace']) ?> frames)</summary>
            <pre class="mt-2 bg-slate-900 text-slate-100 rounded-lg p-3 text-xs overflow-auto max-h-72"><?= $h(implode("\n", (array) $failure['trace'])) ?></pre>
        </details>
        <?php endif; ?>
    </div>
</section>
<?php endif; ?>

<!-- Validation -->
<section class="bg-white rounded-xl border border-slate-200 mb-5">
    <header class="px-4 sm:px-5 py-3 border-b border-slate-100 flex items-center justify-between gap-3 flex-wrap">
        <h3 class="text-sm font-semibold text-slate-800">Last validation</h3>
        <?php if ($validation): ?>
        <span class="text-xs text-slate-500"><?= $h($when($validation['checked_at'] ?? null)) ?> · <?= (int) ($validation['files'] ?? 0) ?> PHP files · <?= (int) ($validation['duration_ms'] ?? 0) ?> ms</span>
        <?php endif; ?>
    </header>
    <div class="p-4 sm:p-5">
        <?php if (!$validation): ?>
            <p class="text-sm text-slate-500">No full report has been saved for this app yet. <strong>Check again</strong> runs one.</p>
        <?php else: ?>
            <div class="flex flex-wrap gap-2 mb-3">
                <span class="bh-badge <?= !empty($validation['ok']) ? 'bh-badge--green' : 'bh-badge--red' ?>"><?= !empty($validation['ok']) ? 'Passed' : count((array) $validation['errors']) . ' problem' . (count((array) $validation['errors']) === 1 ? '' : 's') ?></span>
                <span class="bh-badge bh-badge--gray">Syntax and declarations checked</span>
                <span class="bh-badge <?= !empty($validation['isolated']) ? 'bh-badge--gray' : 'bh-badge--amber' ?>"><?= !empty($validation['isolated']) ? 'Isolated load test ran' : 'No isolated load test' ?></span>
            </div>
            <?php if (!empty($validation['isolated_note'])): ?>
                <p class="text-xs text-slate-500 mb-3"><?= $h($validation['isolated_note']) ?></p>
            <?php endif; ?>
            <?php if (!empty($validation['errors'])): ?>
                <ul class="divide-y divide-slate-100"><?php foreach ($validation['errors'] as $p) echo $problemRow($p); ?></ul>
            <?php endif; ?>
            <?php if (!empty($validation['warnings'])): ?>
                <details class="mt-3">
                    <summary class="text-xs font-medium text-slate-600 cursor-pointer"><?= count($validation['warnings']) ?> note<?= count($validation['warnings']) === 1 ? '' : 's' ?> (not blocking)</summary>
                    <ul class="divide-y divide-slate-100 mt-1"><?php foreach ($validation['warnings'] as $p) echo $problemRow($p); ?></ul>
                </details>
            <?php endif; ?>
        <?php endif; ?>
        <?php if ($isolation && empty($isolation['available'])): ?>
            <p class="text-xs text-amber-700 mt-3">The isolated load test cannot run on this server (<?= $h($isolation['reason']) ?>). Basehim still checks syntax and declarations, and switches an app off if it fails while loading.</p>
        <?php endif; ?>
    </div>
</section>

<?php if ($history): ?>
<section class="bg-white rounded-xl border border-slate-200 mb-5">
    <header class="px-4 sm:px-5 py-3 border-b border-slate-100">
        <h3 class="text-sm font-semibold text-slate-800">Earlier failures</h3>
    </header>
    <ul class="divide-y divide-slate-100">
        <?php foreach ($history as $f): ?>
        <li class="px-4 sm:px-5 py-3">
            <div class="text-xs text-slate-500"><?= $h($when($f['at'] ?? null)) ?> · <?= $h($stageLabel((string) ($f['stage'] ?? ''))) ?> · <span class="font-mono"><?= $h($f['request_id'] ?? '') ?></span></div>
            <div class="text-sm text-slate-800 break-words mt-0.5"><?= $h($f['message'] ?? '') ?></div>
        </li>
        <?php endforeach; ?>
    </ul>
</section>
<?php endif; ?>

<p class="text-xs text-slate-400">Reports are kept in <code>storage/app-health/</code>. They are visible only to people who can manage apps.</p>

<?php $this->endSection(); ?>
