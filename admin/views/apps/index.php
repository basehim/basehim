<?php $this->extend('layouts.app'); ?>
<?php $this->section('content'); ?>
<?php
/**
 * Installed apps (/admin/apps). A compact card grid, matching the CloudHim
 * app's apps list: a stat row, then one card per app with an icon tile, a
 * status pill, its permissions and the actions for its state.
 *
 * All styling is compiled Tailwind utilities; behaviour (the upload panel and
 * the confirm dialogs) lives in admin/assets/js/apps.js — no inline JS or CSS.
 *
 * Render an app's manifest icon. Three forms are understood:
 *   "fa-rocket"             -> Font Awesome <i>
 *   "heroicon:puzzle-piece" -> core Icon.php glyph
 *   "assets/icon.svg"       -> a file bundled inside the app
 * Anything unrecognised falls back to the generic puzzle-piece, so a typo in a
 * manifest can never break the page.
 */
$meta = $meta ?? [];
$party = function (?array $p): string {
    if (!$p || ($p['name'] ?? '') === '') return '';
    $name = htmlspecialchars($p['name']);
    return !empty($p['url'])
        ? '<a href="' . htmlspecialchars($p['url'], ENT_QUOTES) . '" target="_blank" rel="noopener nofollow" class="hover:text-blue-600 hover:underline">' . $name . '</a>'
        : $name;
};
$appIcon = function (array $row) use ($base, $meta): string {
    $m = $meta[(string) ($row['slug'] ?? '')] ?? null;
    if ($m && $m['icon']['type'] === 'image') {
        if (preg_match('#\.(png|gif)$#i', $m['icon']['value'])) {
            return '<img src="' . htmlspecialchars($m['icon']['value'], ENT_QUOTES) . '" alt="" width="44" height="44" class="w-11 h-11 object-cover rounded-xl">';
        }
        return '<img src="' . htmlspecialchars($m['icon']['value'], ENT_QUOTES) . '" alt="" class="w-6 h-6 object-contain">';
    }
    if ($m && $m['icon']['type'] === 'none') {
        return icon('puzzle-piece', 'w-5 h-5');
    }
    $icon = trim((string) ($row['icon'] ?? ''));
    $slug = (string) ($row['slug'] ?? '');

    if ($icon === '') {
        return icon('puzzle-piece', 'w-5 h-5');
    }
    if (preg_match('#\.(svg|png|jpe?g|webp|gif)$#i', $icon)) {
        $rel = ltrim(preg_replace('#^assets/#', '', $icon) ?? $icon, '/');
        $url = $base . '/content/apps/' . rawurlencode($slug) . '/assets/' . $rel;
        return '<img src="' . htmlspecialchars($url, ENT_QUOTES) . '" alt="" class="w-6 h-6 object-contain">';
    }
    if (str_starts_with($icon, 'fa-') || str_starts_with($icon, 'fas ') || str_starts_with($icon, 'far ')) {
        return '<i class="fa ' . htmlspecialchars($icon, ENT_QUOTES) . '" aria-hidden="true"></i>';
    }
    if (str_starts_with($icon, 'heroicon:')) {
        return icon(substr($icon, 9), 'w-5 h-5');
    }
    return icon($icon, 'w-5 h-5');
};

/* Decode one app's permission + scan state (used for stats and per card). */
$appState = function (array $p) use ($available): array {
    $perms = json_decode((string) ($p['permissions'] ?? ''), true);
    $perms = is_array($perms) ? $perms : [];
    $granted = json_decode((string) ($p['granted_permissions'] ?? ''), true);
    $granted = is_array($granted) ? $granted : [];
    $scan = json_decode((string) ($p['scan_result'] ?? ''), true);
    return [
        'active'       => ($p['status'] ?? '') === 'active',
        'filesPresent' => isset($available[$p['slug']]),
        'perms'        => $perms,
        'granted'      => $granted,
        'withheld'     => array_values(array_diff($perms, $granted)),
        'scanHigh'     => is_array($scan) ? (int) ($scan['high'] ?? 0) : 0,
        'awaiting'     => $perms && empty($p['consented_at']),
    ];
};

