<?php $this->extend('layouts.app'); ?>
<?php $this->section('content'); ?>

<?php $this->include('api._nav', ['subtab' => $subtab]); ?>
<div class="space-y-5 sm:space-y-6">

        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
            <div class="min-w-0">
                <h2 class="text-xl font-semibold text-slate-900">API Keys</h2>
                <p class="text-sm text-slate-500 mt-1">Manage keys that allow external apps to authenticate with the API.</p>
            </div>
            <button onclick="openModal()"
                    class="inline-flex items-center justify-center gap-2 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition w-full sm:w-auto sm:shrink-0">
                <?= icon('plus', 'w-4 h-4') ?> New Key
            </button>
        </div>

        <!-- One-time new key reveal banner -->
        <?php if (!empty($newKey)): ?>
        <div class="bg-amber-50 border border-amber-300 rounded-xl p-5" id="newKeyBanner">
            <div class="flex items-start gap-3">
                <?= icon('exclamation-triangle', 'w-4 h-4 text-amber-500 mt-0.5') ?>
                <div class="flex-1">
                    <p class="font-semibold text-amber-900 text-sm">Copy your API key — it won't be shown again!</p>
                    <p class="text-xs text-amber-700 mt-1">Store it securely in your desktop app's configuration. This is the only time it will be visible.</p>
                    <div class="mt-3 flex items-center gap-2">
                        <code id="newKeyText" class="flex-1 bg-white border border-amber-200 rounded-lg px-4 py-2 text-sm font-mono text-slate-900 select-all">
                            <?= htmlspecialchars($newKey) ?>
                        </code>
                        <button type="button" id="bh-copy-key"
                                class="inline-flex items-center gap-1.5 px-3 py-2 bg-amber-100 hover:bg-amber-200 border border-amber-300 rounded-lg text-xs font-medium text-amber-800 transition whitespace-nowrap">
                            <?= icon('document-duplicate', 'w-4 h-4') ?> Copy
                        </button>
                    </div>
                </div>
                <button onclick="document.getElementById('newKeyBanner').remove()" class="text-amber-400 hover:text-amber-600">
                    <?= icon('x-mark', 'w-4 h-4') ?>
                </button>
            </div>
        </div>
        <?php endif; ?>

        <!-- Keys table -->
        <div class="bg-white border border-slate-200 rounded-xl overflow-hidden">
            <?php if (empty($keys)): ?>
                <div class="text-center py-16 text-slate-400">
                    <?= icon('key', 'w-10 h-10 mb-3') ?>
                    <p class="text-sm font-medium">No API keys yet</p>
                    <p class="text-xs mt-1">Create your first key to connect a desktop app.</p>
                </div>
            <?php else: ?>
            <table class="w-full text-sm">
                <thead class="bg-slate-50 border-b border-slate-200">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wide">Name</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wide">Key Prefix</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wide">Scopes</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wide">Rate Limit</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wide">Last Used</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wide">Status</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wide">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php foreach ($keys as $key): ?>
                    <tr class="hover:bg-slate-50">
                        <td class="px-4 py-3">
                            <div class="font-medium text-slate-900"><?= htmlspecialchars($key['name']) ?></div>
                            <div class="text-xs text-slate-400 mt-0.5">
                                Created <?= date('M j, Y', strtotime($key['created_at'])) ?>
                                <?php if ($key['expires_at']): ?>
                                  · Expires <?= date('M j, Y', strtotime($key['expires_at'])) ?>
                                <?php else: ?>
                                  · Never expires
                                <?php endif; ?>
                            </div>
                        </td>
                        <td class="px-4 py-3">
                            <code class="bg-slate-100 px-2 py-0.5 rounded text-xs font-mono text-slate-700">
                                <?= htmlspecialchars($key['key_prefix']) ?>…
                            </code>
                        </td>
                        <td class="px-4 py-3">
                            <?php if (empty($key['scopes'])): ?>
                                <span class="text-xs text-slate-400">All scopes</span>
                            <?php else: ?>
                                <div class="flex flex-wrap gap-1">
                                    <?php foreach (array_slice($key['scopes'], 0, 3) as $scope): ?>
                                        <span class="inline-block bg-blue-50 text-blue-700 px-1.5 py-0.5 rounded text-[10px] font-medium"><?= htmlspecialchars($scope) ?></span>
                                    <?php endforeach; ?>
                                    <?php if (count($key['scopes']) > 3): ?>
                                        <span class="text-[10px] text-slate-400">+<?= count($key['scopes']) - 3 ?> more</span>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3 text-xs text-slate-600">
                            <?= number_format($key['rate_limit']) ?>/hr
                        </td>
                        <td class="px-4 py-3 text-xs text-slate-500">
                            <?php if ($key['last_used_at']): ?>
                                <?= date('M j, Y', strtotime($key['last_used_at'])) ?><br>
                                <span class="text-slate-400"><?= htmlspecialchars($key['last_used_ip'] ?? '') ?></span>
                            <?php else: ?>
                                <span class="text-slate-300">Never</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3">
                            <?php if ($key['is_active']): ?>
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-green-100 text-green-700 text-xs font-medium">
                                    <span class="w-1.5 h-1.5 bg-green-500 rounded-full"></span> Active
                                </span>
                            <?php else: ?>
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-red-100 text-red-700 text-xs font-medium">
                                    <span class="w-1.5 h-1.5 bg-red-500 rounded-full"></span> Revoked
                                </span>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-1">
                                <?php if ($key['is_active']): ?>
                                <form method="POST" action="<?= $base ?>/admin/api/keys/<?= $key['id'] ?>/revoke"
                                      onsubmit="return confirm('Revoke this key? Apps using it will stop working immediately.')">
                                    <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
                                    <button type="submit"
                                            class="px-2 py-1 text-xs text-amber-700 bg-amber-50 hover:bg-amber-100 rounded-lg font-medium transition">
                                        Revoke
                                    </button>
                                </form>
                                <?php endif; ?>
                                <form method="POST" action="<?= $base ?>/admin/api/keys/<?= $key['id'] ?>/delete"
                                      onsubmit="return confirm('Permanently delete this key?')">
                                    <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
                                    <button type="submit"
                                            class="px-2 py-1 text-xs text-red-700 bg-red-50 hover:bg-red-100 rounded-lg font-medium transition">
                                        Delete
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>

    </div><!-- /content -->
