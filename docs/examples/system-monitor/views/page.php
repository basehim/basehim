<?php
/** @var array $m readings from App::readings() */
$row = static function (string $label, ?string $value): string {
    return '<div class="flex justify-between gap-4 py-2 border-t border-slate-100 first:border-0">'
        . '<span class="text-slate-500">' . htmlspecialchars($label) . '</span>'
        . '<span class="text-slate-900 font-medium text-right">' . htmlspecialchars($value ?? 'Not available on this host') . '</span></div>';
};
?>
<div class="max-w-2xl">
    <h1 class="text-xl font-semibold text-slate-900 mb-1">System Monitor</h1>
    <p class="text-sm text-slate-500 mb-5">What the server this site runs on is doing right now. Add the <strong>Server</strong> card to your dashboard from Customize → Add widgets.</p>
    <section class="bh-card bh-card--pad text-sm">
        <?= $row('Load (1 / 5 / 15 min)', $m['load'] ? implode(' / ', array_map(fn($v) => number_format($v, 2), $m['load'])) : null) ?>
        <?= $row('CPU cores', $m['cores'] ? (string) $m['cores'] : null) ?>
        <?= $row('Memory used', $m['mem_used_pct'] !== null ? $m['mem_used_pct'] . '%' : null) ?>
        <?= $row('Disk free', $m['disk_free'] !== null ? $m['disk_free'] . ' of ' . $m['disk_total'] . ' (' . $m['disk_free_pct'] . '%)' : null) ?>
        <?= $row('PHP', $m['php']) ?>
        <?= $row('Web server', $m['server']) ?>
        <?= $row('Operating system', $m['os']) ?>
    </section>
</div>
