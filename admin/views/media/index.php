<?php $this->extend('layouts.app'); ?>
<?php $this->section('content'); ?>
<?php
/**
 * Media Library (1.2.67).
 *
 * One toolbar — type, date, search, sort — drives both views:
 *   Grid  thumbnails, read a page at a time with Load More (media.list);
 *   List  the shared Data Table (`media.library`), which pages on the server.
 * Details, edit, copy URL and delete live in one dialog for both. Every
 * operation goes through the AJAX gateway (media.list / get / update /
 * delete); uploads use POST /admin/media/upload, which the gateway (JSON only)
 * cannot take.
 */
$fmtSize = static function ($b): string {
    $u = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    while ($b >= 1024 && $i < 3) { $b /= 1024; $i++; }
    return round($b, 1) . ' ' . $u[$i];
};
$__user = $currentUser ?? null;
$__canDeleteOthers = $__user ? \App\Http\Middleware\CheckCapability::userCan($__user, 'delete_media') : false;
$__canEditOthers = $__user ? ($__canDeleteOthers || \App\Http\Middleware\CheckCapability::userCan($__user, 'edit_others_posts')) : false;
?>

<div class="nml" data-base="<?= htmlspecialchars($base) ?>" data-csrf="<?= htmlspecialchars($csrf) ?>"
     data-user-id="<?= (int) ($__user['id'] ?? 0) ?>"
     data-can-delete-others="<?= $__canDeleteOthers ? '1' : '0' ?>"
     data-can-edit-others="<?= $__canEditOthers ? '1' : '0' ?>">

  <div class="nml-head">
    <div>
      <h2 class="nml-title">Media Library</h2>
      <p class="nml-sub" id="nml-count" aria-live="polite"><?= (int) $total ?> file<?= (int) $total === 1 ? '' : 's' ?> in your library.</p>
    </div>
    <button type="button" class="nml-upload-btn" id="nml-upload-toggle" aria-expanded="false" aria-controls="upload-zone">
      <?= icon('cloud-arrow-up', 'w-4 h-4') ?> Upload Media
    </button>
  </div>

  <!-- Upload zone (collapsible) -->
  <div id="upload-zone" class="nm-zone nml-zone is-collapsed" role="button" tabindex="-1" aria-label="Upload files: drop them here or press Enter to browse">
    <input type="file" id="upload-input" multiple accept="<?= htmlspecialchars(implode(',', array_map(static fn($t) => '.' . $t, $allowedTypes))) ?>">
    <div class="nm-zone-icon"><?= icon('cloud-arrow-up', 'w-4 h-4') ?></div>
    <p class="nm-zone-title">Drag and drop files here</p>
    <p class="nm-zone-sub">or <span class="nm-zone-sub-link">click to browse</span></p>
    <p class="nm-zone-hint">
      Max size: <code><?= $fmtSize($maxSize) ?></code>
      &middot; Allowed: <code><?= htmlspecialchars(implode(', ', $allowedTypes)) ?></code>
    </p>
  </div>
  <div id="upload-progress" class="nml-progress"></div>

  <!-- Toolbar: type pills + search + date + sort + view -->
  <div class="nml-toolbar">
    <div class="nml-pills" id="nml-pills" role="group" aria-label="File type">
      <button type="button" class="nml-pill is-active" data-type="all" aria-pressed="true"><?= icon('square-3-stack-3d', 'w-4 h-4') ?> All <span class="nml-pill-count" data-count="all">0</span></button>
      <button type="button" class="nml-pill" data-type="image" aria-pressed="false"><?= icon('photo', 'w-4 h-4') ?> Images <span class="nml-pill-count" data-count="image">0</span></button>
      <button type="button" class="nml-pill" data-type="video" aria-pressed="false"><?= icon('film', 'w-4 h-4') ?> Video <span class="nml-pill-count" data-count="video">0</span></button>
      <button type="button" class="nml-pill" data-type="audio" aria-pressed="false"><?= icon('musical-note', 'w-4 h-4') ?> Audio <span class="nml-pill-count" data-count="audio">0</span></button>
      <button type="button" class="nml-pill" data-type="svg" aria-pressed="false"><?= icon('variable', 'w-4 h-4') ?> SVG <span class="nml-pill-count" data-count="svg">0</span></button>
      <button type="button" class="nml-pill" data-type="document" aria-pressed="false"><?= icon('document', 'w-4 h-4') ?> Docs <span class="nml-pill-count" data-count="document">0</span></button>
    </div>
    <div class="nml-tools">
      <div class="nml-search">
        <?= icon('magnifying-glass', 'w-4 h-4') ?>
        <input type="search" id="nml-search" placeholder="Search media…" aria-label="Search media" autocomplete="off" spellcheck="false">
        <button type="button" id="nml-search-clear" class="nml-search-clear hidden" aria-label="Clear search"><?= icon('x-mark', 'w-4 h-4') ?></button>
      </div>
      <select id="nml-month" class="nml-select" aria-label="Date uploaded">
        <option value="all">All dates</option>
      </select>
      <select id="nml-sort" class="nml-select" aria-label="Sort">
        <option value="newest">Newest first</option>
        <option value="oldest">Oldest first</option>
        <option value="name">Name (A-Z)</option>
        <option value="name_desc">Name (Z-A)</option>
        <option value="largest">Largest first</option>
        <option value="smallest">Smallest first</option>
      </select>
      <div class="nml-viewtoggle" role="group" aria-label="View">
        <button type="button" class="nml-vt is-active" data-view="masonry" title="Grid (natural shapes)" aria-pressed="true"><?= icon('table-cells', 'w-4 h-4') ?><span class="bh-sr-only">Grid, natural shapes</span></button>
        <button type="button" class="nml-vt" data-view="grid" title="Grid (squares)" aria-pressed="false"><?= icon('squares-2x2', 'w-4 h-4') ?><span class="bh-sr-only">Grid, squares</span></button>
        <button type="button" class="nml-vt" data-view="list" title="List" aria-pressed="false"><?= icon('list-bullet', 'w-4 h-4') ?><span class="bh-sr-only">List</span></button>
      </div>
      <button type="button" class="nml-select-toggle" id="nml-select-toggle" title="Select multiple" aria-pressed="false"><?= icon('check-badge', 'w-4 h-4') ?> Select</button>
    </div>
  </div>

  <!-- Grid view -->
  <section class="nml-panel" data-nml-panel="grid" aria-label="Media grid">
    <!-- Bulk action bar (grid select mode) -->
    <div class="nml-bulkbar hidden" id="nml-bulkbar">
      <label class="nml-bulk-all"><input type="checkbox" id="nml-select-all"> Select all shown</label>
      <span class="nml-bulk-count" id="nml-bulk-count" aria-live="polite">0 selected</span>
      <div class="nml-bulk-actions">
        <button type="button" class="nml-bulk-btn nml-bulk-btn--danger" id="nml-bulk-delete"><?= icon('trash', 'w-4 h-4') ?> Delete</button>
        <button type="button" class="nml-bulk-btn" id="nml-bulk-cancel">Cancel</button>
      </div>
    </div>
    <div class="nml-body">
      <div id="nml-grid" class="nml-grid nml-grid--masonry" role="list" aria-busy="true">
        <div class="nml-state" role="status"><span class="nml-spinner" aria-hidden="true"></span><p class="nml-state__title">Loading media…</p></div>
      </div>
      <div id="nml-more-wrap" class="nml-more-wrap" aria-live="polite"></div>
    </div>
  </section>

  <!-- List view: the shared Data Table -->
  <section class="nml-panel nml-list" data-nml-panel="list" aria-label="Media list" hidden>
    <?php $this->include('partials.datatable', [
        'tableKey'      => 'media.library',
        'bulkEndpoint'  => '',
        'bulkEvents'    => true,
        'editBase'      => '',
        'deleteConfirm' => 'Delete {n} file(s) permanently? This cannot be undone.',
    ]); ?>
  </section>

  <!-- Details dialog -->
  <div id="nml-pane" class="nml-pane" role="dialog" aria-modal="true" aria-labelledby="nml-pane-title" aria-hidden="true">
    <div class="nml-pane__head">
      <span class="nml-pane__title" id="nml-pane-title">File details</span>
      <button type="button" class="nml-pane__close" id="nml-pane-close" aria-label="Close"><?= icon('x-mark', 'w-4 h-4') ?></button>
    </div>
    <div class="nml-pane__body" id="nml-pane-body"></div>
  </div>
  <div id="nml-pane-backdrop" class="nml-pane-backdrop hidden"></div>

