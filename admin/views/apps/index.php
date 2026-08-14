<?php $this->extend('layouts.app'); ?>
<?php $this->section('content'); ?>
<?php
/**
 * Render an app's manifest icon. Three forms are understood:
 *   "fa-rocket"             -> Font Awesome <i>
 *   "heroicon:puzzle-piece" -> core Icon.php glyph
 *   "assets/icon.svg"       -> a file bundled inside the app
 * Anything unrecognised falls back to the generic puzzle-piece, so a typo in a
 * manifest can never break the page.
 */
$appIcon = function (array $row) use ($base): string {
    $icon = trim((string) ($row['icon'] ?? ''));
    $slug = (string) ($row['slug'] ?? '');

    if ($icon === '') {
        return icon('puzzle-piece', 'w-4 h-4');
    }
    if (preg_match('#\.(svg|png|jpe?g|webp|gif)$#i', $icon)) {
        // Bundled file. The asset route searches both content directories, so
        // this resolves whichever folder the app actually lives in.
        $rel = ltrim(preg_replace('#^assets/#', '', $icon) ?? $icon, '/');
        $url = $base . '/content/apps/' . rawurlencode($slug) . '/assets/' . $rel;
        return '<img src="' . htmlspecialchars($url, ENT_QUOTES) . '" alt="" class="w-5 h-5 object-contain">';
    }
    if (str_starts_with($icon, 'fa-') || str_starts_with($icon, 'fas ') || str_starts_with($icon, 'far ')) {
        return '<i class="fa ' . htmlspecialchars($icon, ENT_QUOTES) . '" aria-hidden="true"></i>';
    }
    if (str_starts_with($icon, 'heroicon:')) {
        return icon(substr($icon, 9), 'w-4 h-4');
    }
    return icon($icon, 'w-4 h-4');
};

?>

<div class="mb-5 flex items-start justify-between gap-4 flex-wrap">
    <div>
        <h2 class="text-xl font-semibold text-slate-900">Apps</h2>
        <p class="text-sm text-slate-500">Extend your site with apps. Upload a <code class="px-1 py-0.5 bg-slate-100 rounded text-xs">.zip</code> or drop a folder into <code class="px-1 py-0.5 bg-slate-100 rounded text-xs">/content/apps/</code>.</p>
    </div>
    <div class="flex items-center gap-2">
        <a href="<?= $base ?>/admin/apps/marketplace" class="px-4 py-2 text-sm bg-gradient-to-r from-teal-600 to-emerald-600 hover:from-teal-700 hover:to-emerald-700 text-white rounded-lg font-medium inline-flex items-center gap-2 shadow-sm">
            <?= icon('building-storefront', 'w-4 h-4') ?> Browse Marketplace
        </a>
        <?php if (!empty($canUpload)): ?>
        <button type="button"
                onclick="document.getElementById('app-upload-panel').classList.toggle('hidden')"
                class="px-3 py-2 text-sm bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium inline-flex items-center gap-2">
            <?= icon('arrow-up-tray', 'w-4 h-4') ?> Upload app
        </button>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($canUpload)): ?>
<div id="app-upload-panel" class="hidden mb-5 bg-white border border-slate-200 rounded-xl p-5">
    <form method="POST"
          action="<?= $base ?>/admin/apps/install"
          enctype="multipart/form-data"
          class="flex flex-col sm:flex-row sm:items-end gap-3">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
        <div class="flex-1">
            <label class="block text-sm font-medium text-slate-700 mb-1">App archive (.zip)</label>
            <input type="file"
                   name="app_zip"
                   accept=".zip,application/zip,application/x-zip-compressed"
                   required
                   class="block w-full text-sm text-slate-700 file:mr-3 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-medium file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100">
            <p class="text-xs text-slate-500 mt-1">Max 16 MB. Archive must contain an <code>app.json</code> manifest (<code>app.json</code> is still accepted).</p>
        </div>
        <button type="submit"
                class="px-4 py-2 text-sm bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium whitespace-nowrap">
            <?= icon('cloud-arrow-up', 'w-4 h-4 mr-1') ?> Install
        </button>
    </form>