$installed   = $installed ?? [];
$totalCount  = count($installed);
$activeCount = 0;
$attention   = 0;
foreach ($installed as $p) {
    $s = $appState($p);
    if ($s['active']) $activeCount++;
    if (!$s['filesPresent'] || ($s['awaiting'] && $s['active']) || $s['scanHigh'] > 0) $attention++;
}
$inactiveCount = $totalCount - $activeCount;

/* Compact action buttons, Tailwind utilities only. */
$btnBase    = 'inline-flex items-center gap-1.5 text-xs font-medium px-2.5 py-1.5 rounded-lg border';
$btnNeutral = $btnBase . ' border-slate-200 text-slate-600 hover:border-slate-300 hover:text-slate-900 hover:bg-slate-50';
$btnPrimary = $btnBase . ' bg-blue-600 border-blue-600 text-white hover:bg-blue-700';
$btnDanger  = $btnBase . ' border-slate-200 text-red-600 hover:border-red-300 hover:bg-red-50';
?>

<div class="mb-5 flex items-start justify-between gap-4 flex-wrap">
    <div class="min-w-0">
        <h2 class="text-xl font-semibold text-slate-900">Apps</h2>
        <p class="text-sm text-slate-500">
            <?php if ($totalCount): ?>
                Manage the apps installed on your site — activate, review permissions or remove them.
            <?php else: ?>
                Extend your site with apps — install from the marketplace or upload a <code class="px-1 py-0.5 bg-slate-100 rounded text-xs">.zip</code>.
            <?php endif; ?>
        </p>
    </div>
    <div class="flex items-center gap-2">
        <a href="<?= $base ?>/admin/marketplace" class="bh-btn bh-btn--primary">
            <?= icon('building-storefront', 'w-4 h-4') ?> Browse marketplace
        </a>
        <?php if (!empty($canUpload)): ?>
        <button type="button" class="bh-btn bh-btn--secondary" data-apps-upload-toggle
                aria-controls="app-upload-panel" aria-expanded="false">
            <?= icon('arrow-up-tray', 'w-4 h-4') ?> Upload app
        </button>
        <?php endif; ?>
    </div>
</div>

<?php if ($totalCount): ?>
<div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-5">
    <?php
    $stats = [
        ['Installed', $totalCount, 'puzzle-piece', 'text-slate-500'],
        ['Active', $activeCount, 'bolt', 'text-emerald-600'],
        ['Inactive', $inactiveCount, 'power', 'text-slate-400'],
        ['Need attention', $attention, 'exclamation-triangle', $attention ? 'text-amber-600' : 'text-slate-300'],
    ];
    foreach ($stats as $c): ?>
    <div class="bg-white rounded-xl border border-slate-200 p-4">
        <div class="text-xs text-slate-400 mb-1 flex items-center gap-1.5"><?= icon($c[2], 'w-4 h-4 ' . $c[3]) ?><?= $c[0] ?></div>
        <div class="text-2xl font-bold text-slate-800"><?= (int) $c[1] ?></div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if (!empty($canUpload)): ?>
<div id="app-upload-panel" class="bh-card bh-card--pad mb-5" hidden>
    <form method="POST" action="<?= $base ?>/admin/apps/install" enctype="multipart/form-data"
          class="flex flex-col sm:flex-row sm:items-end gap-3">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
        <div class="flex-1 min-w-0">
            <label class="bh-label" for="app-zip">App archive (.zip)</label>
            <input id="app-zip" type="file" name="app_zip"
                   accept=".zip,application/zip,application/x-zip-compressed" required
                   class="block w-full text-sm text-slate-700 file:mr-3 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-medium file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100">
            <p class="bh-help">Max 16 MB. The archive must contain an <code>app.json</code> manifest. An app already installed is offered as an upgrade.</p>
        </div>
        <button type="submit" class="bh-btn bh-btn--primary whitespace-nowrap">
            <?= icon('cloud-arrow-up', 'w-4 h-4') ?> Install
        </button>
    </form>
</div>
<?php endif; ?>