</div>

<!-- ===== Create Key Modal ===== -->
<style>
/* Modal styles in plain CSS — avoids Tailwind JIT purge issues with JS-toggled visibility */
#modal-create {
    display: none;
    position: fixed;
    inset: 0;
    z-index: 60;
    align-items: center;
    justify-content: center;
    padding: 1rem;
    background: rgba(15, 23, 42, 0.6);
    -webkit-backdrop-filter: blur(5px); backdrop-filter: blur(5px);
}
#modal-create.is-open {
    display: flex;
}
#modal-create .modal-box {
    background: #fff;
    border-radius: 1rem;
    box-shadow: 0 25px 50px -12px rgba(0,0,0,.25);
    width: 100%;
    max-width: 32rem;
    max-height: 90vh;
    overflow-y: auto;
}
#modal-create .modal-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 1rem 1.5rem;
    border-bottom: 1px solid #f1f5f9;
}
#modal-create .modal-header h3 {
    font-size: 0.9375rem;
    font-weight: 600;
    color: #0f172a;
    margin: 0;
}
#modal-create .modal-close {
    background: none;
    border: none;
    font-size: 1.4rem;
    line-height: 1;
    color: #94a3b8;
    cursor: pointer;
    padding: 0 4px;
}
#modal-create .modal-close:hover { color: #475569; }
#modal-create .modal-body {
    padding: 1.5rem;
    display: flex;
    flex-direction: column;
    gap: 1rem;
}
#modal-create .field-label {
    display: block;
    font-size: 0.75rem;
    font-weight: 600;
    color: #374151;
    margin-bottom: 0.375rem;
}
#modal-create .field-hint {
    font-size: 0.7rem;
    color: #94a3b8;
    margin-top: 0.25rem;
}
#modal-create .field-input {
    width: 100%;
    border: 1px solid #d1d5db;
    border-radius: 0.5rem;
    padding: 0.5rem 0.75rem;
    font-size: 0.875rem;
    color: #1e293b;
    box-sizing: border-box;
    outline: none;
    transition: box-shadow .15s;
    font-family: inherit;
}
#modal-create .field-input:focus {
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59,130,246,.2);
}
#modal-create .scopes-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0.375rem;
    max-height: 12rem;
    overflow-y: auto;
    padding-right: 2px;
}
#modal-create .scope-item {
    display: flex;
    align-items: flex-start;
    gap: 0.5rem;
    font-size: 0.75rem;
    padding: 0.5rem;
    border: 1px solid #f1f5f9;
    border-radius: 0.5rem;
    cursor: pointer;
}
#modal-create .scope-item:hover { background: #f8fafc; }
#modal-create .scope-badge {
    font-family: monospace;
    font-size: 0.625rem;
    color: #1d4ed8;
    background: #eff6ff;
    padding: 1px 4px;
    border-radius: 3px;
    display: inline-block;
}
#modal-create .scope-desc {
    display: block;
    color: #64748b;
    margin-top: 2px;
}
#modal-create .two-col {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0.75rem;
}
#modal-create .modal-footer {
    display: flex;
    justify-content: flex-end;
    gap: 0.5rem;
    padding-top: 0.75rem;
    border-top: 1px solid #f1f5f9;
}
#modal-create .btn-cancel {
    padding: 0.5rem 1rem;
    font-size: 0.875rem;
    color: #475569;
    background: none;
    border: none;
    border-radius: 0.5rem;
    cursor: pointer;
    font-weight: 500;
    font-family: inherit;
}
#modal-create .btn-cancel:hover { background: #f1f5f9; }
#modal-create .btn-submit {
    padding: 0.5rem 1rem;
    font-size: 0.875rem;
    color: #fff;
    background: #2563eb;
    border: none;
    border-radius: 0.5rem;
    cursor: pointer;
    font-weight: 500;
    font-family: inherit;
    display: inline-flex;
    align-items: center;
    gap: 0.375rem;
}
#modal-create .btn-submit:hover { background: #1d4ed8; }
</style>

