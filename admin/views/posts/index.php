<?php $this->extend('layouts.app'); ?>
<?php $this->section('content'); ?>

<?php
$typeLabel = ucfirst($type);
$session = \App\Core\Application::getInstance()->make(\App\Core\Session::class);
$csrf = $session->csrfToken();
?>

<div class="flex items-center justify-between mb-5 flex-wrap gap-3">
    <div>
        <h2 class="text-xl font-semibold text-slate-900"><?= $typeLabel ?>s<?= !empty($trashed) ? ' — Trash' : '' ?></h2>
        <p class="text-sm text-slate-500"><?= !empty($trashed) ? 'Items in the trash can be restored or deleted permanently.' : 'Manage your ' . strtolower($typeLabel) . 's.' ?></p>
    </div>
    <div class="flex items-center gap-2 flex-wrap">
        <div class="inline-flex rounded-lg border border-slate-200 overflow-hidden text-sm">
            <a href="<?= $base ?>/admin/<?= $type ?>s" class="px-3 py-1.5 <?= empty($trashed) ? 'bg-blue-600 text-white' : 'bg-white text-slate-600 hover:bg-slate-50' ?>">All</a>
            <a href="<?= $base ?>/admin/<?= $type ?>s?view=trash" class="px-3 py-1.5 border-l border-slate-200 <?= !empty($trashed) ? 'bg-blue-600 text-white' : 'bg-white text-slate-600 hover:bg-slate-50' ?>">
                <?= icon('trash', 'w-4 h-4 mr-1') ?>Trash<?= ($trashCount ?? 0) > 0 ? ' (' . (int)$trashCount . ')' : '' ?>
            </a>
        </div>
        <?php if (!empty($trashed) && ($trashCount ?? 0) > 0): ?>
        <form method="POST" action="<?= $base ?>/admin/<?= $type ?>s/empty-trash" class="inline"
              onsubmit="return confirm('Permanently delete ALL <?= (int)$trashCount ?> trashed <?= strtolower($typeLabel) ?>(s)? This cannot be undone.')">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
            <button type="submit" class="inline-flex items-center gap-2 px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded-lg text-sm font-medium shadow-sm">
                <?= icon('arrow-uturn-left', 'w-4 h-4') ?> Empty Trash
            </button>
        </form>
        <?php endif; ?>
        <?php if (empty($trashed)): ?>
        <a href="<?= $base ?>/admin/<?= $type ?>s/create" class="inline-flex items-center gap-2 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium shadow-sm">
            <?= icon('plus', 'w-4 h-4') ?> New <?= $typeLabel ?>
        </a>
        <?php endif; ?>
    </div>
</div>

<!-- Filters -->
<div class="bg-white rounded-xl border border-slate-200 p-4 mb-5">
    <form method="GET" class="flex flex-wrap items-center gap-3">
        <?php if (!empty($trashed)): ?><input type="hidden" name="view" value="trash"><?php endif; ?>
        <div class="relative flex-1 min-w-[220px]">
            <span class="absolute inset-y-0 left-0 flex items-center pl-3 text-slate-400">
                <?= icon('magnifying-glass', 'w-4 h-4') ?>
            </span>
            <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search <?= strtolower($typeLabel) ?>s..."
                class="w-full pl-10 pr-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500 outline-none text-sm">
        </div>
        <?php if (empty($trashed)): ?>
        <select name="status" class="px-3 py-2 border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-200 focus:border-blue-500 outline-none">
            <option value="">All statuses</option>
            <?php foreach (['draft', 'published', 'scheduled', 'private'] as $s): ?>
                <option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
            <?php endforeach; ?>
        </select>
        <?php endif; ?>
        <select name="sort" class="px-3 py-2 border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-200 focus:border-blue-500 outline-none">
            <?php foreach (['newest' => 'Newest first', 'oldest' => 'Oldest first', 'title_az' => 'Title A→Z', 'title_za' => 'Title Z→A'] as $sv => $sl): ?>
                <option value="<?= $sv ?>" <?= ($sort ?? 'newest') === $sv ? 'selected' : '' ?>><?= $sl ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-lg text-sm font-medium">
            Filter
        </button>
    </form>
</div>