</div>

<?php $this->endSection(); ?>

<?php $this->section('scripts'); ?>
<script>
(function () {
    var zone = document.getElementById('upload-zone');
    var input = document.getElementById('upload-input');
    var progressList = document.getElementById('upload-progress');
    var toggle = document.getElementById('nml-upload-toggle');

    if (toggle && zone) {
        toggle.addEventListener('click', function () {
            var open = zone.classList.toggle('is-collapsed') === false;
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            zone.tabIndex = open ? 0 : -1;
            if (open) zone.focus();
        });
    }
    if (zone && input) {
        zone.addEventListener('click', function (e) { if (e.target !== input) input.click(); });
        zone.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); input.click(); } });
    }
    if (zone && window.BasehimMedia) {
        BasehimMedia.attachDropzone(zone, {
            progressList: progressList,
            reloadAfter: false,
            onUploaded: function () {
                // Debounced, so a multi-file upload refreshes once.
                clearTimeout(window.__nmlReloadT);
                window.__nmlReloadT = setTimeout(function () {
                    if (window.BasehimMediaLibrary) window.BasehimMediaLibrary.reload();
                }, 600);
            }
        });
    }
})();
</script>
<script src="<?= htmlspecialchars($base) ?>/admin/assets/js/media-library.js?v=<?= htmlspecialchars(defined('BASEHIM_VERSION') ? BASEHIM_VERSION : '2') ?>"></script>
<?php $this->endSection(); ?>
