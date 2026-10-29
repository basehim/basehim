<?php $this->extend('layouts.app'); ?>
<?php $this->section('content'); ?>
<?php
/*
 * Settings → Search (1.2.92): BH Search's index and the site's search page.
 * App\Core\Search\SearchManager builds the index; SiteSearch answers /search.
 */
require_once __DIR__ . '/_ui.php';
$e = static fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$site = $site ?? ['types' => ['post', 'page'], 'sources' => [], 'sort' => 'relevance'];
$types = $types ?? ['post' => 'Posts', 'page' => 'Pages'];
$appSources = $appSources ?? [];
$sources = $sources ?? [];
$engineName = $engine ?? 'like';
$fmt = static fn($n) => $n === null ? '–' : number_format((int) $n);
$statusText = static function (array $s) use ($fmt): string {
    if ($s['kind'] !== 'indexed') return 'Searched directly';
    if ($s['ready']) return 'Ready';
    if ($s['total'] !== null && $s['total'] > 0) {
        $pct = min(99, (int) floor(100 * min($s['done'], $s['total']) / max(1, $s['total'])));
        return 'Building: ' . $fmt(min($s['done'], $s['total'])) . ' of ' . $fmt($s['total']) . " ({$pct}%)";
    }
    return $s['started'] ? 'Building' : 'Waiting to build';
};
?>
<div class="bh-set">
    <?php $this->include('settings._head', compact('tab', 'base')); ?>

    <form method="POST" action="<?= $base ?>/admin/settings/search" class="bh-set__form">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">

        <?= bh_set_card_open('Search index', 'One index for everything searchable: posts, pages, media, comments, users and what apps add. Searching it costs what matches, not how much there is, so it stays fast at millions of items.') ?>
            <div class="bh-set-card__block">
                <p class="bh-help mb-3">
                    <?= $engineName === 'fulltext'
                        ? 'Engine: <strong>word index</strong> (the database\'s FULLTEXT search).'
                        : ($engineName === 'like'
                            ? 'Engine: <strong>text match</strong>. It works everywhere and finds parts of words, but reads every item, so it suits small sites. <a class="text-accent-600 underline" href="' . $e($base) . '/admin/settings/reading">Search method</a> under Reading chooses the word index where the database supports it.'
                            : 'Engine: <strong>' . $e($engineName) . '</strong>, provided by an app.') ?>
                    The index fills in the background after an update and stays current on every save. Until a source is ready, its searches read its own table, as before.
                </p>
                <div class="overflow-x-auto border border-slate-200 rounded-lg">
                    <table class="w-full text-sm" data-search-sources>
                        <thead><tr class="text-xs font-semibold text-slate-600 border-b border-slate-200">
                            <th class="text-left py-2 pl-3 pr-3">Source</th>
                            <th class="text-right py-2 pr-3">Indexed</th>
                            <th class="text-left py-2 pr-3">Status</th>
                            <th class="text-right py-2 pr-3"><span class="sr-only">Actions</span></th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($sources as $s): ?>
                            <tr class="border-b border-slate-100" data-source="<?= $e($s['id']) ?>">
                                <td class="py-2 pl-3 pr-3"><?= $e($s['label']) ?><?php if ($s['owner'] !== 'core'): ?><br><span class="text-xs text-slate-500">App: <?= $e($s['owner']) ?></span><?php endif; ?></td>
                                <td class="text-right py-2 pr-3" data-col="indexed"><?= $s['kind'] === 'indexed' ? $e($fmt($s['indexed'])) : '–' ?></td>
                                <td class="py-2 pr-3" data-col="status"><?= $e($statusText($s)) ?><?php if (!empty($s['failed'])): ?><br><span class="text-xs text-slate-500"><?= (int) $s['failed'] ?> to retry</span><?php endif; ?></td>
                                <td class="text-right py-2 pr-3">
                                    <?php if ($s['kind'] === 'indexed'): ?>
                                    <button type="button" class="bh-btn bh-btn--ghost bh-btn--sm" data-search-rebuild="<?= $e($s['id']) ?>">Rebuild</button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="flex flex-wrap items-center gap-3 mt-3">
                    <button type="button" class="bh-btn bh-btn--secondary bh-btn--sm" data-search-build><?= icon('arrow-path', 'w-4 h-4') ?> Build now</button>
                    <p class="bh-help" data-search-note><?= $building ?? false ? 'Building in the background. Build now finishes it while this page is open.' : 'Everything is indexed.' ?></p>
                </div>
            </div>
        <?= bh_set_card_close() ?>

        <?= bh_set_card_open('Site search', 'What the search page (/search) and the search API find, and in what order.') ?>
            <?php foreach ($types as $slug => $label): ?>
                <?= bh_set_toggle('site_type[' . $slug . ']', in_array($slug, $site['types'], true), (string) $label, $slug === 'post' ? 'Published posts.' : ($slug === 'page' ? 'Published pages.' : 'Published items of this content type.'), ['id' => 'set-site-type-' . $slug]) ?>
            <?php endforeach; ?>
            <?php foreach ($appSources as $id => $label): ?>
                <?= bh_set_toggle('site_source[' . $id . ']', in_array($id, $site['sources'], true), (string) $label, 'Added by an app.', ['id' => 'set-site-source-' . preg_replace('/[^a-z0-9]+/', '-', $id)]) ?>
            <?php endforeach; ?>
            <?= bh_set_row('Order of results', '<select id="site_sort" name="site_sort" class="bh-select max-w-xs">' . bh_set_options(['relevance' => 'Best match first', 'newest' => 'Newest first'], $site['sort']) . '</select>',
                'Best match ranks titles above text and finds word forms too: "pricing" also finds "price".', ['for' => 'site_sort']) ?>
        <?= bh_set_card_close() ?>

        <?= bh_set_card_open('How searching works', 'The same everywhere: the search page, every list in the admin, the media library and apps.') ?>
            <div class="bh-set-card__block">
                <ul class="text-sm space-y-1">
                    <li><code>digitalocean pricing</code>: every word must match, in any order.</li>
                    <li><code>"per-second billing"</code>: the words together, in this order.</li>
                    <li><code>pricing -hetzner</code>: leave out anything with "hetzner".</li>
                    <li>Hyphens, underscores and dots split words, so <code>snipaste 2026</code> finds <code>Snipaste_2026-10-27.png</code>. Capitals and accents don't matter.</li>
                </ul>
            </div>
        <?= bh_set_card_close() ?>

        <?= bh_set_save() ?>
    </form>
