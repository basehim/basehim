<?php $this->extend('layouts.app'); ?>
<?php $this->section('content'); ?>

<div class="mb-5">
    <h2 class="text-xl font-semibold text-slate-900">Comments</h2>
    <p class="text-sm text-slate-500">Moderate user comments.</p>
</div>

<!-- Status tabs -->
<div class="mb-4 -mx-4 px-4 sm:mx-0 sm:px-0 overflow-x-auto" style="scrollbar-width:none;">
<div class="flex items-center gap-1 bg-white border border-slate-200 rounded-xl p-1 w-max max-w-none">
    <?php
    $tabs = [
        '' => ['label' => 'All', 'icon' => 'list-bullet', 'count' => $counts['total'] ?? 0],
        'pending' => ['label' => 'Pending', 'icon' => 'clock', 'count' => $counts['pending'] ?? 0],
        'approved' => ['label' => 'Approved', 'icon' => 'check', 'count' => $counts['approved'] ?? 0],
        'spam' => ['label' => 'Spam', 'icon' => 'no-symbol', 'count' => $counts['spam'] ?? 0],
        'trash' => ['label' => 'Trash', 'icon' => 'trash', 'count' => $counts['trash'] ?? 0],
    ];
    foreach ($tabs as $val => $tab):
        $active = $status === $val;
    ?>
    <a href="?status=<?= $val ?>" class="px-3 py-1.5 rounded-lg text-sm font-medium flex items-center gap-2 whitespace-nowrap flex-shrink-0 <?= $active ? 'bg-blue-50 text-blue-700' : 'text-slate-600 hover:bg-slate-50' ?>">
        <?= icon($tab['icon'], 'w-3.5 h-3.5') ?>
        <?= $tab['label'] ?>
        <span class="text-xs px-1.5 py-0.5 rounded-full <?= $active ? 'bg-blue-200 text-blue-800' : 'bg-slate-100 text-slate-500' ?>"><?= $tab['count'] ?></span>
    </a>
    <?php endforeach; ?>
</div>
</div>

<div class="bh-card overflow-hidden">
    <?php if (empty($comments)): ?>
        <?php
            [$__t, $__x] = match ($status) {
                'pending'  => ['Nothing waiting for review', 'New comments that need approval appear here.'],
                'approved' => ['No approved comments', 'Comments you approve appear here.'],
                'spam'     => ['No spam', 'Comments marked as spam appear here.'],
                'trash'    => ['Trash is empty', 'Deleted comments appear here.'],
                default    => ['No comments yet', 'Comments on your posts appear here.'],
            };
        ?>
        <div class="bh-empty">
            <span class="bh-empty__icon"><?= icon('chat-bubble-left-right') ?></span>
            <p class="bh-empty__title"><?= $__t ?></p>
            <p class="bh-empty__text"><?= $__x ?></p>
        </div>
    <?php else: ?>
    <div class="divide-y divide-slate-100">
        <?php foreach ($comments as $c): ?>
        <div class="px-5 py-4 hover:bg-slate-50 flex items-start gap-4">
            <div class="w-10 h-10 rounded-full bg-gradient-to-br from-blue-400 to-blue-600 grid place-items-center text-white text-sm font-semibold flex-shrink-0">
                <?= strtoupper(substr($c['author_name'] ?? 'A', 0, 1)) ?>
            </div>
            <div class="flex-1 min-w-0">
                <div class="flex items-center gap-2 flex-wrap">
                    <span class="font-medium text-slate-900 text-sm"><?= htmlspecialchars($c['author_name'] ?? 'Anonymous') ?></span>
                    <?php if (!empty($c['author_email'])): ?>
                        <span class="text-xs text-slate-500">&lt;<?= htmlspecialchars($c['author_email']) ?>&gt;</span>
                    <?php endif; ?>
                    <span class="bh-badge <?php
                        echo match($c['status']) {
                            'approved' => 'bh-badge--green',
                            'pending' => 'bh-badge--amber',
                            'spam' => 'bh-badge--red',
                            default => 'bh-badge--gray',
                        };
                    ?>"><?= ucfirst($c['status']) ?></span>
                </div>
                <p class="text-sm text-slate-700 mt-1"><?= nl2br(htmlspecialchars($c['content'])) ?></p>
                <div class="text-xs text-slate-400 mt-2 flex items-center gap-3 flex-wrap">
                    <span><?= icon('clock', 'w-4 h-4 mr-1') ?><?= date('M j, Y g:i a', strtotime($c['created_at'])) ?></span>
                    <?php if (!empty($c['post_title'])): ?>
                    <span>on <a href="<?= $base ?>/admin/posts/<?= $c['post_id'] ?>/edit" class="text-blue-600 hover:underline"><?= htmlspecialchars($c['post_title']) ?></a></span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="flex items-center gap-1 flex-shrink-0">
                <?php if ($c['status'] !== 'approved'): ?>
                <form method="POST" action="<?= $base ?>/admin/comments/<?= $c['id'] ?>/approve" class="inline">
                    <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
                    <button class="p-2 text-slate-400 hover:text-green-600 hover:bg-green-50 rounded" title="Approve">
                        <?= icon('check', 'w-4 h-4') ?>
                    </button>
                </form>
                <?php endif; ?>
                <?php if ($c['status'] !== 'spam'): ?>
                <form method="POST" action="<?= $base ?>/admin/comments/<?= $c['id'] ?>/spam" class="inline">
                    <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
                    <button class="p-2 text-slate-400 hover:text-amber-600 hover:bg-amber-50 rounded" title="Mark spam">
                        <?= icon('no-symbol', 'w-4 h-4') ?>
                    </button>
                </form>
                <?php endif; ?>
                <form method="POST" action="<?= $base ?>/admin/comments/<?= $c['id'] ?>/delete" class="inline" onsubmit="return confirm('Delete this comment?')">
                    <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
                    <button class="p-2 text-slate-400 hover:text-red-600 hover:bg-red-50 rounded" title="Delete">
                        <?= icon('trash', 'w-4 h-4') ?>
                    </button>
                </form>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<?php $this->endSection(); ?>
