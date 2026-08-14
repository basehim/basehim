<?php $this->extend('layouts.auth'); ?>
<?php $this->section('content'); ?>
<?php $p = $params; ?>

<div class="w-full max-w-md">
    <div class="text-center mb-6">
                <div class="inline-flex mb-4"><?= brand_logo(56) ?></div>
        <h1 class="text-xl font-semibold text-slate-900">Connect to <?= htmlspecialchars($site_title ?? 'Basehim') ?></h1>
        <p class="text-sm text-slate-500 mt-1">
            <strong class="text-slate-700"><?= htmlspecialchars($client['client_name'] ?? 'An application') ?></strong>
            wants to access your site.
        </p>
    </div>

    <div class="bg-white rounded-2xl shadow-xl shadow-blue-100/40 border border-slate-100 px-7 py-7">

        <!-- Who is signing in -->
        <div class="flex items-center gap-3 pb-5 mb-5 border-b border-slate-100">
            <div class="w-9 h-9 rounded-full bg-gradient-to-br from-blue-400 to-blue-600 grid place-items-center text-white text-sm font-semibold">
                <?= strtoupper(substr((string) ($user['display_name'] ?? 'U'), 0, 1)) ?>
            </div>
            <div class="min-w-0">
                <div class="text-sm font-medium text-slate-800 truncate"><?= htmlspecialchars($user['display_name'] ?? '') ?></div>
                <div class="text-xs text-slate-500 truncate"><?= htmlspecialchars($user['email'] ?? '') ?></div>
            </div>
        </div>

        <p class="text-xs font-semibold uppercase tracking-wide text-slate-400 mb-3">It will be able to</p>
        <ul class="space-y-2.5 mb-6">
            <?php foreach ($scopes as $s): ?>
            <li class="flex items-start gap-2.5">
                <span class="mt-0.5 w-5 h-5 rounded-full bg-emerald-100 text-emerald-600 grid place-items-center shrink-0">
                    <?= icon('check', 'w-3 h-3') ?>
                </span>
                <span class="text-sm text-slate-700"><?= htmlspecialchars($scopeLabels[$s] ?? $s) ?></span>
            </li>
            <?php endforeach; ?>
        </ul>

        <div class="rounded-lg bg-amber-50 border border-amber-200 px-3 py-2.5 mb-6">
            <p class="text-xs text-amber-800">
                <?= icon('exclamation-triangle', 'w-3.5 h-3.5 mr-1 inline-block align-text-bottom') ?>
                Only continue if you started this from a tool you trust. It will act as you, limited to the permissions above.
            </p>
        </div>

        <form method="POST" action="<?= $base ?>/oauth/authorize" class="space-y-3">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
            <?php foreach (['client_id','redirect_uri','code_challenge','code_challenge_method','scope','state','resource'] as $f): ?>
                <input type="hidden" name="<?= $f ?>" value="<?= htmlspecialchars((string) ($p[$f] ?? '')) ?>">
            <?php endforeach; ?>

            <button type="submit" name="decision" value="allow"
                    class="w-full bg-blue-600 hover:bg-blue-700 text-white font-medium py-2.5 rounded-lg shadow-sm transition inline-flex items-center justify-center gap-2">
                <?= icon('check', 'w-4 h-4') ?> Allow access
            </button>
            <button type="submit" name="decision" value="deny"
                    class="w-full bg-white hover:bg-slate-50 text-slate-600 font-medium py-2.5 rounded-lg border border-slate-200 transition">
                Cancel
            </button>
        </form>
    </div>

    <p class="text-center text-xs text-slate-400 mt-5">
        Redirects to <span class="font-mono break-all"><?= htmlspecialchars(parse_url((string) ($p['redirect_uri'] ?? ''), PHP_URL_HOST) ?: '—') ?></span>
    </p>
</div>

<?php $this->endSection(); ?>