</div>
<script>
(function () {
  var table = document.querySelector('[data-search-sources]');
  var buildBtn = document.querySelector('[data-search-build]');
  var note = document.querySelector('[data-search-note]');
  if (!table || !buildBtn || !window.Basehim || !Basehim.ajax) return;
  function fmt(n) { return n === null || n === undefined ? '–' : Number(n).toLocaleString(); }
  function statusText(s) {
    if (s.kind !== 'indexed') return 'Searched directly';
    if (s.ready) return 'Ready';
    if (s.total) { var d = Math.min(s.done, s.total); return 'Building: ' + fmt(d) + ' of ' + fmt(s.total) + ' (' + Math.min(99, Math.floor(100 * d / Math.max(1, s.total))) + '%)'; }
    return s.started ? 'Building' : 'Waiting to build';
  }
  function show(data) {
    (data.sources || []).forEach(function (s) {
      var row = table.querySelector('tr[data-source="' + (window.CSS && CSS.escape ? CSS.escape(s.id) : s.id) + '"]');
      if (!row) return;
      row.querySelector('[data-col="indexed"]').textContent = s.kind === 'indexed' ? fmt(s.indexed) : '–';
      row.querySelector('[data-col="status"]').textContent = statusText(s);
    });
    note.textContent = data.building ? 'Building… keep this page open, or leave it: the rest is done in the background.' : 'Everything is indexed.';
  }
  var running = false;
  function step(loader) {
    return Basehim.ajax.request('search.build', {}, { method: 'POST' }).then(function (data) {
      show(data);
      if (data.building && running) return step(loader);
      running = false;
      if (loader) loader.done('Indexed');
    });
  }
  buildBtn.addEventListener('click', function () {
    if (running) return;
    running = true;
    var l = window.bhLoader ? bhLoader(buildBtn, 'Building…') : null;
    step(l).catch(function (err) {
      running = false;
      if (l) l.fail('Not finished');
      if (window.bhToast) bhToast((err && err.message) || 'Building the index failed. Try again.', 'error');
    });
  });
  table.addEventListener('click', function (ev) {
    var btn = ev.target.closest('[data-search-rebuild]');
    if (!btn) return;
    var id = btn.getAttribute('data-search-rebuild');
    var go = function () {
      var l = window.bhLoader ? bhLoader(btn, 'Starting…') : null;
      Basehim.ajax.request('search.rebuild', { source: id }, { method: 'POST' }).then(function (data) {
        show(data); if (l) l.done('Started');
      }).catch(function (err) {
        if (l) l.fail('Not started');
        if (window.bhToast) bhToast((err && err.message) || 'Could not start the rebuild.', 'error');
      });
    };
    if (window.bhConfirm) {
      bhConfirm('Index this source again from the start? Its searches read its own table until the new index is ready.', { confirmLabel: 'Rebuild' })
        .then(function (ok) { if (ok) go(); });
    } else { go(); }
  });
})();
</script>

<?php $this->endSection(); ?>
