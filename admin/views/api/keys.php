<?php $this->extend('layouts.app'); ?>
<?php $this->section('content'); ?>
<?php
/*
 * API → Keys. Since 1.2.102 built from the admin's components: the key list
 * is a small stacking table (.bh-table--stack), the "New key" form a
 * .bh-dialog with core fields and checkboxes. It replaced a hand-made modal
 * with its own 90-line stylesheet.
 */
$__e = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
?>

<?php $this->include('api._nav', ['subtab' => $subtab]); ?>
<div class="space-y-5 sm:space-y-6">

    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
        <div class="min-w-0">
            <h2 class="text-xl font-semibold text-slate-900">API Keys</h2>
            <p class="text-sm text-slate-500 mt-1">Manage keys that allow external apps to authenticate with the API.</p>
        </div>
        <button type="button" class="bh-btn bh-btn--primary w-full sm:w-auto" data-bh-dialog-open="bh-key-dialog">
            <?= icon('plus', 'w-4 h-4') ?> New key
        </button>
    </div>

    <?php if (!empty($newKey)): ?>
    <div class="bh-note bh-note--warn" id="newKeyBanner" role="status">
        <?= icon('exclamation-triangle', 'w-4 h-4') ?>
        <div class="min-w-0 flex-1">
            <p class="m-0 font-semibold">Copy your API key now: it isn't shown again.</p>
            <p class="m-0 mt-1">Store it securely in your app's configuration. This is the only time it is visible.</p>
            <div class="mt-3 flex flex-col sm:flex-row sm:items-center gap-2">
                <code id="newKeyText" class="flex-1 min-w-0 break-all rounded-lg border border-amber-200 bg-white px-3 py-2 font-mono text-sm text-slate-900 select-all"><?= $__e($newKey) ?></code>
                <button type="button" id="bh-copy-key" class="bh-btn bh-btn--secondary bh-btn--sm">
                    <?= icon('document-duplicate', 'w-4 h-4') ?> Copy
                </button>
            </div>
        </div>
        <button type="button" class="bh-btn bh-btn--ghost bh-btn--sm bh-btn--icon" aria-label="Dismiss" onclick="document.getElementById('newKeyBanner').remove()">
            <?= icon('x-mark', 'w-4 h-4') ?>
        </button>
    </div>
    <?php endif; ?>

    <div class="bh-card overflow-hidden">
        <?php if (empty($keys)): ?>
        <div class="bh-empty">
            <div class="bh-empty__icon"><?= icon('key') ?></div>
            <p class="bh-empty__title">No API keys yet</p>
            <p class="bh-empty__text">Create your first key to connect a desktop app or another service.</p>
            <div class="bh-empty__actions">
                <button type="button" class="bh-btn bh-btn--secondary" data-bh-dialog-open="bh-key-dialog"><?= icon('plus', 'w-4 h-4') ?> New key</button>
            </div>
        </div>
        <?php else: ?>
        <table class="bh-table bh-table--stack">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Key prefix</th>
                    <th>Scopes</th>
                    <th>Rate limit</th>
                    <th>Last used</th>
                    <th>Status</th>
                    <th><span class="sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($keys as $key): ?>
                <tr>
                    <td class="bh-table__primary">
                        <div class="font-medium text-slate-900"><?= $__e($key['name']) ?></div>
                        <div class="text-xs text-slate-500 mt-0.5">
                            Created <?= bh_date($key['created_at'], 'M j, Y') ?>
                            · <?= $key['expires_at'] ? 'Expires ' . bh_date($key['expires_at'], 'M j, Y') : 'Never expires' ?>
                        </div>
                    </td>
                    <td data-label="Key prefix"><code class="rounded bg-slate-100 px-2 py-0.5 font-mono text-xs text-slate-700"><?= $__e($key['key_prefix']) ?>…</code></td>
                    <td data-label="Scopes">
                        <?php if (empty($key['scopes'])): ?>
                            <span class="text-xs text-slate-500">All scopes</span>
                        <?php else: ?>
                            <span class="inline-flex flex-wrap gap-1">
                                <?php foreach (array_slice($key['scopes'], 0, 3) as $scope): ?>
                                <span class="bh-badge bh-badge--blue"><?= $__e($scope) ?></span>
                                <?php endforeach; ?>
                                <?php if (count($key['scopes']) > 3): ?>
                                <span class="text-xs text-slate-500">+<?= count($key['scopes']) - 3 ?> more</span>
                                <?php endif; ?>
                            </span>
                        <?php endif; ?>
                    </td>
                    <td data-label="Rate limit" class="text-xs"><?= number_format((int) $key['rate_limit']) ?>/hr</td>
                    <td data-label="Last used" class="text-xs text-slate-500">
                        <?php if ($key['last_used_at']): ?>
                            <?= bh_date($key['last_used_at'], 'M j, Y') ?>
                            <?php if (!empty($key['last_used_ip'])): ?><span class="text-slate-400"> · <?= $__e($key['last_used_ip']) ?></span><?php endif; ?>
                        <?php else: ?>
                            Never
                        <?php endif; ?>
                    </td>
                    <td data-label="Status">
                        <?php if ($key['is_active']): ?>
                            <span class="bh-badge bh-badge--green">Active</span>
                        <?php else: ?>
                            <span class="bh-badge bh-badge--red">Revoked</span>
                        <?php endif; ?>
                    </td>
                    <td class="bh-table__actions">
                        <?php if ($key['is_active']): ?>
                        <form method="POST" action="<?= $base ?>/admin/api/keys/<?= (int) $key['id'] ?>/revoke" class="inline"
                              onsubmit="return confirm('Revoke this key? Apps using it stop working immediately.')">
                            <input type="hidden" name="_csrf" value="<?= $__e($csrf) ?>">
                            <button type="submit" class="bh-btn bh-btn--secondary bh-btn--sm">Revoke</button>
                        </form>
                        <?php endif; ?>
                        <form method="POST" action="<?= $base ?>/admin/api/keys/<?= (int) $key['id'] ?>/delete" class="inline"
                              onsubmit="return confirm('Permanently delete this key?')">
                            <input type="hidden" name="_csrf" value="<?= $__e($csrf) ?>">
                            <button type="submit" class="bh-btn bh-btn--danger bh-btn--sm">Delete</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<dialog class="bh-dialog bh-dialog--lg" id="bh-key-dialog" aria-labelledby="bh-key-dialog-title" data-bh-dialog-focus="#bh-key-name">
    <form method="POST" action="<?= $base ?>/admin/api/keys" class="bh-dialog__form">
        <input type="hidden" name="_csrf" value="<?= $__e($csrf) ?>">
        <header class="bh-dialog__head">
            <div>
                <h2 class="bh-dialog__title" id="bh-key-dialog-title">New API key</h2>
                <p class="bh-dialog__sub">The key is shown once, right after you create it.</p>
            </div>
            <button type="button" class="bh-dialog__close" data-bh-dialog-close aria-label="Close"><?= icon('x-mark') ?></button>
        </header>
        <div class="bh-dialog__body">
            <div>
                <label class="bh-label" for="bh-key-name">Key name</label>
                <input type="text" id="bh-key-name" name="name" required maxlength="100" class="bh-input" placeholder="e.g. Desktop app, production">
                <p class="bh-help">A label so you remember what this key is for.</p>
            </div>
            <fieldset class="m-0 p-0 border-0 min-w-0">
                <legend class="bh-label">Permissions (scopes)</legend>
                <p class="bh-help mt-0 mb-2">Leave all unticked to grant full access, or pick only what the app needs.</p>
                <div class="bh-checks">
                    <?php foreach ($scopes as $scope => $desc): ?>
                    <label class="bh-check">
                        <input type="checkbox" class="bh-check__input" name="scopes[]" value="<?= $__e($scope) ?>">
                        <span class="bh-check__text"><code class="font-mono text-xs text-blue-700"><?= $__e($scope) ?></code>
                            <span class="bh-check__help"><?= $__e($desc) ?></span></span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </fieldset>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="bh-label" for="bh-key-rate">Rate limit (requests per hour)</label>
                    <input type="number" id="bh-key-rate" name="rate_limit" value="1000" min="1" max="100000" class="bh-input">
                </div>
                <div>
                    <label class="bh-label" for="bh-key-expiry">Expiry</label>
                    <select id="bh-key-expiry" name="expires_in" class="bh-select">
                        <option value="never">Never</option>
                        <option value="30 days">30 days</option>
                        <option value="90 days">90 days</option>
                        <option value="1 year">1 year</option>
                    </select>
                </div>
            </div>
        </div>
        <footer class="bh-dialog__foot">
            <button type="button" class="bh-btn bh-btn--secondary" data-bh-dialog-close>Cancel</button>
            <button type="submit" class="bh-btn bh-btn--primary"><?= icon('key', 'w-4 h-4') ?> Create key</button>
        </footer>
    </form>
</dialog>

<script>
// Copy the new key; the button says so for two seconds.
(function () {
    var btn = document.getElementById('bh-copy-key');
    if (!btn) return;
    var original = btn.innerHTML;
    btn.addEventListener('click', function () {
        var el = document.getElementById('newKeyText');
        if (!el || !navigator.clipboard) return;
        navigator.clipboard.writeText(el.textContent.trim()).then(function () {
            btn.innerHTML = BasehimIcon('check', 'w-4 h-4') + ' Copied';
            setTimeout(function () { btn.innerHTML = original; }, 2000);
        }, function () { window.bhToast && window.bhToast('Could not copy. Select the key and copy it yourself.', 'error'); });
    });
})();
</script>

<?php $this->endSection(); ?>
