<?php $this->extend('layouts.app'); ?>
<?php $this->section('content'); ?>

<div class="mb-5">
    <h2 class="text-xl font-semibold text-slate-900">Settings</h2>
    <p class="text-sm text-slate-500">Configure your site.</p>
</div>

<div>
    <?php $this->include('settings._nav', compact('tab', 'base')); ?>
    <div class="mt-0">
        <div class="bg-white rounded-xl border border-slate-200 p-6">
            <h3 class="font-semibold text-slate-900 mb-1">Permalinks</h3>
            <p class="text-sm text-slate-500 mb-5">Control the URL structure for your posts.</p>

            <form method="POST" action="<?= $base ?>/admin/settings/permalinks" class="space-y-5">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">

                <?php $struct = $values['structure'] ?? 'pretty'; ?>

                <div class="space-y-3">
                    <label class="flex items-start gap-3 p-4 border border-slate-200 rounded-lg cursor-pointer hover:bg-slate-50 has-[:checked]:border-blue-500 has-[:checked]:bg-blue-50/30">
                        <input type="radio" name="structure" value="pretty" <?= $struct === 'pretty' ? 'checked' : '' ?>
                            class="mt-1 text-blue-600 focus:ring-blue-500">
                        <div class="flex-1">
                            <div class="font-medium text-sm text-slate-900">Default</div>
                            <div class="text-xs text-slate-500 mt-0.5">Posts use <code class="px-1.5 py-0.5 bg-slate-100 rounded text-xs">/posts/sample-post</code>, pages use <code class="px-1.5 py-0.5 bg-slate-100 rounded text-xs">/sample-page</code>.</div>
                        </div>
                    </label>

                    <label class="flex items-start gap-3 p-4 border border-slate-200 rounded-lg cursor-pointer hover:bg-slate-50 has-[:checked]:border-blue-500 has-[:checked]:bg-blue-50/30">
                        <input type="radio" name="structure" value="category" <?= $struct === 'category' ? 'checked' : '' ?>
                            class="mt-1 text-blue-600 focus:ring-blue-500">
                        <div class="flex-1">
                            <div class="font-medium text-sm text-slate-900">Category / Post name</div>
                            <div class="text-xs text-slate-500 mt-0.5">Posts use <code class="px-1.5 py-0.5 bg-slate-100 rounded text-xs">/electronics/sample-post</code> (primary category slug + post slug). Pages use <code class="px-1.5 py-0.5 bg-slate-100 rounded text-xs">/sample-page</code>.</div>
                        </div>
                    </label>

                    <label class="flex items-start gap-3 p-4 border border-slate-200 rounded-lg cursor-pointer hover:bg-slate-50 has-[:checked]:border-blue-500 has-[:checked]:bg-blue-50/30">
                        <input type="radio" name="structure" value="flat" <?= $struct === 'flat' ? 'checked' : '' ?>
                            class="mt-1 text-blue-600 focus:ring-blue-500">
                        <div class="flex-1">
                            <div class="font-medium text-sm text-slate-900">Flat (post name only)</div>
                            <div class="text-xs text-slate-500 mt-0.5">Both posts and pages live at <code class="px-1.5 py-0.5 bg-slate-100 rounded text-xs">/sample-name</code>. Posts no longer use the <code>/posts/</code> prefix.</div>
                        </div>
                    </label>
                </div>

                <div class="bg-amber-50 border border-amber-200 rounded-lg p-4 text-sm text-amber-900">
                    <?= icon('information-circle', 'w-4 h-4 mr-1') ?>
                    Changing your permalink structure may break existing links from search engines and external sites pointing to your content.
                </div>

                <!-- ── Preferred address ─────────────────────────────────── -->
                <div class="pt-5 border-t border-slate-200">
                    <h4 class="font-semibold text-slate-900 mb-1">Preferred address</h4>
                    <p class="text-sm text-slate-500 mb-4">
                        A site reachable at more than one address splits its search ranking between
                        them and can have visitors logged out when they cross from one to the other.
                        Choosing one here writes a permanent redirect into <code class="px-1 bg-slate-100 rounded text-xs">.htaccess</code>
                        so every visit lands on the same address.
                    </p>

                    <?php
                    $host  = $values['canonical_host'] ?? 'none';
                    $https = !empty($values['force_https']);
                    $ht    = $htaccess ?? ['exists' => false, 'writable' => false, 'managed' => false];
                    // Show the choices against the address actually in use, so
                    // "with www" is concrete rather than an abstraction.
                    $bare  = preg_replace('/^www\./i', '', (string) ($currentHost ?? 'example.com'));
                    ?>

                    <div class="space-y-3 mb-4">
                        <label class="flex items-start gap-3 p-4 border border-slate-200 rounded-lg cursor-pointer hover:bg-slate-50 has-[:checked]:border-blue-500 has-[:checked]:bg-blue-50/30">
                            <input type="radio" name="canonical_host" value="none" <?= $host === 'none' ? 'checked' : '' ?>
                                class="mt-1 text-blue-600 focus:ring-blue-500">
                            <div class="flex-1">
                                <div class="font-medium text-sm text-slate-900">Leave it alone</div>
                                <div class="text-xs text-slate-500 mt-0.5">No host redirect. Choose this if your host or a service like Cloudflare already handles it &mdash; two sets of redirect rules can loop.</div>
                            </div>
                        </label>

                        <label class="flex items-start gap-3 p-4 border border-slate-200 rounded-lg cursor-pointer hover:bg-slate-50 has-[:checked]:border-blue-500 has-[:checked]:bg-blue-50/30">
                            <input type="radio" name="canonical_host" value="www" <?= $host === 'www' ? 'checked' : '' ?>
                                class="mt-1 text-blue-600 focus:ring-blue-500">
                            <div class="flex-1">
                                <div class="font-medium text-sm text-slate-900">With <code>www</code></div>
                                <div class="text-xs text-slate-500 mt-0.5"><?= htmlspecialchars($bare) ?> &rarr; <strong>www.<?= htmlspecialchars($bare) ?></strong></div>
                            </div>
                        </label>

                        <label class="flex items-start gap-3 p-4 border border-slate-200 rounded-lg cursor-pointer hover:bg-slate-50 has-[:checked]:border-blue-500 has-[:checked]:bg-blue-50/30">
                            <input type="radio" name="canonical_host" value="root" <?= $host === 'root' ? 'checked' : '' ?>
                                class="mt-1 text-blue-600 focus:ring-blue-500">
                            <div class="flex-1">
                                <div class="font-medium text-sm text-slate-900">Without <code>www</code></div>
                                <div class="text-xs text-slate-500 mt-0.5">www.<?= htmlspecialchars($bare) ?> &rarr; <strong><?= htmlspecialchars($bare) ?></strong></div>
                            </div>
                        </label>
                    </div>

                    <label class="flex items-start gap-3 p-4 border border-slate-200 rounded-lg cursor-pointer hover:bg-slate-50 has-[:checked]:border-blue-500 has-[:checked]:bg-blue-50/30">
                        <input type="checkbox" name="force_https" value="1" <?= $https ? 'checked' : '' ?>
                            class="mt-1 rounded text-blue-600 focus:ring-blue-500">
                        <div class="flex-1">
                            <div class="font-medium text-sm text-slate-900">Always use HTTPS</div>
                            <div class="text-xs text-slate-500 mt-0.5">
                                Redirects <code>http://</code> to <code>https://</code>. Only switch this on once a
                                certificate is installed and working &mdash; otherwise the site becomes unreachable.
                                The rule understands proxies, so it will not loop behind Cloudflare.
                            </div>
                        </div>
                    </label>

                    <?php if (!$ht['exists']): ?>
                        <div class="mt-4 rounded-lg bg-slate-50 border border-slate-200 p-4 text-sm text-slate-700">
                            <?= icon('information-circle', 'w-4 h-4 mr-1 text-slate-400') ?>
                            There is no <code>.htaccess</code> at the site root. That is normal on nginx, where these
                            rules belong in the server configuration instead. Your choice is still saved, and the
                            rules to copy are shown below once you pick one.
                        </div>
                    <?php elseif (!$ht['writable']): ?>
                        <div class="mt-4 rounded-lg bg-amber-50 border border-amber-200 p-4 text-sm text-amber-900">
                            <?= icon('exclamation-triangle', 'w-4 h-4 mr-1') ?>
                            <code>.htaccess</code> is not writable, so the rules cannot be saved into it.
                            Either grant write permission, or paste the block below in by hand.
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($htaccessBlock)): ?>
                        <details class="mt-4 rounded-lg border border-slate-200">
                            <summary class="px-4 py-2.5 text-sm font-medium text-slate-700 cursor-pointer select-none">
                                Show the rules currently in use
                            </summary>
                            <pre class="px-4 pb-4 text-[11px] leading-relaxed text-slate-600 overflow-x-auto"><?= htmlspecialchars($htaccessBlock) ?></pre>
                        </details>
                    <?php endif; ?>
                </div>

                <button type="submit" class="px-5 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg shadow-sm">
                    <?= icon('document-check', 'w-4 h-4 mr-1') ?> Save Changes
                </button>
            </form>
        </div>
    </div>
</div>

<?php $this->endSection(); ?>
