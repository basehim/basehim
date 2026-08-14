<?php $this->extend('layouts.app'); ?>
<?php $this->section('content'); ?>

<?php
function fmtBytes($b) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    while ($b >= 1024 && $i < count($units) - 1) { $b /= 1024; $i++; }
    return round($b, 1) . ' ' . $units[$i];
}
?>

<!-- Welcome -->
<div class="bg-gradient-to-r from-blue-600 to-blue-500 rounded-2xl shadow-lg shadow-blue-200/50 px-6 py-6 mb-6 text-white">
    <div class="flex items-start justify-between flex-wrap gap-3">
        <div>
            <h2 class="text-xl font-semibold mb-1">Welcome back, <?= htmlspecialchars($currentUser['display_name'] ?? 'there') ?>!</h2>
            <p class="text-blue-100 text-sm">Here's what's happening with <strong><?= htmlspecialchars($siteName) ?></strong> today.</p>
        </div>
        <div class="flex gap-2">
            <a href="<?= $base ?>/admin/posts/create" class="bg-white text-blue-700 hover:bg-blue-50 px-4 py-2 rounded-lg text-sm font-medium shadow flex items-center gap-2">
                <?= icon('pencil-square', 'w-4 h-4') ?> New Post
            </a>
            <a href="<?= $base ?>/admin/media" class="bg-white/10 hover:bg-white/20 text-white border border-white/20 px-4 py-2 rounded-lg text-sm font-medium flex items-center gap-2">
                <?= icon('arrow-up-tray', 'w-4 h-4') ?> Upload
            </a>
        </div>
    </div>
</div>

<!-- Stat cards -->
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    <div class="bg-white rounded-xl border border-slate-200 p-5">
        <div class="flex items-center justify-between mb-2">
            <span class="text-sm text-slate-500">Posts</span>
            <div class="w-10 h-10 rounded-lg bg-blue-50 text-blue-600 grid place-items-center">
                <?= icon('newspaper', 'w-4 h-4') ?>
            </div>
        </div>
        <div class="text-2xl font-semibold text-slate-900"><?= number_format($stats['posts']) ?></div>
        <div class="text-xs text-slate-500 mt-1">
            <?= $stats['posts_published'] ?> published · <?= $stats['posts_draft'] ?> drafts
        </div>
    </div>

    <div class="bg-white rounded-xl border border-slate-200 p-5">
        <div class="flex items-center justify-between mb-2">
            <span class="text-sm text-slate-500">Pages</span>
            <div class="w-10 h-10 rounded-lg bg-indigo-50 text-indigo-600 grid place-items-center">
                <?= icon('document-text', 'w-4 h-4') ?>
            </div>
        </div>
        <div class="text-2xl font-semibold text-slate-900"><?= number_format($stats['pages']) ?></div>
        <div class="text-xs text-slate-500 mt-1">Static pages</div>
    </div>

    <div class="bg-white rounded-xl border border-slate-200 p-5">
        <div class="flex items-center justify-between mb-2">
            <span class="text-sm text-slate-500">Media</span>
            <div class="w-10 h-10 rounded-lg bg-sky-50 text-sky-600 grid place-items-center">
                <?= icon('photo', 'w-4 h-4') ?>
            </div>
        </div>
        <div class="text-2xl font-semibold text-slate-900"><?= number_format($stats['media']) ?></div>
        <div class="text-xs text-slate-500 mt-1"><?= fmtBytes($stats['media_size']) ?> total</div>
    </div>

    <div class="bg-white rounded-xl border border-slate-200 p-5">
        <div class="flex items-center justify-between mb-2">
            <span class="text-sm text-slate-500">Comments</span>
            <div class="w-10 h-10 rounded-lg bg-cyan-50 text-cyan-600 grid place-items-center">
                <?= icon('chat-bubble-left-right', 'w-4 h-4') ?>
            </div>
        </div>
        <div class="text-2xl font-semibold text-slate-900"><?= number_format($stats['comments']) ?></div>
        <div class="text-xs text-slate-500 mt-1">
            <?php if ($stats['comments_pending'] > 0): ?>
                <a href="<?= $base ?>/admin/comments" class="text-amber-600 hover:underline">
                    <?= icon('exclamation-circle', 'w-4 h-4') ?>
                    <?= $stats['comments_pending'] ?> pending
                </a>
            <?php else: ?>
                All reviewed
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <!-- Recent posts -->
    <div class="bg-white rounded-xl border border-slate-200 p-5 lg:col-span-2">
        <div class="flex items-center justify-between mb-4">
            <h3 class="font-semibold text-slate-900">Recent Posts</h3>
            <a href="<?= $base ?>/admin/posts" class="text-sm text-blue-600 hover:text-blue-700">View all <?= icon('arrow-right', 'w-3.5 h-3.5') ?></a>
        </div>
        <?php if (empty($recentPosts)): ?>
            <div class="text-center py-12 text-slate-500">
                <?= icon('folder-open', 'w-10 h-10 text-slate-300 mb-3') ?>
                <p>No posts yet. <a href="<?= $base ?>/admin/posts/create" class="text-blue-600 hover:underline">Create your first one</a>.</p>
            </div>
        <?php else: ?>
            <div class="divide-y divide-slate-100">
                <?php foreach ($recentPosts as $post): ?>
                <div class="py-3 flex items-center justify-between gap-4">
                    <div class="min-w-0 flex-1">
                        <a href="<?= $base ?>/admin/posts/<?= $post['id'] ?>/edit" class="block text-sm font-medium text-slate-900 hover:text-blue-600 truncate">
                            <?= htmlspecialchars($post['title']) ?>
                        </a>
                        <div class="text-xs text-slate-500 mt-0.5">
                            by <?= htmlspecialchars($post['author_name'] ?? 'Unknown') ?> · <?= date('M j, Y', strtotime($post['created_at'])) ?>
                        </div>
                    </div>
                    <span class="text-xs px-2 py-1 rounded-full font-medium <?php
                        $status = $post['status'];
                        echo $status === 'published' ? 'bg-green-50 text-green-700' :
                            ($status === 'draft' ? 'bg-slate-100 text-slate-600' :
                            ($status === 'scheduled' ? 'bg-blue-50 text-blue-700' : 'bg-amber-50 text-amber-700'));
                    ?>">
                        <?= ucfirst($status) ?>
                    </span>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Recent comments -->
    <div class="bg-white rounded-xl border border-slate-200 p-5">
        <div class="flex items-center justify-between mb-4">
            <h3 class="font-semibold text-slate-900">Recent Comments</h3>
            <a href="<?= $base ?>/admin/comments" class="text-sm text-blue-600 hover:text-blue-700">View all</a>
        </div>
        <?php if (empty($recentComments)): ?>
            <div class="text-center py-10 text-slate-500">
                <?= icon('no-symbol', 'w-8 h-8 text-slate-300 mb-2') ?>
                <p class="text-sm">No comments yet.</p>
            </div>
        <?php else: ?>
            <div class="space-y-3">
                <?php foreach ($recentComments as $c): ?>
                <div class="text-sm">
                    <div class="font-medium text-slate-900"><?= htmlspecialchars($c['author_name'] ?? 'Anonymous') ?></div>
                    <p class="text-slate-600 text-xs mt-0.5 line-clamp-2"><?= htmlspecialchars(mb_substr($c['content'], 0, 120)) ?></p>
                    <div class="text-xs text-slate-400 mt-1">
                        on <?= htmlspecialchars($c['post_title'] ?? '(deleted)') ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php