<?php if (empty($installed)): ?>
    <div class="bh-card">
        <div class="bh-empty">
            <span class="bh-empty__icon"><?= icon('puzzle-piece') ?></span>
            <p class="bh-empty__title">No apps installed yet</p>
            <p class="bh-empty__text">Install an app from the marketplace, or drop an app folder into <code class="px-1.5 py-0.5 bg-slate-100 rounded">/content/apps/</code> and refresh.</p>
            <div class="bh-empty__actions">
                <a href="<?= $base ?>/admin/marketplace" class="bh-btn bh-btn--primary"><?= icon('building-storefront', 'w-4 h-4') ?> Browse marketplace</a>
                <?php if (!empty($canUpload)): ?>
                <button type="button" class="bh-btn bh-btn--secondary" data-apps-upload-toggle aria-controls="app-upload-panel"><?= icon('arrow-up-tray', 'w-4 h-4') ?> Upload a .zip</button>
                <?php endif; ?>
            </div>
        </div>
    </div>
<?php else: ?>
<div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4">
    <?php foreach ($installed as $p): ?>
    <?php
    $s = $appState($p);
    $isActive = $s['active'];
    $filesPresent = $s['filesPresent'];
    $perms = $s['perms'];
    $granted = $s['granted'];
    $withheld = $s['withheld'];
    $scanHigh = $s['scanHigh'];
    $awaiting = $s['awaiting'];
    $m = $meta[$p['slug']] ?? null;

    $who = [];
    if ($m && $m['developer']) $who[] = 'by ' . $party($m['developer']);
    if ($m && $m['company'] && (!$m['developer'] || $m['company']['name'] !== $m['developer']['name'])) $who[] = $party($m['company']);
    $desc = ($m['description'] ?? '') !== '' ? $m['description'] : ($p['description'] ?? '');
    $consentUrl = $base . '/admin/apps/' . urlencode($p['slug']) . '/consent';
    ?>
    <div class="bg-white rounded-xl border border-slate-200 p-4 flex flex-col">
        <!-- Head: icon · name · status -->
        <div class="flex items-start gap-3">
            <div class="w-11 h-11 rounded-xl bg-slate-100 grid place-items-center text-slate-500 overflow-hidden shrink-0">
                <?= $appIcon($p) ?>
            </div>
            <div class="flex-1 min-w-0">
                <div class="flex items-center justify-between gap-2">
                    <h3 class="text-sm font-semibold text-slate-800 truncate"><?= htmlspecialchars($p['name']) ?></h3>
                    <span class="shrink-0 inline-flex items-center gap-1 text-[11px] px-2 py-0.5 rounded-full font-semibold <?= $isActive ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-500' ?>">
                        <?php if ($isActive): ?><span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span><?php endif; ?>
                        <?= $isActive ? 'Active' : 'Inactive' ?>
                    </span>
                </div>
                <div class="text-[11px] text-slate-400 truncate mt-0.5">
                    v<?= htmlspecialchars((string) ($p['version'] ?? '')) ?> · <?= htmlspecialchars((string) ($p['vendor'] ?? '')) ?>/<?= htmlspecialchars((string) ($p['slug'] ?? '')) ?>
                    <?php if ($who): ?> · <?= implode(' · ', $who) ?><?php endif; ?>
                </div>
            </div>
        </div>

        <?php if ($desc !== ''): ?>
        <p class="text-xs text-slate-500 leading-relaxed mt-3 line-clamp-2"><?= htmlspecialchars($desc) ?></p>
        <?php endif; ?>

        <?php if ($m && $m['problems']): ?>
        <details class="mt-3 text-xs text-amber-700">
            <summary class="cursor-pointer font-medium">App details need attention (<?= count($m['problems']) ?>)</summary>
            <ul class="mt-1 list-disc pl-5 space-y-0.5">
                <?php foreach ($m['problems'] as $problem): ?><li><?= htmlspecialchars($problem) ?></li><?php endforeach; ?>
            </ul>
            <p class="mt-1 text-slate-500">For the app's developer: these are fields in its <code>app.json</code>. The app works normally either way.</p>
        </details>
        <?php endif; ?>

        <!-- Permissions -->
        <div class="mt-3 flex flex-wrap items-center gap-1.5">
            <?php if (!$perms): ?>
                <span class="text-[11px] px-2 py-0.5 rounded-full bg-slate-50 text-slate-400 border border-slate-200"
                      title="This app declares no permissions, so Basehim does not restrict its use of the App API.">Unrestricted</span>
            <?php else: ?>
                <?php foreach ($perms as $perm): $isG = in_array($perm, $granted, true); ?>
                    <span class="text-[11px] px-1.5 py-0.5 rounded font-mono <?= $isG ? 'bg-slate-100 text-slate-600' : 'bg-slate-50 text-slate-400 line-through' ?>"
                          title="<?= $isG ? 'Granted' : 'Withheld' ?>"><?= htmlspecialchars((string) $perm) ?></span>
                <?php endforeach; ?>
                <?php if ($awaiting && $isActive): ?>
                    <a href="<?= $consentUrl ?>" class="bh-badge bh-badge--amber"
                       title="This app gained permissions in an update while it was running. They are not enforced until you review them.">Permissions need review</a>
                <?php elseif ($awaiting): ?>
                    <span class="bh-badge bh-badge--amber">Awaiting approval</span>
                <?php elseif ($withheld): ?>
                    <span class="bh-badge bh-badge--gray"><?= count($withheld) ?> withheld</span>
                <?php endif; ?>
            <?php endif; ?>
            <?php if ($scanHigh > 0): ?>
                <a href="<?= $consentUrl ?>" class="bh-badge bh-badge--red">
                    <?= icon('exclamation-triangle', 'w-3 h-3') ?> <?= $scanHigh ?> code flag<?= $scanHigh === 1 ? '' : 's' ?>
                </a>
            <?php endif; ?>
        </div>

        <!-- Actions -->
        <div class="mt-auto pt-3 flex items-center gap-1.5 flex-wrap border-t border-slate-100">
            <?php if ($isActive): ?>
                <form method="POST" action="<?= $base ?>/admin/apps/<?= urlencode($p['slug']) ?>/deactivate">
                    <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
                    <button class="<?= $btnNeutral ?>"><?= icon('power', 'w-4 h-4') ?> Deactivate</button>
                </form>
            <?php elseif ($filesPresent): ?>
                <form method="POST" action="<?= $base ?>/admin/apps/<?= urlencode($p['slug']) ?>/activate">
                    <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
                    <button class="<?= $btnPrimary ?>"><?= icon('bolt', 'w-4 h-4') ?> Activate</button>
                </form>
            <?php else: ?>
                <span class="bh-badge bh-badge--amber"><?= icon('exclamation-triangle', 'w-4 h-4') ?> Files missing</span>
            <?php endif; ?>

            <a href="<?= $base ?>/admin/apps/<?= urlencode($p['slug']) ?>/logs" class="<?= $btnNeutral ?>">
                <?= icon('document-text', 'w-4 h-4') ?> Logs
            </a>
            <?php if ($perms): ?>
            <a href="<?= $consentUrl ?>" class="<?= $btnNeutral ?>">
                <?= icon('shield-check', 'w-4 h-4') ?> Permissions
            </a>
            <?php endif; ?>

            <?php if (!$isActive): ?>
                <span class="ml-auto inline-flex items-center gap-1.5">
                    <?php if (!$filesPresent): ?>
                    <form method="POST" action="<?= $base ?>/admin/apps/<?= urlencode($p['slug']) ?>/uninstall"
                          data-confirm="Remove this app's record from the database? Its files are already gone.">
                        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
                        <button class="<?= $btnNeutral ?>"><?= icon('backspace', 'w-4 h-4') ?> Remove record</button>
                    </form>
                    <?php endif; ?>
                    <form method="POST" action="<?= $base ?>/admin/apps/<?= urlencode($p['slug']) ?>/delete"
                          data-confirm="Permanently delete &ldquo;<?= htmlspecialchars($p['name'], ENT_QUOTES) ?>&rdquo; and all its files? This cannot be undone." data-confirm-danger data-confirm-label="Delete app">
                        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
                        <button class="<?= $btnDanger ?>"><?= icon('trash', 'w-4 h-4') ?> Delete</button>
                    </form>
                </span>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<script src="<?= $base ?>/admin/assets/js/apps.js?v=<?= urlencode((string) (defined('BASEHIM_VERSION') ? BASEHIM_VERSION : '1')) ?>" defer></script>

<?php $this->endSection(); ?>
