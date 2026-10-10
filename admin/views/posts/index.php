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
            <button type="submit" class="bh-btn bh-btn--danger">
                <?= icon('trash', 'w-4 h-4') ?> Empty trash
            </button>
        </form>
        <?php endif; ?>
        <?php if (empty($trashed)): ?>
        <a href="<?= $base ?>/admin/<?= $type ?>s/create" class="bh-btn bh-btn--primary">
            <?= icon('plus', 'w-4 h-4') ?> New <?= $typeLabel ?>
        </a>
        <?php endif; ?>
    </div>
</div>

<!-- Filters -->
<div class="bh-card bh-card--pad mb-5">
    <form method="GET" class="flex flex-wrap items-center gap-3">
        <?php if (!empty($trashed)): ?><input type="hidden" name="view" value="trash"><?php endif; ?>
        <div class="relative flex-1 min-w-[220px]">
            <span class="absolute inset-y-0 left-0 flex items-center pl-3 text-slate-400">
                <?= icon('magnifying-glass', 'w-4 h-4') ?>
            </span>
            <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search <?= strtolower($typeLabel) ?>s..."
                class="bh-input" style="padding-left:2.5rem">
        </div>
        <?php if (empty($trashed)): ?>
        <select name="status" class="bh-select bh-select--auto">
            <option value="">All statuses</option>
            <?php foreach (['draft', 'published', 'scheduled', 'private'] as $s): ?>
                <option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
            <?php endforeach; ?>
        </select>
        <?php endif; ?>
        <select name="sort" class="bh-select bh-select--auto">
            <?php foreach (['newest' => 'Newest first', 'oldest' => 'Oldest first', 'title_az' => 'Title A→Z', 'title_za' => 'Title Z→A'] as $sv => $sl): ?>
                <option value="<?= $sv ?>" <?= ($sort ?? 'newest') === $sv ? 'selected' : '' ?>><?= $sl ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="bh-btn bh-btn--secondary">
            Filter
        </button>
    </form>
</div>

<!-- Table -->
<div class="bh-card overflow-hidden">
    <?php if (empty($posts)): ?>
        <?php
            $__noun = strtolower($typeLabel);
            $__filtered = !empty($search) || !empty($status);
            $__clear = $base . '/admin/' . $type . 's' . (!empty($trashed) ? '?view=trash' : '');
        ?>
        <div class="bh-empty">
            <span class="bh-empty__icon"><?= icon(!empty($trashed) ? 'trash' : ($__filtered ? 'magnifying-glass' : 'document-text')) ?></span>
            <?php if ($__filtered): ?>
            <p class="bh-empty__title">No <?= $__noun ?>s match</p>
            <p class="bh-empty__text">Try a different search<?= empty($trashed) ? ' or status' : '' ?>.</p>
            <div class="bh-empty__actions"><a href="<?= $__clear ?>" class="bh-btn bh-btn--secondary">Clear filters</a></div>
            <?php elseif (!empty($trashed)): ?>
            <p class="bh-empty__title">Trash is empty</p>
            <p class="bh-empty__text"><?= ucfirst($__noun) ?>s you move to the trash appear here, until you restore or delete them.</p>
            <?php else: ?>
            <p class="bh-empty__title">No <?= $__noun ?>s yet</p>
            <p class="bh-empty__text"><?= $__noun === 'page' ? 'Pages hold content that stays put, like About or Contact.' : 'Write your first post to get started.' ?></p>
            <div class="bh-empty__actions"><a href="<?= $base ?>/admin/<?= $type ?>s/create" class="bh-btn bh-btn--primary"><?= icon('plus', 'w-4 h-4') ?> New <?= $__noun ?></a></div>
            <?php endif; ?>
        </div>
    <?php else: ?>
    <!-- Bulk actions bar (checkboxes reference this form via the form="" attribute,
         so per-row delete forms stay valid HTML) -->
    <form id="bh-bulk-form" method="POST" action="<?= $base ?>/admin/<?= $type ?>s/bulk"
          class="flex items-center gap-2 px-5 py-3 border-b border-slate-200 bg-slate-50">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
        <select name="bulk_action" id="bh-bulk-action" class="bh-select bh-select--auto bh-select--sm">
            <option value="">Bulk actions…</option>
            <?php if (!empty($trashed)): ?>
            <option value="restore">Restore</option>
            <option value="delete_forever">Delete permanently</option>
            <?php else: ?>
            <option value="publish">Publish</option>
            <option value="draft">Move to draft</option>
            <option value="delete">Move to trash</option>
            <?php endif; ?>
        </select>
        <button type="submit" class="bh-btn bh-btn--secondary bh-btn--sm">Apply</button>
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
                    <span class="bh-badge <?php
                        $st = $post['status'];
                        echo $st === 'published' ? 'bh-badge--green' :
                            ($st === 'draft' ? 'bh-badge--gray' :
                            ($st === 'scheduled' ? 'bh-badge--blue' : 'bh-badge--amber'));
                    ?>"><?= ucfirst($post['status']) ?></span>
                </td>
                <td class="px-5 py-3 text-slate-500 text-xs hidden md:table-cell"><?= bh_date($post['created_at'], 'M j, Y') ?></td>
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
                        <a href="<?= htmlspecialchars(\App\Core\Helpers::postUrl($post, (string) $base)) ?>" target="_blank" class="p-2 text-slate-400 hover:text-blue-600 hover:bg-blue-50 rounded">
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
        // Hold the submit, ask in the admin's dialog, then submit again with
        // the same button (the second pass goes straight through).
        if (action === 'delete' && !form._bhOk) {
            ev.preventDefault();
            var by = ev.submitter;
            bhConfirm('Delete ' + n + ' item(s)? This cannot be undone.').then(function (ok) {
                if (!ok) return;
                form._bhOk = true;
                if (typeof form.requestSubmit === 'function') form.requestSubmit(by && by.form === form ? by : undefined); else form.submit();
            });
            return;
        }
        form._bhOk = false;
    });
})();
</script>

<?php $this->endSection(); ?>
