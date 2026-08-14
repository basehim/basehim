<?php $this->extend('layouts.app'); ?>
<?php $this->section('content'); ?>

<?php
$baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
    . '://' . ($_SERVER['HTTP_HOST'] ?? 'your-domain.com');
$apiBase = $baseUrl . '/api/v1';
?>

<?php $this->include('api._nav', ['subtab' => $subtab]); ?>
<div class="space-y-5 sm:space-y-6">

        <!-- Header -->
        <div>
            <h2 class="text-xl font-semibold text-slate-900">API Overview</h2>
            <p class="text-sm text-slate-500 mt-1">Connect your desktop app or any external tool to Basehim via the REST API.</p>
        </div>

        <!-- Status badge -->
        <div class="bg-green-50 border border-green-200 rounded-xl p-4 flex items-center gap-3">
            <div class="w-8 h-8 rounded-full bg-green-100 grid place-items-center">
                <?= icon('check-circle', 'w-4 h-4 text-green-600') ?>
            </div>
            <div>
                <p class="text-sm font-semibold text-green-800">API is Active</p>
                <p class="text-xs text-green-700 mt-0.5">All REST endpoints are online and accepting requests.</p>
            </div>
        </div>

        <!-- Base URL -->
        <div class="bg-white border border-slate-200 rounded-xl p-5 space-y-3">
            <h3 class="text-sm font-semibold text-slate-800 flex items-center gap-2">
                <?= icon('globe-alt', 'w-4 h-4 text-blue-500') ?>
                Base URL
            </h3>
            <div class="flex items-stretch gap-2">
                <!-- min-w-0 + break-all: without them a long base URL refuses to
                     shrink and pushes the Copy button off the card. -->
                <code class="flex-1 min-w-0 break-all bg-slate-50 border border-slate-200 rounded-lg px-4 py-2.5 text-sm text-slate-800 font-mono select-all">
                    <?= htmlspecialchars($apiBase) ?>
                </code>
                <button onclick="navigator.clipboard.writeText('<?= htmlspecialchars($apiBase, ENT_QUOTES) ?>')"
                        class="shrink-0 inline-flex items-center px-3 py-2.5 bg-slate-100 hover:bg-slate-200 rounded-lg text-xs font-medium text-slate-600 transition whitespace-nowrap">
                    <?= icon('document-duplicate', 'w-4 h-4 mr-1') ?> Copy
                </button>
            </div>
        </div>

        <!-- Quick start -->
        <div class="bg-white border border-slate-200 rounded-xl p-5 space-y-4">
            <h3 class="text-sm font-semibold text-slate-800 flex items-center gap-2">
                <?= icon('rocket-launch', 'w-4 h-4 text-purple-500') ?>
                Quick Start
            </h3>
            <ol class="space-y-4 text-sm text-slate-700">
                <li class="flex gap-3">
                    <span class="w-6 h-6 rounded-full bg-blue-100 text-blue-700 grid place-items-center text-xs font-bold shrink-0 mt-0.5">1</span>
                    <div class="min-w-0 flex-1">
                        <p class="font-medium">Create an API Key</p>
                        <p class="text-slate-500 text-xs mt-0.5">Go to <a href="<?= $base ?>/admin/api/keys" class="text-blue-600 hover:underline">API Keys</a> and generate a new key with the scopes your desktop app needs.</p>
                    </div>
                </li>
                <li class="flex gap-3">
                    <span class="w-6 h-6 rounded-full bg-blue-100 text-blue-700 grid place-items-center text-xs font-bold shrink-0 mt-0.5">2</span>
                    <div class="min-w-0 flex-1">
                        <p class="font-medium">Authenticate Requests</p>
                        <p class="text-slate-500 text-xs mt-0.5">Send the key in the <code class="bg-slate-100 px-1 rounded">Authorization</code> header of every request.</p>
                        <pre class="mt-2 bg-slate-900 text-green-300 rounded-lg p-3 text-xs overflow-x-auto">Authorization: Bearer basehim_your_api_key_here</pre>
                    </div>
                </li>
                <li class="flex gap-3">
                    <span class="w-6 h-6 rounded-full bg-blue-100 text-blue-700 grid place-items-center text-xs font-bold shrink-0 mt-0.5">3</span>
                    <div class="min-w-0 flex-1">
                        <p class="font-medium">Make Your First Request</p>
                        <p class="text-slate-500 text-xs mt-0.5">Try fetching posts:</p>
                        <pre class="mt-2 bg-slate-900 text-green-300 rounded-lg p-3 text-xs overflow-x-auto">curl -H "Authorization: Bearer basehim_your_key" \
  <?= htmlspecialchars($apiBase) ?>/posts</pre>
                    </div>
                </li>
                <li class="flex gap-3">
                    <span class="w-6 h-6 rounded-full bg-blue-100 text-blue-700 grid place-items-center text-xs font-bold shrink-0 mt-0.5">4</span>
                    <div class="min-w-0 flex-1">
                        <p class="font-medium">Explore All Endpoints</p>
                        <p class="text-slate-500 text-xs mt-0.5">Browse the full list in the <a href="<?= $base ?>/admin/api/reference" class="text-blue-600 hover:underline">API Reference</a>.</p>
                    </div>
                </li>
            </ol>
        </div>

        <!-- Auth info cards -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="bg-white border border-slate-200 rounded-xl p-5 space-y-2">
                <h4 class="text-sm font-semibold text-slate-800 flex items-center gap-2">
                    <?= icon('shield-check', 'w-4 h-4 text-amber-500') ?>
                    Authentication Methods
                </h4>
                <ul class="text-xs text-slate-600 space-y-1.5">
                    <li class="flex items-start gap-2"><?= icon('check', 'w-4 h-4 text-green-500 mt-0.5') ?><span><strong>API Key (Bearer)</strong> — for desktop/server apps. Long-lived, scope-limited.</span></li>
                    <li class="flex items-start gap-2"><?= icon('check', 'w-4 h-4 text-green-500 mt-0.5') ?><span><strong>JWT Token</strong> — for web/mobile apps. Short-lived, refreshable via <code class="bg-slate-100 px-1 rounded">/auth/refresh</code>.</span></li>
                </ul>
            </div>
            <div class="bg-white border border-slate-200 rounded-xl p-5 space-y-2">
                <h4 class="text-sm font-semibold text-slate-800 flex items-center gap-2">
                    <?= icon('lock-closed', 'w-4 h-4 text-rose-500') ?>
                    Security Notes
                </h4>
                <ul class="text-xs text-slate-600 space-y-1.5">
                    <li class="flex items-start gap-2"><?= icon('exclamation-triangle', 'w-4 h-4 text-amber-500 mt-0.5') ?><span>Always use HTTPS in production to protect your API keys in transit.</span></li>
                    <li class="flex items-start gap-2"><?= icon('exclamation-triangle', 'w-4 h-4 text-amber-500 mt-0.5') ?><span>Grant only the scopes your app actually needs (principle of least privilege).</span></li>
                    <li class="flex items-start gap-2"><?= icon('exclamation-triangle', 'w-4 h-4 text-amber-500 mt-0.5') ?><span>Revoke any key that may have been compromised immediately.</span></li>
                </ul>
            </div>
        </div>

    </div>
<?php $this->endSection(); ?>