<!-- Table -->
<div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
    <?php if (empty($posts)): ?>
        <div class="text-center py-16 text-slate-500">
            <?= icon(!empty($trashed) ? 'fa-trash-can' : 'fa-folder-open', 'w-12 h-12 text-slate-300 mb-3') ?>
            <p class="mb-3"><?= !empty($trashed) ? 'Trash is empty.' : 'No ' . strtolower($typeLabel) . 's found.' ?></p>
            <?php if (empty($trashed)): ?>
            <a href="<?= $base ?>/admin/<?= $type ?>s/create" class="inline-flex items-center gap-2 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm">
                <?= icon('plus', 'w-4 h-4') ?> Create one
            </a>
            <?php endif; ?>
        </div>
    <?php else: ?>
    <!-- Bulk actions bar (checkboxes reference this form via the form="" attribute,
         so per-row delete forms stay valid HTML) -->
    <form id="bh-bulk-form" method="POST" action="<?= $base ?>/admin/<?= $type ?>s/bulk"
          class="flex items-center gap-2 px-5 py-3 border-b border-slate-200 bg-slate-50">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
        <select name="bulk_action" id="bh-bulk-action" class="px-3 py-1.5 border border-slate-300 rounded-lg text-xs focus:ring-2 focus:ring-blue-200 focus:border-blue-500 outline-none">
            <option value="">Bulk actions…</option>
            <?php if (!empty($trashed)): ?>
            <option value="restore">Restore</option>
            <option value="delete_forever">Delete Permanently</option>
            <?php else: ?>
            <option value="publish">Publish</option>
            <option value="draft">Move to Draft</option>
            <option value="delete">Move to Trash</option>
            <?php endif; ?>
        </select>
        <button type="submit" class="px-3 py-1.5 bg-slate-700 hover:bg-slate-800 text-white rounded-lg text-xs font-medium">Apply</button>
        <span id="bh-bulk-count" class="text-xs text-slate-400"></span>
    </form>
    <table class="w-full text-sm">
        <thead class="bg-slate-50 border-b border-slate-200">
            <tr>
                <th class="px-5 py-3 w-8"><input type="checkbox" id="bh-bulk-all"></th>
                <th class="text-left px-5 py-3 font-medium text-slate-600">Title</th>
                <th class="text-left px-5 py-3 font-medium text-slate-600 hidden md:table-cell">Author</th>
                <th class="text-left px-5 py-3 font-medium text-slate-600">Status</th>
                <th class="text-left px-5 py-3 font-medium text-slate-600 hidden md:table-cell">Date</th>
                <th class="text-right px-5 py-3 font-medium text-slate-600">Actions</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            <?php foreach ($posts as $post): ?>
            <tr class="hover:bg-slate-50">
                <td class="px-5 py-3">
                    <input type="checkbox" class="bh-bulk-cb" name="ids[]" value="<?= (int)$post['id'] ?>" form="bh-bulk-form">
                </td>
                <td class="px-5 py-3">
                    <?php if (!empty($trashed)): ?>
                    <span class="font-medium text-slate-500"><?= htmlspecialchars($post['title']) ?></span>
                    <?php else: ?>
                    <a href="<?= $base ?>/admin/<?= $type ?>s/<?= $post['id'] ?>/edit" class="font-medium text-slate-900 hover:text-blue-600">
                        <?= htmlspecialchars($post['title']) ?>
                    </a>
                    <?php endif; ?>
                    <div class="text-xs text-slate-500 mt-0.5"><?= htmlspecialchars($post['slug']) ?></div>
                </td>
                <td class="px-5 py-3 text-slate-600 hidden md:table-cell"><?= htmlspecialchars($post['author_name'] ?? 'Unknown') ?></td>
                <td class="px-5 py-3">
                    <span class="text-xs px-2 py-0.5 rounded-full font-medium <?php
                        $st = $post['status'];
                        echo $st === 'published' ? 'bg-green-50 text-green-700' :
                            ($st === 'draft' ? 'bg-slate-100 text-slate-600' :
                            ($st === 'scheduled' ? 'bg-blue-50 text-blue-700' : 'bg-amber-50 text-amber-700'));
                    ?>"><?= ucfirst($post['status']) ?></span>
                </td>
                <td class="px-5 py-3 text-slate-500 text-xs hidden md:table-cell"><?= date('M j, Y', strtotime($post['created_at'])) ?></td>
                <td class="px-5 py-3 text-right">
                    <div class="inline-flex items-center gap-1">
                        <?php if (!empty($trashed)): ?>
                        <form method="POST" action="<?= $base ?>/admin/<?= $type ?>s/<?= $post['id'] ?>/restore" class="inline">
                            <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
                            <button type="submit" class="p-2 text-slate-400 hover:text-emerald-600 hover:bg-emerald-50 rounded" title="Restore">
                                <?= icon('arrow-uturn-left', 'w-4 h-4') ?>
                            </button>
                        </form>
                        <form method="POST" action="<?= $base ?>/admin/<?= $type ?>s/<?= $post['id'] ?>/force-delete" class="inline" onsubmit="return confirm('Permanently delete this <?= strtolower($typeLabel) ?>? This cannot be undone.')">
                            <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
                            <button type="submit" class="p-2 text-slate-400 hover:text-red-600 hover:bg-red-50 rounded" title="Delete permanently">
                                <?= icon('x-mark', 'w-4 h-4') ?>
                            </button>
                        </form>
                        <?php else: ?>
                        <a href="<?= $base ?>/admin/<?= $type ?>s/<?= $post['id'] ?>/edit" class="p-2 text-slate-400 hover:text-blue-600 hover:bg-blue-50 rounded">
                            <?= icon('pencil', 'w-4 h-4') ?>
                        </a>
                        <?php if ($post['status'] === 'published'): ?>
                        <a href="<?= $base ?>/<?= $type === 'post' ? 'posts/' : 'page/' ?><?= htmlspecialchars($post['slug']) ?>" target="_blank" class="p-2 text-slate-400 hover:text-blue-600 hover:bg-blue-50 rounded">
                            <?= icon('eye', 'w-4 h-4') ?>
                        </a>
                        <?php endif; ?>
                        <form method="POST" action="<?= $base ?>/admin/<?= $type ?>s/<?= $post['id'] ?>/delete" class="inline" onsubmit="return confirm('Move this <?= strtolower($typeLabel) ?> to trash?')">
                            <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
                            <button type="submit" class="p-2 text-slate-400 hover:text-red-600 hover:bg-red-50 rounded" title="Move to trash">
                                <?= icon('trash', 'w-4 h-4') ?>
                            </button>
                        </form>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <!-- Pagination -->
    <?php if ($meta['last_page'] > 1): ?>
    <div class="flex items-center justify-between px-5 py-3 border-t border-slate-200 bg-slate-50">
        <span class="text-xs text-slate-500">
            Showing <?= count($posts) ?> of <?= $meta['total'] ?> · Page <?= $meta['page'] ?> of <?= $meta['last_page'] ?>
        </span>
        <div class="flex items-center gap-1">
            <?php if ($meta['page'] > 1): ?>
                <a href="?<?= http_build_query(array_merge($_GET, ['page' => $meta['page'] - 1])) ?>" class="px-3 py-1.5 text-sm border border-slate-300 rounded-lg hover:bg-white">
                    <?= icon('chevron-left', 'w-4 h-4') ?> Prev
                </a>
            <?php endif; ?>
            <?php if ($meta['page'] < $meta['last_page']): ?>
                <a href="?<?= http_build_query(array_merge($_GET, ['page' => $meta['page'] + 1])) ?>" class="px-3 py-1.5 text-sm border border-slate-300 rounded-lg hover:bg-white">
                    Next <?= icon('chevron-right', 'w-4 h-4') ?>
                </a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<script>
(function () {
    var all = document.getElementById('bh-bulk-all');
    var form = document.getElementById('bh-bulk-form');
    if (!form) return;
    var count = document.getElementById('bh-bulk-count');
    function cbs() { return Array.prototype.slice.call(document.querySelectorAll('.bh-bulk-cb')); }
    function refresh() {
        var n = cbs().filter(function (c) { return c.checked; }).length;
        if (count) count.textContent = n ? n + ' selected' : '';
    }
    all && all.addEventListener('change', function () {
        cbs().forEach(function (c) { c.checked = all.checked; });
        refresh();
    });
    document.addEventListener('change', function (ev) {
        if (ev.target.classList && ev.target.classList.contains('bh-bulk-cb')) refresh();
    });
    form.addEventListener('submit', function (ev) {
        var action = document.getElementById('bh-bulk-action').value;
        var n = cbs().filter(function (c) { return c.checked; }).length;
        if (!action || !n) {
            ev.preventDefault();
            alert('Pick a bulk action and select at least one item.');
            return;
        }
        if (action === 'delete' && !confirm('Delete ' + n + ' item(s)? This cannot be undone.')) {
            ev.preventDefault();
        }
    });
})();
</script>

<?php $this->endSection(); ?>
