<?php $this->extend('layouts.app'); ?>
<?php $this->section('content'); ?>

<?php
$typeLabel = ucfirst($type);
$session = \App\Core\Application::getInstance()->make(\App\Core\Session::class);
$csrf = $session->csrfToken();
?>

<div class="flex items-center justify-between mb-5 flex-wrap gap-3">
    <div>
        <h2 class="text-xl font-semibold text-slate-900"><?= $typeLabel ?>s</h2>
        <p class="text-sm text-slate-500">Manage your <?= strtolower($typeLabel) ?>s.</p>
    </div>
    <a href="<?= $base ?>/admin/<?= $type ?>s/create" class="inline-flex items-center gap-2 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium shadow-sm">
        <?= icon('plus', 'w-4 h-4') ?> New <?= $typeLabel ?>
    </a>
</div>

<!-- Filters -->
<div class="bg-white rounded-xl border border-slate-200 p-4 mb-5">
    <form method="GET" class="flex flex-wrap items-center gap-3">
        <div class="relative flex-1 min-w-[220px]">
            <span class="absolute inset-y-0 left-0 flex items-center pl-3 text-slate-400">
                <?= icon('magnifying-glass', 'w-4 h-4') ?>
            </span>
            <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search <?= strtolower($typeLabel) ?>s..."
                class="w-full pl-10 pr-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500 outline-none text-sm">
        </div>
        <select name="status" class="px-3 py-2 border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-200 focus:border-blue-500 outline-none">
            <option value="">All statuses</option>
            <?php foreach (['draft', 'published', 'scheduled', 'private', 'trash'] as $s): ?>
                <option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
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
            <?= icon('folder-open', 'w-12 h-12 text-slate-300 mb-3') ?>
            <p class="mb-3">No <?= strtolower($typeLabel) ?>s found.</p>
            <a href="<?= $base ?>/admin/<?= $type ?>s/create" class="inline-flex items-center gap-2 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm">
                <?= icon('plus', 'w-4 h-4') ?> Create one
            </a>
        </div>
    <?php else: ?>
    <table class="w-full text-sm">
        <thead class="bg-slate-50 border-b border-slate-200">
            <tr>
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
                    <a href="<?= $base ?>/admin/<?= $type ?>s/<?= $post['id'] ?>/edit" class="font-medium text-slate-900 hover:text-blue-600">
                        <?= htmlspecialchars($post['title']) ?>
                    </a>
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
                        <a href="<?= $base ?>/admin/<?= $type ?>s/<?= $post['id'] ?>/edit" class="p-2 text-slate-400 hover:text-blue-600 hover:bg-blue-50 rounded">
                            <?= icon('pencil', 'w-4 h-4') ?>
                        </a>
                        <?php if ($post['status'] === 'published'): ?>
                        <a href="<?= $base ?>/<?= $type === 'post' ? 'posts/' : 'page/' ?><?= htmlspecialchars($post['slug']) ?>" target="_blank" class="p-2 text-slate-400 hover:text-blue-600 hover:bg-blue-50 rounded">
                            <?= icon('eye', 'w-4 h-4') ?>
                        </a>
                        <?php endif; ?>
                        <form method="POST" action="<?= $base ?>/admin/<?= $type ?>s/<?= $post['id'] ?>/delete" class="inline" onsubmit="return confirm('Delete this <?= strtolower($typeLabel) ?>?')">
                            <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
                            <button type="submit" class="p-2 text-slate-400 hover:text-red-600 hover:bg-red-50 rounded">
                                <?= icon('trash', 'w-4 h-4') ?>
                            </button>
                        </form>
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

<?php $this->endSection(); ?>
