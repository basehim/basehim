<?php $this->extend('layouts.app'); ?>
<?php $this->section('content'); ?>

<?php
$isEdit = $editUser !== null;
$action = $isEdit ? "{$base}/admin/users/{$editUser['id']}" : "{$base}/admin/users";
$isSelf = $isEdit && (int)($currentUser['id'] ?? 0) === (int)$editUser['id'];
$uid = $isEdit ? (int)$editUser['id'] : 0;
$statusNow = $editUser['status'] ?? 'active';
$statusColors = ['active' => 'bg-emerald-100 text-emerald-700', 'inactive' => 'bg-slate-200 text-slate-600', 'suspended' => 'bg-red-100 text-red-700', 'pending' => 'bg-amber-100 text-amber-700'];
?>

<div class="flex items-center gap-3 mb-5">
    <a href="<?= $base ?>/admin/users" class="text-slate-500 hover:text-blue-600">
        <?= icon('arrow-left', 'w-4 h-4') ?>
    </a>
    <h2 class="text-xl font-semibold text-slate-900"><?= $isEdit ? 'Edit User' : 'New User' ?></h2>
    <?php if ($isEdit): ?>
        <span class="text-slate-400">—</span>
        <span class="text-slate-600 text-sm font-medium"><?= htmlspecialchars($editUser['display_name'] ?: $editUser['username']) ?></span>
        <span class="text-[11px] font-semibold px-2 py-0.5 rounded-full <?= $statusColors[$statusNow] ?? 'bg-slate-100 text-slate-600' ?>"><?= ucfirst($statusNow) ?></span>
        <span class="text-[11px] font-semibold px-2 py-0.5 rounded-full bg-blue-100 text-blue-700"><?= ucwords(str_replace('_', ' ', $editUser['role'] ?? 'subscriber')) ?></span>
        <?php if ($isSelf): ?><span class="text-[11px] text-slate-400">(this is you)</span><?php endif; ?>
    <?php endif; ?>
</div>

<?php if ($isEdit): ?>
<!-- Tab bar
     Uses the shared .bh-tabs/.bh-tab component rather than hand-rolled Tailwind.
     These looked like tabs but were not the component, so they missed the sticky
     positioning and horizontal scrolling every other section gets, and drifted
     whenever the component changed. Panels are switched by script here — unlike
     Settings, where each tab is a separate page — so these stay <button>s. -->
<nav class="bh-tabs" id="user-tabs" aria-label="User sections">
    <button data-tab="account" class="utab bh-tab" type="button">
        <?= icon('user', 'w-4 h-4') ?>Account
    </button>
    <button data-tab="access" class="utab bh-tab" type="button">
        <?= icon('shield-check', 'w-4 h-4') ?>Access control
    </button>
    <button data-tab="activity" class="utab bh-tab" type="button">
        <?= icon('clock', 'w-4 h-4') ?>Activity
    </button>
    <button data-tab="danger" class="utab bh-tab bh-tab-danger" type="button">
        <?= icon('exclamation-triangle', 'w-4 h-4') ?>Danger zone
    </button>
</nav>
<?php endif; ?>

