<?php $this->extend('layouts.app'); ?>
<?php $this->section('content'); ?>
<?php
/**
 * Installed apps (/admin/apps). Rebuilt in 1.2.85 on the core components:
 *
 *  - the page header shared with the Marketplace (.bh-page-head);
 *  - an upload card for an app's .zip (POST /admin/apps/install);
 *  - a note naming any folder in content/apps that isn't an installed app —
 *    copied in by hand or renamed — which is never listed, loaded or
 *    activated (AppService::register());
 *  - the core Data Table `apps.installed` (routes/tables.php): search, status
 *    filter, sorting, row menu and bulk actions;
 *  - the details window (partials/item-modal) for an app's permissions.
 *
 * admin/assets/js/apps.js runs the table's actions over the AJAX gateway
 * (apps.bulk, apps.consent, apps.consent_save). No stylesheet or inline
 * styles of its own: .bh-apps-* and .bh-app-* are in tailwind.css.
 */
$unregistered = $unregistered ?? [];
$noun = count($unregistered) === 1 ? 'folder' : 'folders';
?>
<div class="bh-apps" id="apps-root">

    <header class="bh-page-head">
        <div class="bh-page-head__text">
            <span class="bh-page-head__icon" aria-hidden="true"><?= icon('puzzle-piece', 'w-5 h-5') ?></span>
            <div class="min-w-0">
                <h2 class="bh-page-head__title">Apps</h2>
                <p class="bh-page-head__sub">Turn apps on or off, review what they're allowed to do, or remove them.</p>
            </div>
        </div>
        <div class="bh-page-head__actions">
            <?php if (!empty($canUpload)): ?>
            <button type="button" class="bh-btn bh-btn--secondary" data-apps-upload-toggle aria-controls="app-upload-panel" aria-expanded="false">
                <?= icon('arrow-up-tray', 'w-4 h-4') ?> Upload app
            </button>
            <?php endif; ?>
            <a href="<?= $base ?>/admin/marketplace" class="bh-btn bh-btn--primary">
                <?= icon('building-storefront', 'w-4 h-4') ?> <span class="hidden sm:inline">Browse marketplace</span><span class="sm:hidden">Marketplace</span>
            </a>
        </div>
    </header>

    <?php if (!empty($canUpload)): ?>
    <section id="app-upload-panel" class="bh-apps-upload" aria-label="Upload an app" hidden>
        <form method="POST" action="<?= $base ?>/admin/apps/install" enctype="multipart/form-data" class="bh-apps-upload__form" data-apps-upload>
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
            <label class="bh-apps-upload__drop">
                <?= icon('cloud-arrow-up', '') ?>
                <span class="min-w-0">
                    <span class="bh-apps-upload__name" data-apps-file>Choose the app's .zip file</span>
                    <span class="bh-apps-upload__hint">Up to 16 MB, with an app.json inside. It's scanned and checked before you can turn it on; an app that's already installed is offered as an update.</span>
                </span>
                <input type="file" name="app_zip" accept=".zip,application/zip,application/x-zip-compressed" required>
            </label>
            <button type="submit" class="bh-btn bh-btn--primary"><?= icon('arrow-up-tray', 'w-4 h-4') ?> Install</button>
        </form>
    </section>
    <?php endif; ?>

    <?php if ($unregistered): ?>
    <div class="bh-note bh-apps-note" role="note">
        <?= icon('information-circle', '') ?>
        <span>
            <strong><?= count($unregistered) ?> <?= $noun ?> in content/apps <?= count($unregistered) === 1 ? "isn't an installed app" : "aren't installed apps" ?>:</strong>
            <?= implode(', ', array_map(fn($f) => '<code>' . htmlspecialchars($f) . '</code>', $unregistered)) ?>.
            Apps are installed from a .zip upload or the marketplace, so they're scanned and recorded first; folders copied in by hand or renamed are ignored and never run.
        </span>
    </div>
    <?php endif; ?>

    <?php $this->include('partials.datatable', [
        'tableKey'   => 'apps.installed',
        'bulkEvents' => true,
    ]); ?>
</div>

<?php $this->include('partials.item-modal'); ?>

<script src="<?= $base ?>/admin/assets/js/apps.js?v=<?= urlencode((string) BASEHIM_VERSION) ?>" defer></script>
<?php $this->endSection(); ?>
