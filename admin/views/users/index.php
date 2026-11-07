<?php $this->extend('layouts.app'); ?>
<?php $this->section('content'); ?>
<?php
/*
 * Users (1.2.102): core's Data Table (users.list, routes/tables.php), with
 * search, role and status filters, sorting, column choices and cards on
 * phones. Delete runs here, through the existing form route, after asking.
 */
?>

<div class="flex items-center justify-between mb-5 flex-wrap gap-3">
    <div>
        <h2 class="text-xl font-semibold text-slate-900">Users</h2>
        <p class="text-sm text-slate-500">Manage user accounts.</p>
    </div>
    <a href="<?= $base ?>/admin/users/create" class="bh-btn bh-btn--primary">
        <?= icon('user-plus', 'w-4 h-4') ?> New user
    </a>
</div>

<?php $this->include('partials.datatable', [
    'tableKey'   => 'users.list',
    'bulkEvents' => true,
]); ?>

<form method="POST" id="bh-user-delete" class="hidden">
    <input type="hidden" name="_csrf" value="<?= htmlspecialchars((string) $csrf, ENT_QUOTES) ?>">
</form>

<script>
(function () {
    var table = document.querySelector('.bh-dt');
    var form = document.getElementById('bh-user-delete');
    if (!table || !form) return;
    table.addEventListener('bh:dt-action', function (e) {
        var d = e.detail || {};
        if (d.action !== 'delete') return;
        // The name as the row shows it (read as text, never inserted as HTML).
        var who = 'this person';
        if (d.row && d.row.cells && d.row.cells.user) {
            var t = new DOMParser().parseFromString(d.row.cells.user, 'text/html').querySelector('.bh-dt-title');
            if (t && t.textContent.trim()) who = t.textContent.trim();
        }
        window.bhConfirm('Delete ' + who + '? Their account is removed and they can no longer sign in.', { danger: true, confirmLabel: 'Delete' })
            .then(function (yes) {
                if (!yes) return;
                form.action = <?= json_encode($base . '/admin/users/') ?> + encodeURIComponent(d.id) + '/delete';
                form.submit();
            });
    });
})();
</script>

<?php $this->endSection(); ?>
