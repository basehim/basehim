<?php $this->extend('layouts.app'); ?>
<?php $this->section('content'); ?>

<?php
$healthMeta = [
    'good' => ['bg-emerald-100 text-emerald-700', 'check-circle', 'All systems healthy'],
    'warn' => ['bg-amber-100 text-amber-700', 'exclamation-triangle', $overview['warn_count'] . ' warning(s)'],
    'fail' => ['bg-red-100 text-red-700', 'x-circle', $overview['fail_count'] . ' issue(s) need attention'],
];
$hm = $healthMeta[$overview['health']] ?? $healthMeta['warn'];
$dot = ['pass' => 'bg-emerald-400', 'warn' => 'bg-amber-400', 'fail' => 'bg-red-500'];
?>

<div class="flex items-center gap-3 mb-1">
    <h2 class="text-xl font-semibold text-slate-900">System</h2>
    <span class="text-[11px] font-semibold px-2.5 py-1 rounded-full <?= $hm[0] ?>">
        <?= icon($hm[1], 'w-4 h-4 mr-1') ?><?= htmlspecialchars($hm[2]) ?>
    </span>
</div>
<p class="text-sm text-slate-500 mb-5">Diagnostics and maintenance for Basehim <?= htmlspecialchars($overview['basehim_version']) ?>.</p>

<!-- Tab bar -->
<nav class="bh-tabs" id="sys-tabs" aria-label="System sections">
    <button data-tab="overview" class="stab bh-tab" type="button"><?= icon('heart', 'w-4 h-4') ?>Overview</button>
    <button data-tab="php" class="stab bh-tab" type="button"><?= icon('server', 'w-4 h-4') ?>PHP &amp; Server</button>
    <button data-tab="database" class="stab bh-tab" type="button"><?= icon('circle-stack', 'w-4 h-4') ?>Database</button>
    <button data-tab="logs" class="stab bh-tab" type="button"><?= icon('document-text', 'w-4 h-4') ?>Logs</button>
    <button data-tab="cache" class="stab bh-tab" type="button"><?= icon('sparkles', 'w-4 h-4') ?>Cache &amp; Maintenance</button>
</nav>

<!-- ============================ OVERVIEW ============================ -->
<div class="spane" data-pane="overview">
    <div class="grid grid-cols-1 md:grid-cols-4 gap-3 mb-5">
        <?php
        $cards = [
            ['Basehim', $overview['basehim_version'], 'cube'],
            ['PHP', $overview['php_version'], 'code-bracket'],
            ['Server', $overview['server'], 'server'],
            ['OS', $overview['os'], 'computer-desktop'],
        ];
        foreach ($cards as $c): ?>
        <div class="bg-white rounded-xl border border-slate-200 p-4">
            <div class="text-xs text-slate-400 mb-1"><?= icon($c[2], 'w-4 h-4 mr-1') ?><?= htmlspecialchars($c[0]) ?></div>
            <div class="text-sm font-semibold text-slate-800 truncate" title="<?= htmlspecialchars((string)$c[1]) ?>"><?= htmlspecialchars((string)$c[1]) ?></div>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
        <div class="px-5 py-3 border-b border-slate-100"><h3 class="text-sm font-semibold text-slate-900">Health checks</h3></div>
        <div class="divide-y divide-slate-100">
            <?php foreach ($overview['checks'] as $c): ?>
            <div class="flex items-center gap-3 px-5 py-2.5">
                <span class="w-2 h-2 rounded-full shrink-0 <?= $dot[$c['status']] ?? 'bg-slate-300' ?>"></span>
                <span class="text-sm text-slate-700 flex-1"><?= htmlspecialchars($c['label']) ?></span>
                <span class="text-xs text-slate-400"><?= htmlspecialchars($c['detail']) ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- ========================== PHP & SERVER ========================== -->