// Dashboard widgets contributed by apps & themes.
$dashWidgets = \App\Core\Application::getInstance()
    ->make(\App\Core\WidgetRegistry::class)->all('dashboard');
if (!empty($dashWidgets)):
    $reg = \App\Core\Application::getInstance()->make(\App\Core\WidgetRegistry::class);
    $widthClass = fn($w) => match ($w) { 'full' => 'lg:col-span-3', 'half' => 'lg:col-span-2', default => 'lg:col-span-1' };
?>
<div class="mt-6">
    <h3 class="text-xs font-semibold text-slate-500 uppercase tracking-wide mb-3">Widgets</h3>
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <?php foreach ($dashWidgets as $w): ?>
        <div class="<?= $widthClass($w['dashboard']['width'] ?? 'third') ?> bg-white rounded-xl border border-slate-200 overflow-hidden">
            <div class="px-4 py-3 border-b border-slate-100 flex items-center gap-2">
                <?= icon(htmlspecialchars($w['icon']), 'w-4 h-4 text-slate-400') ?>
                <h4 class="font-semibold text-slate-800 text-sm"><?= htmlspecialchars($w['title']) ?></h4>
            </div>
            <div class="p-4 text-sm text-slate-600">
                <?= $reg->render($w['key'], [], 'dashboard') ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<?php
// Background update check — runs after the dashboard has painted so it never
// delays the page. The endpoint is throttled server-side, so firing it on every
// dashboard load is cheap and keeps the badge fresh without a manual click.
$__canUpdates = \App\Http\Middleware\CheckCapability::userCan($currentUser ?? null, 'manage_settings');
$__csrf = \App\Core\Application::getInstance()->make(\App\Core\Session::class)->csrfToken();
?>
<?php if ($__canUpdates): ?>
<script>
(function () {
    // Wait for idle so we never compete with rendering.
    var start = function () {
        var body = new FormData();
        body.append('_csrf', <?= json_encode($__csrf) ?>);
        fetch(<?= json_encode($base . '/admin/updates/sync.json') ?>, {
            method: 'POST', body: body, credentials: 'same-origin',
            headers: { 'Accept': 'application/json' }
        })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (d) {
            if (!d || !d.ok) return;
            var badge = document.querySelector('[data-bh-badge="updates"]');
            if (!badge) return;
            var n = parseInt(d.count, 10) || 0;
            badge.textContent = n > 99 ? '99+' : String(n);
            badge.hidden = n === 0;
        })
        .catch(function () { /* offline or service down — badge just stays as-is */ });
    };
    if ('requestIdleCallback' in window) {
        requestIdleCallback(start, { timeout: 3000 });
    } else {
        setTimeout(start, 800);
    }
})();
</script>
<?php endif; ?>

<?php $this->endSection(); ?>