<div id="modal-create">
    <div class="modal-box">
        <div class="modal-header">
            <h3>Create API Key</h3>
            <button type="button" class="modal-close" onclick="closeModal()">×</button>
        </div>
        <form method="POST" action="<?= $base ?>/admin/api/keys" class="modal-body">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">

            <!-- Name -->
            <div>
                <label class="field-label">Key Name <span style="color:#ef4444">*</span></label>
                <input type="text" name="name" required placeholder="e.g. Desktop App — Production"
                       class="field-input">
                <p class="field-hint">A human-readable label so you remember what this key is for.</p>
            </div>

            <!-- Scopes -->
            <div>
                <label class="field-label">Permissions (Scopes)</label>
                <p class="field-hint" style="margin-bottom:.5rem">Leave all unchecked to grant full access, or pick specific scopes.</p>
                <div class="scopes-grid">
                    <?php foreach ($scopes as $scope => $desc): ?>
                    <label class="scope-item">
                        <input type="checkbox" name="scopes[]" value="<?= htmlspecialchars($scope) ?>"
                               style="margin-top:2px;flex-shrink:0">
                        <span>
                            <span class="scope-badge"><?= htmlspecialchars($scope) ?></span>
                            <span class="scope-desc"><?= htmlspecialchars($desc) ?></span>
                        </span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Rate Limit & Expiry -->
            <div class="two-col">
                <div>
                    <label class="field-label">Rate Limit (req/hr)</label>
                    <input type="number" name="rate_limit" value="1000" min="1" max="100000"
                           class="field-input">
                </div>
                <div>
                    <label class="field-label">Expiry</label>
                    <select name="expires_in" class="field-input">
                        <option value="never">Never</option>
                        <option value="30 days">30 days</option>
                        <option value="90 days">90 days</option>
                        <option value="1 year">1 year</option>
                    </select>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn-cancel" onclick="closeModal()">Cancel</button>
                <button type="submit" class="btn-submit">
                    <?= icon('key', 'w-4 h-4') ?> Generate Key
                </button>
            </div>
        </form>
    </div>

<script>
// Copy-key button: swap the icon by re-rendering it (inline SVG has no class-based glyph).
(function () {
    var btn = document.getElementById('bh-copy-key');
    if (!btn) return;
    var original = btn.innerHTML;
    btn.addEventListener('click', function () {
        var el = document.getElementById('newKeyText');
        if (el) navigator.clipboard.writeText(el.textContent.trim());
        btn.innerHTML = BasehimIcon('check', 'w-4 h-4') + ' Copied!';
        setTimeout(function () { btn.innerHTML = original; }, 2000);
    });
})();
function openModal()  { document.getElementById('modal-create').classList.add('is-open'); }
function closeModal() { document.getElementById('modal-create').classList.remove('is-open'); }
// Close on backdrop click
document.getElementById('modal-create').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});
// Close on Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeModal();
});
</script>

<?php $this->endSection(); ?>