<div class="spane hidden" data-pane="php">
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
            <div class="px-5 py-3 border-b border-slate-100"><h3 class="text-sm font-semibold text-slate-900">PHP configuration</h3></div>
            <table class="w-full text-sm">
                <?php foreach ($phpInfo as $k => $v): ?>
                <tr class="border-b border-slate-50">
                    <td class="px-5 py-2 text-slate-500 w-1/2"><?= htmlspecialchars($k) ?></td>
                    <td class="px-5 py-2 text-slate-800 font-medium font-mono text-[13px]"><?= htmlspecialchars((string)$v) ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>
        <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
            <div class="px-5 py-3 border-b border-slate-100"><h3 class="text-sm font-semibold text-slate-900">Server</h3></div>
            <table class="w-full text-sm">
                <?php foreach ($serverInfo as $k => $v): ?>
                <tr class="border-b border-slate-50">
                    <td class="px-5 py-2 text-slate-500 w-2/5"><?= htmlspecialchars($k) ?></td>
                    <td class="px-5 py-2 text-slate-800 font-medium font-mono text-[12px] break-all"><?= htmlspecialchars((string)$v) ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>
    </div>

    <div class="bg-white rounded-xl border border-slate-200 overflow-hidden mt-4">
        <div class="px-5 py-3 border-b border-slate-100 flex items-center justify-between">
            <h3 class="text-sm font-semibold text-slate-900">Loaded extensions</h3>
            <span class="text-xs text-slate-400"><?= count($extensions) ?> loaded</span>
        </div>
        <div class="p-4 flex flex-wrap gap-1.5">
            <?php foreach ($extensions as $ext): ?>
                <span class="text-[11px] px-2 py-0.5 rounded bg-slate-100 text-slate-600 font-mono"><?= htmlspecialchars($ext) ?></span>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- ============================ DATABASE ============================ -->
<div class="spane hidden" data-pane="database">
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
        <div class="bg-white rounded-xl border border-slate-200 overflow-hidden lg:col-span-1">
            <div class="px-5 py-3 border-b border-slate-100"><h3 class="text-sm font-semibold text-slate-900">Connection</h3></div>
            <table class="w-full text-sm">
                <?php foreach ($dbInfo as $k => $v): ?>
                <tr class="border-b border-slate-50">
                    <td class="px-5 py-2 text-slate-500"><?= htmlspecialchars($k) ?></td>
                    <td class="px-5 py-2 text-slate-800 font-medium font-mono text-[12px] break-all"><?= htmlspecialchars((string)$v) ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>

        <div class="bg-white rounded-xl border border-slate-200 overflow-hidden lg:col-span-2">
            <div class="px-5 py-3 border-b border-slate-100 flex items-center justify-between">
                <h3 class="text-sm font-semibold text-slate-900">Tables</h3>
                <span class="text-xs text-slate-400"><?= $tableStats['count'] ?> tables · <?= htmlspecialchars($tableStats['total_size']) ?></span>
            </div>
            <div class="max-h-80 overflow-y-auto">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 sticky top-0"><tr>
                        <th class="px-5 py-2 text-left text-xs font-semibold text-slate-500">Table</th>
                        <th class="px-5 py-2 text-right text-xs font-semibold text-slate-500">Rows</th>
                        <th class="px-5 py-2 text-right text-xs font-semibold text-slate-500">Size</th>
                    </tr></thead>
                    <tbody>
                        <?php foreach ($tableStats['tables'] as $t): ?>
                        <tr class="border-b border-slate-50">
                            <td class="px-5 py-2 text-slate-700 font-mono text-[12px]"><?= htmlspecialchars($t['name']) ?></td>
                            <td class="px-5 py-2 text-right text-slate-500"><?= number_format($t['rows']) ?></td>
                            <td class="px-5 py-2 text-right text-slate-500"><?= htmlspecialchars($t['size']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($tableStats['tables'])): ?>
                        <tr><td colspan="3" class="px-5 py-6 text-center text-slate-400 text-sm">No table data available.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="bg-white rounded-xl border border-slate-200 overflow-hidden mt-4">
        <div class="px-5 py-3 border-b border-slate-100 flex items-center justify-between">
            <h3 class="text-sm font-semibold text-slate-900">Migrations</h3>
            <?php if (!empty($migrations['pending'])): ?>
            <form method="POST" action="<?= $base ?>/admin/system/migrate" onsubmit="return confirm('Apply <?= count($migrations['pending']) ?> pending migration(s)? Back up your database first.');">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
                <button class="px-3 py-1.5 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-xs font-medium">
                    <?= icon('play', 'w-4 h-4 mr-1') ?>Run <?= count($migrations['pending']) ?> pending
                </button>
            </form>
            <?php else: ?>
                <span class="text-xs text-emerald-600"><?= icon('check', 'w-4 h-4 mr-1') ?>Up to date</span>
            <?php endif; ?>
        </div>
        <div class="p-4 space-y-1">
            <?php foreach ($migrations['available'] as $m): $key = preg_replace('/\.sql$/', '', $m); $done = in_array($key, $migrations['applied'], true); ?>
            <div class="flex items-center gap-2 text-sm">
                <?= icon($done ? 'check-circle' : 'stop-circle', 'w-3.5 h-3.5 ' . ($done ? 'text-emerald-500' : 'text-amber-400')) ?>
                <span class="font-mono text-[12px] <?= $done ? 'text-slate-600' : 'text-slate-800 font-semibold' ?>"><?= htmlspecialchars($m) ?></span>
                <?php if (!$done): ?><span class="text-[10px] px-1.5 py-0.5 rounded bg-amber-100 text-amber-700">pending</span><?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- ============================== LOGS ============================== -->
