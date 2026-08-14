<?php $this->extend('layouts.app'); ?>
<?php $this->section('content'); ?>
<?php
function fmtSize($b) {
    $u = ['B','KB','MB','GB'];
    $i = 0;
    while ($b >= 1024 && $i < 3) { $b /= 1024; $i++; }
    return round($b, 1) . ' ' . $u[$i];
}
?>

<div class="nml" data-base="<?= htmlspecialchars($base) ?>" data-csrf="<?= htmlspecialchars($csrf) ?>">

  <div class="nml-head">
    <div>
      <h2 class="nml-title">Media Library</h2>
      <p class="nml-sub" id="nml-count"><?= (int) $meta['total'] ?> file<?= (int)$meta['total'] === 1 ? '' : 's' ?> in your library.</p>
    </div>
    <button type="button" class="nml-upload-btn" id="nml-upload-toggle">
      <?= icon('cloud-arrow-up', 'w-4 h-4') ?> Upload
    </button>
  </div>

  <!-- Upload zone (collapsible) -->
  <div id="upload-zone" class="nm-zone nml-zone is-collapsed">
    <input type="file" id="upload-input" multiple accept="image/*,video/*,audio/*,.pdf,.zip,.svg">
    <div class="nm-zone-icon"><?= icon('cloud-arrow-up', 'w-4 h-4') ?></div>
    <p class="nm-zone-title">Drag and drop files here</p>
    <p class="nm-zone-sub">or <span class="nm-zone-sub-link">click to browse</span></p>
    <p class="nm-zone-hint">
      Max size: <code><?= fmtSize($maxSize) ?></code>
      &middot; Allowed: <code><?= implode(', ', $allowedTypes) ?></code>
    </p>
  </div>
  <div id="upload-progress" class="nml-progress"></div>

  <!-- Toolbar: filter pills + search + sort -->
  <div class="nml-toolbar">
    <div class="nml-pills" id="nml-pills">
      <button class="nml-pill is-active" data-type="all"><?= icon('square-3-stack-3d', 'w-4 h-4') ?> All <span class="nml-pill-count" data-count="all">0</span></button>
      <button class="nml-pill" data-type="image"><?= icon('photo', 'w-4 h-4') ?> Images <span class="nml-pill-count" data-count="image">0</span></button>
      <button class="nml-pill" data-type="video"><?= icon('film', 'w-4 h-4') ?> Video <span class="nml-pill-count" data-count="video">0</span></button>
      <button class="nml-pill" data-type="audio"><?= icon('musical-note', 'w-4 h-4') ?> Audio <span class="nml-pill-count" data-count="audio">0</span></button>
      <button class="nml-pill" data-type="svg"><?= icon('variable', 'w-4 h-4') ?> SVG <span class="nml-pill-count" data-count="svg">0</span></button>
      <button class="nml-pill" data-type="document"><?= icon('document', 'w-4 h-4') ?> Docs <span class="nml-pill-count" data-count="document">0</span></button>
    </div>
    <div class="nml-tools">
      <div class="nml-search">
        <?= icon('magnifying-glass', 'w-4 h-4') ?>
        <input type="text" id="nml-search" placeholder="Search media…" autocomplete="off" spellcheck="false">
        <button type="button" id="nml-search-clear" class="nml-search-clear hidden"><?= icon('x-mark', 'w-4 h-4') ?></button>
      </div>
      <select id="nml-sort" class="nml-select" title="Sort">
        <option value="newest">Newest first</option>
        <option value="oldest">Oldest first</option>
        <option value="name">Name (A-Z)</option>
        <option value="name_desc">Name (Z-A)</option>
        <option value="largest">Largest first</option>
        <option value="smallest">Smallest first</option>
      </select>
      <div class="nml-viewtoggle">
        <button class="nml-vt is-active" data-view="masonry" title="Masonry"><?= icon('table-cells', 'w-4 h-4') ?></button>
        <button class="nml-vt" data-view="grid" title="Uniform grid"><?= icon('bars-2', 'w-4 h-4') ?></button>
        <button class="nml-vt" data-view="list" title="List"><?= icon('list-bullet', 'w-4 h-4') ?></button>
      </div>
      <button class="nml-select-toggle" id="nml-select-toggle" title="Select multiple"><?= icon('check-badge', 'w-4 h-4') ?> Select</button>
    </div>
  </div>

  <!-- Bulk action bar (shown in select mode) -->
  <div class="nml-bulkbar hidden" id="nml-bulkbar">
    <label class="nml-bulk-all"><input type="checkbox" id="nml-select-all"> Select all</label>
    <span class="nml-bulk-count" id="nml-bulk-count">0 selected</span>
    <div class="nml-bulk-actions">
      <button class="nml-bulk-btn nml-bulk-btn--danger" id="nml-bulk-delete"><?= icon('trash', 'w-4 h-4') ?> Delete</button>
      <button class="nml-bulk-btn" id="nml-bulk-cancel">Cancel</button>
    </div>
  </div>

  <!-- Grid (client-rendered) -->
  <div class="nml-body">
    <div id="nml-grid" class="nml-grid nml-grid--masonry" aria-live="polite">
      <div class="nml-loading"><?= icon('arrow-path', 'w-4 h-4 animate-spin') ?> Loading media...</div>
    </div>
    <div id="nml-more-wrap" class="nml-more-wrap hidden">
      <button id="nml-more" class="nml-more">Load more</button>
    </div>
  </div>

  <!-- Detail side pane -->
  <div id="nml-pane" class="nml-pane" aria-hidden="true">
    <div class="nml-pane__head">
      <span class="nml-pane__title">Details</span>
      <button class="nml-pane__close" id="nml-pane-close" title="Close"><?= icon('x-mark', 'w-4 h-4') ?></button>
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
            zone.classList.toggle('is-collapsed');
            if (!zone.classList.contains('is-collapsed') && input) input.focus();
        });
    }
    if (zone && input) {
        zone.addEventListener('click', function (e) { if (e.target !== input) input.click(); });
    }
    if (zone && window.BasehimMedia) {
        BasehimMedia.attachDropzone(zone, {
            progressList: progressList,
            reloadAfter: false,
            onUploaded: function () {
                // Debounced reload so a multi-file upload refreshes once.
                clearTimeout(window.__nmlReloadT);
                window.__nmlReloadT = setTimeout(function () {
                    if (window.BasehimMediaLibrary) window.BasehimMediaLibrary.reload();
                }, 600);
            }
        });
    }
})();
</script>
<script src="<?= htmlspecialchars($base) ?>/admin/assets/js/media-library.js?v=2"></script>
<?php $this->endSection(); ?>