<!-- ============================ ACCOUNT ============================ -->
<div class="upane" data-pane="account">
<form method="POST" action="<?= $action ?>" class="max-w-3xl">
    <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
    <div class="bg-white rounded-xl border border-slate-200 p-5 space-y-4">
        <h3 class="text-sm font-semibold text-slate-900">Basic account information</h3>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <div>
                <label class="block text-xs text-slate-500 mb-1">Username <?= $isEdit ? '(read-only)' : '*' ?></label>
                <input type="text" name="username" value="<?= htmlspecialchars($editUser['username'] ?? '') ?>"
                    <?= $isEdit ? 'readonly' : 'required' ?>
                    class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500 outline-none <?= $isEdit ? 'bg-slate-50' : '' ?>">
            </div>
            <div>
                <label class="block text-xs text-slate-500 mb-1">Email *</label>
                <input type="email" name="email" value="<?= htmlspecialchars($editUser['email'] ?? '') ?>" required
                    class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500 outline-none">
            </div>
            <div>
                <label class="block text-xs text-slate-500 mb-1">Display Name</label>
                <input type="text" name="display_name" value="<?= htmlspecialchars($editUser['display_name'] ?? '') ?>"
                    class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500 outline-none">
            </div>
            <div>
                <label class="block text-xs text-slate-500 mb-1">Password <?= $isEdit ? '(leave blank to keep)' : '* (min 8 chars)' ?></label>
                <input type="password" name="password" autocomplete="new-password" <?= $isEdit ? '' : 'required minlength="8"' ?>
                    class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500 outline-none">
            </div>
        </div>
        <div>
            <label class="block text-xs text-slate-500 mb-1">Bio</label>
            <textarea name="bio" rows="3" class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500 outline-none"><?= htmlspecialchars($editUser['bio'] ?? '') ?></textarea>
        </div>
        <?php if (!$isEdit): ?>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-3 border-t border-slate-100 pt-4">
            <div>
                <label class="block text-xs text-slate-500 mb-1">Role</label>
                <select name="role" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm outline-none focus:border-blue-500">
                    <?php foreach ($roles as $r): ?>
                        <option value="<?= $r ?>" <?= $r === 'subscriber' ? 'selected' : '' ?>><?= ucwords(str_replace('_', ' ', $r)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-xs text-slate-500 mb-1">Status</label>
                <select name="status" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm outline-none focus:border-blue-500">
                    <?php foreach (['active','inactive','suspended','pending'] as $s): ?>
                        <option value="<?= $s ?>"><?= ucfirst($s) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <?php endif; ?>
        <div class="pt-1">
            <button type="submit" class="px-5 py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium shadow-sm text-sm">
                <?= icon('document-check', 'w-4 h-4 mr-1') ?> <?= $isEdit ? 'Save Account' : 'Create User' ?>
            </button>
        </div>
    </div>
</form>
</div>

<?php if ($isEdit): ?>

<!-- ========================= ACCESS CONTROL ========================= -->
<div class="upane hidden" data-pane="access">
<?php if (!$canManageTarget): ?>
    <div class="max-w-4xl bg-amber-50 border border-amber-200 rounded-xl p-4 text-sm text-amber-700 mb-4">
        <?= icon('lock-closed', 'w-4 h-4 mr-1') ?> This user is at or above your access level, so you can't change their role or permissions. The controls below are read-only.
    </div>
<?php endif; ?>
<form method="POST" action="<?= $base ?>/admin/users/<?= $uid ?>/access" class="max-w-4xl space-y-5">
    <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">

    <div class="bg-white rounded-xl border border-slate-200 p-5">
        <h3 class="text-sm font-semibold text-slate-900 mb-1">Role</h3>
        <p class="text-xs text-slate-500 mb-3">The role provides the base set of capabilities (defined in <code class="bg-slate-100 px-1 rounded">config/capabilities.php</code>).</p>
        <select name="role" <?= $isSelf ? 'disabled' : '' ?> class="w-full max-w-xs px-3 py-2 border border-slate-300 rounded-lg text-sm outline-none focus:border-blue-500 <?= $isSelf ? 'bg-slate-50' : '' ?>">
            <?php foreach ($roles as $r): ?>
                <option value="<?= $r ?>" <?= ($editUser['role'] ?? 'subscriber') === $r ? 'selected' : '' ?>><?= ucwords(str_replace('_', ' ', $r)) ?></option>
            <?php endforeach; ?>
        </select>
        <?php if ($isSelf): ?><p class="text-xs text-amber-600 mt-2"><?= icon('information-circle', 'w-4 h-4 mr-1') ?>You can't change your own role — another administrator must do it.</p><?php endif; ?>
        <?php if (($editUser['role'] ?? '') === 'super_admin'): ?>
            <p class="text-xs text-blue-600 mt-2"><?= icon('information-circle', 'w-4 h-4 mr-1') ?>Super admins hold every capability; overrides below have no effect on them.</p>
        <?php endif; ?>
    </div>

    <div class="bg-white rounded-xl border border-slate-200 p-5">
        <h3 class="text-sm font-semibold text-slate-900 mb-1">Module access</h3>
        <p class="text-xs text-slate-500 mb-4">Which admin areas this user can open. <strong>Default</strong> follows the role; <strong>Grant</strong>/<strong>Deny</strong> override it for this user only. Deny always wins.</p>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-x-8 gap-y-1">
            <?php foreach ($moduleMap as $m): $cap = $m['cap'];
                $mode = in_array($cap, $overrides['deny'], true) ? 'deny' : (in_array($cap, $overrides['grant'], true) ? 'grant' : 'default');
                $eff = in_array('*', $effectiveCaps, true) || in_array($cap, $effectiveCaps, true);
            ?>
            <div class="flex items-center justify-between py-2 border-b border-slate-100">
                <div class="flex items-center gap-2.5 min-w-0">
                    <?= icon($m['icon'], 'w-4 h-4 shrink-0 ' . ($eff ? 'text-blue-500' : 'text-slate-300')) ?>
                    <span class="text-sm text-slate-700 truncate"><?= $m['label'] ?></span>
                    <span class="w-1.5 h-1.5 rounded-full <?= $eff ? 'bg-emerald-400' : 'bg-slate-300' ?>" title="<?= $eff ? 'Has access' : 'No access' ?>"></span>
                </div>
                <div class="flex rounded-lg border border-slate-200 overflow-hidden text-[11px] font-medium shrink-0">
                    <label class="cursor-pointer"><input type="radio" name="cap_mode[<?= $cap ?>]" value="default" class="hidden peer" <?= $mode==='default'?'checked':'' ?>><span class="px-2.5 py-1 block text-slate-500 peer-checked:bg-slate-600 peer-checked:text-white">Default</span></label>
                    <label class="cursor-pointer"><input type="radio" name="cap_mode[<?= $cap ?>]" value="grant" class="hidden peer" <?= $mode==='grant'?'checked':'' ?>><span class="px-2.5 py-1 block text-slate-500 peer-checked:bg-emerald-500 peer-checked:text-white border-l border-slate-200">Grant</span></label>
                    <label class="cursor-pointer"><input type="radio" name="cap_mode[<?= $cap ?>]" value="deny" class="hidden peer" <?= $mode==='deny'?'checked':'' ?>><span class="px-2.5 py-1 block text-slate-500 peer-checked:bg-red-500 peer-checked:text-white border-l border-slate-200">Deny</span></label>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="bg-white rounded-xl border border-slate-200 p-5">
        <h3 class="text-sm font-semibold text-slate-900 mb-1">App access</h3>
        <p class="text-xs text-slate-500 mb-4">Grant or deny this user access to specific installed apps' admin areas. <strong>Default</strong> follows the role.</p>
        <?php if (empty($appItems)): ?>
            <p class="text-xs text-slate-400"><?= icon('x-circle', 'w-4 h-4 mr-1') ?>No active apps with an admin area.</p>
        <?php else: ?>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-x-8 gap-y-1">
            <?php foreach ($appItems as $m): $cap = $m['cap'];
                $mode = in_array($cap, $overrides['deny'], true) ? 'deny' : (in_array($cap, $overrides['grant'], true) ? 'grant' : 'default');
                $eff = $m['eff'];
            ?>
            <div class="flex items-center justify-between py-2 border-b border-slate-100">
                <div class="flex items-center gap-2.5 min-w-0">
                    <?= icon('plug', 'w-4 h-4 ' . ($eff ? 'text-blue-500' : 'text-slate-300')) ?>
                    <span class="text-sm text-slate-700 truncate"><?= htmlspecialchars($m['label']) ?></span>
                    <span class="w-1.5 h-1.5 rounded-full <?= $eff ? 'bg-emerald-400' : 'bg-slate-300' ?>"></span>
                </div>
                <div class="flex rounded-lg border border-slate-200 overflow-hidden text-[11px] font-medium shrink-0">
                    <label class="cursor-pointer"><input type="radio" name="cap_mode[<?= htmlspecialchars($cap) ?>]" value="default" class="hidden peer" <?= $mode==='default'?'checked':'' ?>><span class="px-2.5 py-1 block text-slate-500 peer-checked:bg-slate-600 peer-checked:text-white">Default</span></label>
                    <label class="cursor-pointer"><input type="radio" name="cap_mode[<?= htmlspecialchars($cap) ?>]" value="grant" class="hidden peer" <?= $mode==='grant'?'checked':'' ?>><span class="px-2.5 py-1 block text-slate-500 peer-checked:bg-emerald-500 peer-checked:text-white border-l border-slate-200">Grant</span></label>
                    <label class="cursor-pointer"><input type="radio" name="cap_mode[<?= htmlspecialchars($cap) ?>]" value="deny" class="hidden peer" <?= $mode==='deny'?'checked':'' ?>><span class="px-2.5 py-1 block text-slate-500 peer-checked:bg-red-500 peer-checked:text-white border-l border-slate-200">Deny</span></label>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <div class="bg-white rounded-xl border border-slate-200 p-5">
        <h3 class="text-sm font-semibold text-slate-900 mb-1">Individual permissions</h3>
        <p class="text-xs text-slate-500 mb-4">Fine-grained capabilities beyond module access — publishing rights, editing others' content, deletions.</p>
        <?php foreach ($permissionCatalog as $group => $caps): ?>
            <p class="text-[11px] font-bold uppercase tracking-wide text-slate-400 mt-4 mb-1 first:mt-0"><?= htmlspecialchars($group) ?></p>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-x-8 gap-y-1">
                <?php foreach ($caps as $cap):
                    $mode = in_array($cap, $overrides['deny'], true) ? 'deny' : (in_array($cap, $overrides['grant'], true) ? 'grant' : 'default');
                    $eff = in_array('*', $effectiveCaps, true) || in_array($cap, $effectiveCaps, true);
                ?>
                <div class="flex items-center justify-between py-2 border-b border-slate-100">
                    <div class="flex items-center gap-2 min-w-0">
                        <span class="text-sm text-slate-700 font-mono text-[12px] truncate"><?= htmlspecialchars($cap) ?></span>
                        <span class="w-1.5 h-1.5 rounded-full <?= $eff ? 'bg-emerald-400' : 'bg-slate-300' ?>"></span>
                    </div>
                    <div class="flex rounded-lg border border-slate-200 overflow-hidden text-[11px] font-medium shrink-0">
                        <label class="cursor-pointer"><input type="radio" name="cap_mode[<?= $cap ?>]" value="default" class="hidden peer" <?= $mode==='default'?'checked':'' ?>><span class="px-2.5 py-1 block text-slate-500 peer-checked:bg-slate-600 peer-checked:text-white">Default</span></label>
                        <label class="cursor-pointer"><input type="radio" name="cap_mode[<?= $cap ?>]" value="grant" class="hidden peer" <?= $mode==='grant'?'checked':'' ?>><span class="px-2.5 py-1 block text-slate-500 peer-checked:bg-emerald-500 peer-checked:text-white border-l border-slate-200">Grant</span></label>
                        <label class="cursor-pointer"><input type="radio" name="cap_mode[<?= $cap ?>]" value="deny" class="hidden peer" <?= $mode==='deny'?'checked':'' ?>><span class="px-2.5 py-1 block text-slate-500 peer-checked:bg-red-500 peer-checked:text-white border-l border-slate-200">Deny</span></label>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    </div>

    <button type="submit" class="px-5 py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium shadow-sm text-sm">
        <?= icon('document-check', 'w-4 h-4 mr-1') ?> Save Access Control
    </button>
</form>
</div>

<!-- ============================ ACTIVITY ============================ -->
<div class="upane hidden" data-pane="activity">
    <div class="max-w-4xl bg-white rounded-xl border border-slate-200">
        <div class="flex items-center gap-2 p-4 border-b border-slate-100 flex-wrap">
            <button data-actfilter="all" class="actpill is-on px-3 py-1.5 rounded-full text-xs font-medium border border-slate-200">Recent Actions</button>
            <button data-actfilter="audit" class="actpill px-3 py-1.5 rounded-full text-xs font-medium border border-slate-200">Audit Log</button>
            <button data-actfilter="content" class="actpill px-3 py-1.5 rounded-full text-xs font-medium border border-slate-200">Content Changes</button>
            <button data-actfilter="logins" class="actpill px-3 py-1.5 rounded-full text-xs font-medium border border-slate-200">Login Attempts</button>
            <span class="ml-auto text-xs text-slate-400" id="act-count"></span>
        </div>
        <div id="act-list" class="divide-y divide-slate-100">
            <div class="p-8 text-center text-slate-400 text-sm"><?= icon('arrow-path', 'w-4 h-4 animate-spin mr-2') ?>Loading activity…</div>
        </div>
        <div class="p-3 text-center border-t border-slate-100 hidden" id="act-more-wrap">
            <button id="act-more" class="text-xs font-medium text-blue-600 hover:text-blue-700">Load more</button>
        </div>
    </div>
</div>

<!-- =========================== DANGER ZONE =========================== -->
<div class="upane hidden" data-pane="danger">
<div class="max-w-3xl space-y-4">
    <?php if ($isSelf): ?>
        <div class="bg-amber-50 border border-amber-200 rounded-xl p-4 text-sm text-amber-700">
            <?= icon('information-circle', 'w-4 h-4 mr-1') ?> These actions are disabled on your own account — another administrator must perform them.
        </div>
    <?php endif; ?>

    <div class="bg-white rounded-xl border border-slate-200 divide-y divide-slate-100">
        <!-- Suspend / Reactivate -->
        <div class="flex items-center justify-between gap-4 p-5">
            <div>
                <h4 class="text-sm font-semibold text-slate-900">Suspend account</h4>
                <p class="text-xs text-slate-500 mt-0.5">Blocks sign-in immediately. Content stays published. Reversible.</p>
            </div>
            <?php if ($statusNow === 'suspended'): ?>
            <form method="POST" action="<?= $base ?>/admin/users/<?= $uid ?>/reactivate">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
                <button <?= $isSelf ? 'disabled' : '' ?> class="px-4 py-2 text-sm font-medium rounded-lg border border-emerald-300 text-emerald-700 hover:bg-emerald-50 disabled:opacity-40">Reactivate</button>
            </form>
            <?php else: ?>
            <form method="POST" action="<?= $base ?>/admin/users/<?= $uid ?>/suspend" onsubmit="return confirm('Suspend this account? They will be signed out and unable to sign in.');">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
                <button <?= $isSelf ? 'disabled' : '' ?> class="px-4 py-2 text-sm font-medium rounded-lg border border-amber-300 text-amber-700 hover:bg-amber-50 disabled:opacity-40">Suspend</button>
            </form>
            <?php endif; ?>
        </div>

        <!-- Archive / Reactivate -->
        <div class="flex items-center justify-between gap-4 p-5">
            <div>
                <h4 class="text-sm font-semibold text-slate-900">Archive user</h4>
                <p class="text-xs text-slate-500 mt-0.5">Marks the account inactive — sign-in disabled, kept for records. Reversible.</p>
            </div>
            <?php if ($statusNow === 'inactive'): ?>
            <form method="POST" action="<?= $base ?>/admin/users/<?= $uid ?>/reactivate">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
                <button <?= $isSelf ? 'disabled' : '' ?> class="px-4 py-2 text-sm font-medium rounded-lg border border-emerald-300 text-emerald-700 hover:bg-emerald-50 disabled:opacity-40">Reactivate</button>
            </form>
            <?php else: ?>
            <form method="POST" action="<?= $base ?>/admin/users/<?= $uid ?>/archive" onsubmit="return confirm('Archive this user? They will no longer be able to sign in.');">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
                <button <?= $isSelf ? 'disabled' : '' ?> class="px-4 py-2 text-sm font-medium rounded-lg border border-slate-300 text-slate-600 hover:bg-slate-50 disabled:opacity-40">Archive</button>
            </form>
            <?php endif; ?>
        </div>

        <!-- Transfer ownership -->
        <div class="flex items-center justify-between gap-4 p-5 flex-wrap">
            <div>
                <h4 class="text-sm font-semibold text-slate-900">Transfer ownership</h4>
                <p class="text-xs text-slate-500 mt-0.5">Reassign all posts and pages authored by this user to someone else.</p>
            </div>
            <form method="POST" action="<?= $base ?>/admin/users/<?= $uid ?>/transfer" class="flex items-center gap-2"
                  onsubmit="return confirm('Transfer ALL content authored by this user? This cannot be undone automatically.');">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
                <select name="to_user_id" required class="px-3 py-2 border border-slate-300 rounded-lg text-sm outline-none focus:border-blue-500">
                    <option value="">Transfer to…</option>
                    <?php foreach ($allUsers as $u): ?>
                        <option value="<?= (int)$u['id'] ?>"><?= htmlspecialchars(($u['display_name'] ?: $u['username']) . ' (' . $u['role'] . ')') ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="px-4 py-2 text-sm font-medium rounded-lg border border-blue-300 text-blue-700 hover:bg-blue-50">Transfer</button>
            </form>
        </div>

        <!-- Delete -->
        <div class="flex items-center justify-between gap-4 p-5 bg-red-50/40 rounded-b-xl">
            <div>
                <h4 class="text-sm font-semibold text-red-700">Delete user</h4>
                <p class="text-xs text-red-500/80 mt-0.5">Soft-deletes the account. Transfer their content first if you want to keep authorship intact.</p>
            </div>
            <form method="POST" action="<?= $base ?>/admin/users/<?= $uid ?>/delete"
                  onsubmit="return confirm('Delete this user? Type OK to confirm you understand this removes their account.') && confirm('Are you absolutely sure?');">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
                <button <?= $isSelf ? 'disabled' : '' ?> class="px-4 py-2 text-sm font-medium rounded-lg bg-red-600 text-white hover:bg-red-700 disabled:opacity-40">
                    <?= icon('trash', 'w-4 h-4 mr-1') ?> Delete User
                </button>
            </form>
        </div>
    </div>
</div>
</div>

<?php endif; ?>

<?php $this->endSection(); ?>

<?php $this->section('scripts'); ?>
<?php if ($isEdit): ?>
<script>
(function () {
    // ---- tabs (hash-persisted) ----
    var tabs = document.querySelectorAll('.utab');
    var panes = document.querySelectorAll('.upane');
    function show(name) {
        var found = false;
        panes.forEach(function (p) { var on = p.getAttribute('data-pane') === name; p.classList.toggle('hidden', !on); if (on) found = true; });
        tabs.forEach(function (t) {
            var on = t.getAttribute('data-tab') === name;
            t.classList.toggle('is-active', on);
            t.setAttribute('aria-selected', on ? 'true' : 'false');
        });
        if (name === 'activity' && !actLoaded) { actLoaded = true; loadActivity(true); }
        return found;
    }
    tabs.forEach(function (t) {
        t.addEventListener('click', function () {
            var name = t.getAttribute('data-tab');
            history.replaceState(null, '', '#' + name);
            show(name);
        });
    });
    var initial = (location.hash || '#account').slice(1);
    if (!show(initial)) show('account');

    // ---- activity ----
    var BASE = <?= json_encode($base) ?>;
    var UID = <?= (int)$uid ?>;
    var actLoaded = false;
    var actFilter = 'all';
    var actPage = 1;
    var actLast = 1;
    var listEl = document.getElementById('act-list');
    var moreWrap = document.getElementById('act-more-wrap');
    var countEl = document.getElementById('act-count');

    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
    function badge(ev) {
        if (ev.indexOf('auth.login_failed') === 0) return ['Login failed', 'bg-red-100 text-red-700'];
        if (ev.indexOf('auth.') === 0) return [ev.replace('auth.', '').replace(/_/g, ' '), 'bg-sky-100 text-sky-700'];
        if (ev.indexOf('user.') === 0) return [ev.replace('user.', '').replace(/_/g, ' '), 'bg-violet-100 text-violet-700'];
        if (ev.indexOf('post.') === 0 || ev.indexOf('page.') === 0) return [ev.replace('.', ' ').replace(/_/g, ' '), 'bg-emerald-100 text-emerald-700'];
        return [ev, 'bg-slate-100 text-slate-600'];
    }
    function rowHtml(r) {
        var b = badge(r.event || '');
        var when = (r.created_at || '').replace('T', ' ');
        return '<div class="flex items-start gap-3 px-4 py-3">'
            + '<span class="text-[10.5px] font-semibold px-2 py-0.5 rounded-full whitespace-nowrap mt-0.5 ' + b[1] + '">' + esc(b[0]) + '</span>'
            + '<div class="min-w-0 flex-1"><div class="text-sm text-slate-700">' + esc(r.detail || '—') + '</div>'
            + '<div class="text-[11px] text-slate-400 mt-0.5">' + esc(when) + (r.ip ? ' · ' + esc(r.ip) : '') + '</div></div>'
            + '</div>';
    }
    function loadActivity(reset) {
        if (reset) { actPage = 1; listEl.innerHTML = '<div class="p-8 text-center text-slate-400 text-sm"><?= icon('arrow-path', 'w-4 h-4 animate-spin mr-2') ?>Loading…</div>'; }
        fetch(BASE + '/admin/users/' + UID + '/activity.json?filter=' + actFilter + '&page=' + actPage, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d.ok) { listEl.innerHTML = '<div class="p-8 text-center text-red-500 text-sm">Could not load activity.</div>'; return; }
                actLast = (d.meta && d.meta.last_page) || 1;
                var html = (d.data || []).map(rowHtml).join('');
                if (reset) {
                    listEl.innerHTML = html || '<div class="p-8 text-center text-slate-400 text-sm">No activity recorded yet.</div>';
                } else {
                    listEl.insertAdjacentHTML('beforeend', html);
                }
                countEl.textContent = (d.meta ? d.meta.total : 0) + ' event(s)';
                moreWrap.classList.toggle('hidden', actPage >= actLast);
            })
            .catch(function () { listEl.innerHTML = '<div class="p-8 text-center text-red-500 text-sm">Could not load activity.</div>'; });
    }
    document.querySelectorAll('.actpill').forEach(function (p) {
        p.addEventListener('click', function () {
            document.querySelectorAll('.actpill').forEach(function (x) {
                var on = x === p;
                x.classList.toggle('is-on', on);
                x.classList.toggle('bg-blue-600', on);
                x.classList.toggle('text-white', on);
                x.classList.toggle('border-blue-600', on);
            });
            actFilter = p.getAttribute('data-actfilter');
            loadActivity(true);
        });
    });
    // style the initially active pill
    var first = document.querySelector('.actpill.is-on');
    if (first) { first.classList.add('bg-blue-600', 'text-white', 'border-blue-600'); }
    document.getElementById('act-more').addEventListener('click', function () {
        if (actPage < actLast) { actPage++; loadActivity(false); }
    });
})();
</script>
<?php endif; ?>
<?php $this->endSection(); ?>