<div class="spane hidden" data-pane="logs">
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
        <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
            <div class="px-5 py-3 border-b border-slate-100"><h3 class="text-sm font-semibold text-slate-900">Log files</h3></div>
            <div class="divide-y divide-slate-100 max-h-96 overflow-y-auto" id="log-file-list">
                <?php if (empty($logFiles)): ?>
                    <div class="px-5 py-6 text-center text-slate-400 text-sm">No log files.</div>
                <?php else: foreach ($logFiles as $f): ?>
                <button type="button" class="log-file w-full text-left px-5 py-3 hover:bg-slate-50" data-name="<?= htmlspecialchars($f['name']) ?>">
                    <div class="text-sm font-medium text-slate-700 font-mono text-[12px] truncate"><?= htmlspecialchars($f['name']) ?></div>
                    <div class="text-[11px] text-slate-400 mt-0.5"><?= htmlspecialchars($f['size']) ?> · <?= htmlspecialchars($f['modified']) ?></div>
                </button>
                <?php endforeach; endif; ?>
            </div>
        </div>

        <div class="bg-white rounded-xl border border-slate-200 overflow-hidden lg:col-span-2 flex flex-col">
            <div class="px-5 py-3 border-b border-slate-100 flex items-center justify-between">
                <h3 class="text-sm font-semibold text-slate-900" id="log-title">Select a log file</h3>
                <div class="flex items-center gap-2" id="log-actions" style="display:none">
                    <button id="log-refresh" class="text-xs text-slate-500 hover:text-blue-600"><?= icon('arrow-path', 'w-4 h-4 mr-1') ?>Refresh</button>
                    <form method="POST" action="<?= $base ?>/admin/system/log/delete" id="log-delete-form" onsubmit="return confirm('Delete this log file?');">
                        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
                        <input type="hidden" name="name" id="log-delete-name">
                        <button class="text-xs text-red-500 hover:text-red-700"><?= icon('trash', 'w-4 h-4 mr-1') ?>Delete</button>
                    </form>
                </div>
            </div>
            <pre id="log-content" class="flex-1 overflow-auto p-4 text-[11.5px] leading-relaxed font-mono text-slate-700 bg-slate-50 m-0 max-h-96 whitespace-pre-wrap break-all">Choose a log file from the list to view its most recent entries.</pre>
        </div>
    </div>
</div>