</div>
<?php endif; ?>


<?php if (empty($installed)): ?>
    <div class="bg-white rounded-xl border border-slate-200 text-center py-16 text-slate-500">
        <?= icon('x-circle', 'w-12 h-12 text-slate-300 mb-3') ?>
        <p>No apps installed.</p>
        <p class="text-xs text-slate-400 mt-2">
            Upload a ZIP above, or drop an app folder into
            <code class="px-1.5 py-0.5 bg-slate-100 rounded">/content/apps/</code> and refresh.
        </p>
    </div>
<?php else: ?>
<div class="grid grid-cols-1 md:grid-cols-2 gap-4">
    <?php foreach ($installed as $p): ?>
    <div class="bg-white rounded-xl border border-slate-200 p-5 flex flex-col">
        <div class="flex items-start gap-3 mb-3">
            <div class="w-10 h-10 rounded-lg bg-gradient-to-br from-blue-100 to-blue-200 grid place-items-center text-blue-600 overflow-hidden">
                <?= $appIcon($p) ?>
            </div>
            <div class="flex-1 min-w-0">
                <h3 class="font-semibold text-slate-900"><?= htmlspecialchars($p['name']) ?></h3>
                <div class="text-xs text-slate-500">
                    <?= htmlspecialchars($p['vendor']) ?>/<?= htmlspecialchars($p['slug']) ?> · v<?= htmlspecialchars($p['version']) ?>
                </div>
            </div>
            <span class="text-xs px-2 py-0.5 rounded-full font-medium <?= $p['status'] === 'active' ? 'bg-green-50 text-green-700' : 'bg-slate-100 text-slate-500' ?>">
                <?= ucfirst($p['status']) ?>
            </span>
        </div>
        <p class="text-sm text-slate-600 flex-1 mb-3"><?= htmlspecialchars($p['description'] ?? '') ?></p>

        <?php
        $filesPresent = isset($available[$p['slug']]);
        $perms = json_decode((string) ($p['permissions'] ?? ''), true);
        $perms = is_array($perms) ? $perms : [];
        ?>

        <?php
        $granted = json_decode((string) ($p['granted_permissions'] ?? ''), true);
        $granted = is_array($granted) ? $granted : [];
        $withheld = array_values(array_diff($perms, $granted));
        $scan = json_decode((string) ($p['scan_result'] ?? ''), true);
        $scanHigh = is_array($scan) ? (int) ($scan['high'] ?? 0) : 0;
        $awaiting = $perms && empty($p['consented_at']);
        ?>

        <?php if (!$perms): ?>
        <div class="mb-3">
            <span class="text-[11px] px-1.5 py-0.5 rounded bg-slate-100 text-slate-500 border border-slate-200"
                  title="This app declares no permissions, so Basehim does not restrict its use of the App API.">
                Unrestricted
            </span>
        </div>
        <?php else: ?>
        <div class="flex flex-wrap gap-1 mb-3 items-center">
            <?php foreach ($perms as $perm): ?>
                <?php $isGranted = in_array($perm, $granted, true); ?>
                <span class="text-[11px] px-1.5 py-0.5 rounded font-mono <?= $isGranted ? 'bg-slate-100 text-slate-600' : 'bg-slate-50 text-slate-400 line-through' ?>"
                      title="<?= $isGranted ? 'Granted' : 'Withheld' ?>">
                    <?= htmlspecialchars((string) $perm) ?>
                </span>
            <?php endforeach; ?>
            <?php if ($awaiting && ($p['status'] ?? '') === 'active'): ?>
                <a href="<?= $base ?>/admin/apps/<?= urlencode($p['slug']) ?>/consent"
                   class="text-[11px] px-1.5 py-0.5 rounded bg-amber-50 text-amber-700 border border-amber-200"
                   title="This app gained permissions in an update while it was running. They are not being enforced until you review them.">
                    Permissions need review
                </a>
            <?php elseif ($awaiting): ?>
                <span class="text-[11px] px-1.5 py-0.5 rounded bg-amber-50 text-amber-700 border border-amber-200">Awaiting approval</span>
            <?php elseif ($withheld): ?>
                <span class="text-[11px] px-1.5 py-0.5 rounded bg-amber-50 text-amber-700 border border-amber-200"><?= count($withheld) ?> withheld</span>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($scanHigh > 0): ?>
        <div class="mb-3">
            <a href="<?= $base ?>/admin/apps/<?= urlencode($p['slug']) ?>/consent"
               class="text-[11px] px-1.5 py-0.5 rounded bg-red-50 text-red-700 border border-red-200 inline-flex items-center gap-1">
                <?= icon('exclamation-triangle', 'w-3 h-3') ?> <?= $scanHigh ?> code flag<?= $scanHigh === 1 ? '' : 's' ?>
            </a>
        </div>
        <?php endif; ?>

        

        <div class="flex flex-wrap items-center gap-2">
            <?php if ($p['status'] === 'active'): ?>
                <form method="POST" action="<?= $base ?>/admin/apps/<?= urlencode($p['slug']) ?>/deactivate" class="inline">
                    <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
                    <button class="px-3 py-1.5 text-sm border border-slate-300 hover:bg-slate-50 rounded-lg font-medium text-slate-700">
                        <?= icon('power', 'w-4 h-4 mr-1') ?> Deactivate
                    </button>
                </form>
            <?php elseif ($filesPresent): ?>
                <form method="POST" action="<?= $base ?>/admin/apps/<?= urlencode($p['slug']) ?>/activate" class="inline">
                    <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
                    <button class="px-3 py-1.5 text-sm bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium">
                        <?= icon('bolt', 'w-4 h-4 mr-1') ?> Activate
                    </button>
                </form>
            <?php else: ?>
                <span class="px-2 py-1 text-xs rounded-md bg-amber-50 text-amber-700 border border-amber-200">
                    <?= icon('exclamation-triangle', 'w-4 h-4 mr-1') ?> Files missing
                </span>
            <?php endif; ?>

            <?php if ($p['status'] !== 'active'): ?>
                <form method="POST"
                      action="<?= $base ?>/admin/apps/<?= urlencode($p['slug']) ?>/delete"
                      class="inline"
                      onsubmit="return confirm('Permanently delete this app and its files? This cannot be undone.');">
                    <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
                    <button class="px-3 py-1.5 text-sm border border-red-200 hover:bg-red-50 text-red-700 rounded-lg font-medium">
                        <?= icon('trash', 'w-4 h-4 mr-1') ?> Delete
                    </button>
                </form>
                <?php if (!$filesPresent): ?>
                <form method="POST"
                      action="<?= $base ?>/admin/apps/<?= urlencode($p['slug']) ?>/uninstall"
                      class="inline"
                      onsubmit="return confirm('Remove this app from the database?');">
                    <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
                    <button class="px-3 py-1.5 text-sm border border-slate-300 hover:bg-slate-50 rounded-lg font-medium text-slate-700">
                        <?= icon('backspace', 'w-4 h-4 mr-1') ?> Remove record
                    </button>
                </form>
                <?php endif; ?>
            <?php endif; ?>

            <a href="<?= $base ?>/admin/apps/<?= urlencode($p['slug']) ?>/logs"
               class="px-3 py-1.5 text-sm border border-slate-300 hover:bg-slate-50 rounded-lg font-medium text-slate-700">
                <?= icon('document-text', 'w-4 h-4 mr-1') ?> Logs
            </a>
            <?php if ($perms): ?>
            <a href="<?= $base ?>/admin/apps/<?= urlencode($p['slug']) ?>/consent"
               class="px-3 py-1.5 text-sm border border-slate-300 hover:bg-slate-50 rounded-lg font-medium text-slate-700">
                <?= icon('shield-check', 'w-4 h-4 mr-1') ?> Permissions
            </a>
            <?php endif; ?>

            <?php if (!empty($p['author'])): ?>
                <span class="ml-auto text-xs text-slate-400">by <?= htmlspecialchars($p['author']) ?></span>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php $this->endSection(); ?>