<!-- ====================== CACHE & MAINTENANCE ====================== -->
<div class="spane hidden" data-pane="cache">
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div class="bg-white rounded-xl border border-slate-200 p-5">
            <h3 class="text-sm font-semibold text-slate-900 mb-1">Application cache</h3>
            <p class="text-xs text-slate-500 mb-4">Currently <?= (int)$cacheInfo['app_files'] ?> file(s), <?= htmlspecialchars($cacheInfo['app_size']) ?>. Clearing removes compiled/cached files and resets OPcache <?= $cacheInfo['opcache'] ? '' : '(OPcache reset unavailable on this server)' ?>.</p>
            <form method="POST" action="<?= $base ?>/admin/system/cache/clear">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
                <button class="px-4 py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium">
                    <?= icon('sparkles', 'w-4 h-4 mr-1') ?>Clear Cache<?= $cacheInfo['opcache'] ? ' &amp; Reset OPcache' : '' ?>
                </button>
            </form>
        </div>

        <div class="bg-white rounded-xl border border-slate-200 p-5">
            <h3 class="text-sm font-semibold text-slate-900 mb-1">OPcache status</h3>
            <?php if (!empty($opcache['enabled'])): ?>
            <div class="space-y-2 mt-3 text-sm">
                <div class="flex justify-between"><span class="text-slate-500">Memory used</span><span class="text-slate-800 font-medium"><?= htmlspecialchars($opcache['used']) ?> (<?= $opcache['used_pct'] ?>%)</span></div>
                <div class="w-full bg-slate-100 rounded-full h-2"><div class="bg-blue-500 h-2 rounded-full" style="width:<?= min(100, (float)$opcache['used_pct']) ?>%"></div></div>
                <div class="flex justify-between"><span class="text-slate-500">Cached scripts</span><span class="text-slate-800 font-medium"><?= number_format($opcache['cached_scripts']) ?></span></div>
                <div class="flex justify-between"><span class="text-slate-500">Hit rate</span><span class="text-slate-800 font-medium"><?= $opcache['hit_rate'] ?>%</span></div>
            </div>
            <?php else: ?>
                <p class="text-sm text-slate-400 mt-3"><?= icon('information-circle', 'w-4 h-4 mr-1') ?>OPcache is not enabled on this server.</p>
            <?php endif; ?>
        </div>
    </div>

    <div class="bg-amber-50 border border-amber-200 rounded-xl p-4 mt-4 text-sm text-amber-700">
        <?= icon('light-bulb', 'w-4 h-4 mr-1') ?>
        Tip: after deploying updated PHP files on cPanel, clear the cache here (or from your host's OPcache tool) and hard-refresh (Ctrl+F5) so changes take effect.
    </div>
</div>

<?php $this->endSection(); ?>

<?php $this->section('scripts'); ?>
<script>
(function () {
    var BASE = <?= json_encode($base) ?>;

    // ---- tabs (hash-persisted) ----
    var tabs = document.querySelectorAll('.stab');
    var panes = document.querySelectorAll('.spane');
    function show(name) {
        var found = false;
        panes.forEach(function (p) { var on = p.getAttribute('data-pane') === name; p.classList.toggle('hidden', !on); if (on) found = true; });
        tabs.forEach(function (t) {
            var on = t.getAttribute('data-tab') === name;
            t.classList.toggle('is-active', on);
            if (on) t.setAttribute('aria-current', 'true'); else t.removeAttribute('aria-current');
        });
        return found;
    }
    tabs.forEach(function (t) {
        t.addEventListener('click', function () {
            var name = t.getAttribute('data-tab');
            history.replaceState(null, '', '#' + name);
            show(name);
        });
    });
    var initial = (location.hash || '#overview').slice(1);
    if (!show(initial)) show('overview');

    // ---- logs viewer ----
    var current = null;
    var content = document.getElementById('log-content');
    var title = document.getElementById('log-title');
    var actions = document.getElementById('log-actions');
    var delName = document.getElementById('log-delete-name');

    function loadLog(name) {
        current = name;
        title.textContent = name;
        actions.style.display = '';
        delName.value = name;
        content.textContent = 'Loading…';
        fetch(BASE + '/admin/system/log?name=' + encodeURIComponent(name) + '&lines=400', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d.error) { content.textContent = d.error; return; }
                var lines = d.lines || [];
                content.textContent = lines.length ? lines.join('\n') : '(empty)';
                content.scrollTop = content.scrollHeight;
            })
            .catch(function () { content.textContent = 'Failed to load log.'; });
    }
    document.querySelectorAll('.log-file').forEach(function (b) {
        b.addEventListener('click', function () {
            document.querySelectorAll('.log-file').forEach(function (x) { x.classList.remove('bg-blue-50'); });
            b.classList.add('bg-blue-50');
            loadLog(b.getAttribute('data-name'));
        });
    });
    var refresh = document.getElementById('log-refresh');
    if (refresh) refresh.addEventListener('click', function () { if (current) loadLog(current); });
})();
</script>
<?php $this->endSection(); ?>
