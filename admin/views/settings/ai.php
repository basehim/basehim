<?php $this->extend('layouts.app'); ?>
<?php $this->section('content'); ?>
<?php
    $v = $values ?? [];
    $on = static fn(string $k): bool => ($v[$k] ?? '0') === '1';
    $e = static fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    $input = 'w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500 outline-none';
    $card = 'bg-white rounded-xl border border-slate-200 p-6';
    $check = function (string $key, string $label, string $help = '') use ($on, $e): string {
        return '<label class="flex items-start gap-3 py-1.5 cursor-pointer">'
             . '<input type="checkbox" name="' . $e($key) . '" value="1"' . ($on($key) ? ' checked' : '') . ' class="mt-1 rounded border-slate-300 text-blue-600">'
             . '<span><span class="block text-sm font-medium text-slate-800">' . $e($label) . '</span>'
             . ($help !== '' ? '<span class="block text-xs text-slate-500 mt-0.5">' . $help . '</span>' : '') . '</span></label>';
    };
    $policy = function (string $key, string $label, array $bots, string $help) use ($v, $e): string {
        $cur = ($v[$key] ?? 'allow') === 'block' ? 'block' : 'allow';
        $h = '<div class="py-3 border-t border-slate-100 first:border-t-0"><div class="flex flex-wrap items-center justify-between gap-3">'
           . '<div><div class="text-sm font-medium text-slate-800">' . $e($label) . '</div><div class="text-xs text-slate-500 mt-0.5">' . $help . '</div></div>'
           . '<div class="inline-flex rounded-lg border border-slate-300 overflow-hidden text-sm" role="radiogroup" aria-label="' . $e($label) . '">';
        foreach (['allow' => 'Allow', 'block' => 'Block'] as $val => $txt) {
            $h .= '<label class="px-3 py-1.5 cursor-pointer ' . ($cur === $val ? ($val === 'block' ? 'bg-rose-600 text-white' : 'bg-blue-600 text-white') : 'bg-white text-slate-700') . '">'
                . '<input type="radio" class="sr-only" name="' . $e($key) . '" value="' . $val . '"' . ($cur === $val ? ' checked' : '') . '>' . $txt . '</label>';
        }
        return $h . '</div></div><div class="text-xs text-slate-400 mt-1.5 font-mono">' . $e(implode(', ', $bots)) . '</div></div>';
    };
    $o = $origin ?? '';
?>

<div class="mb-5">
    <h2 class="text-xl font-semibold text-slate-900">Settings</h2>
    <p class="text-sm text-slate-500">Configure your site.</p>
</div>

<div>
    <?php $this->include('settings._nav', compact('tab', 'base')); ?>
    <form method="POST" action="<?= $base ?>/admin/settings/ai" class="space-y-5">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">

        <!-- Overview -->
        <div class="<?= $card ?>">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="max-w-2xl">
                    <h3 class="font-semibold text-slate-900 mb-1">AI agent accessibility</h3>
                    <p class="text-sm text-slate-500">How AI agents and crawlers find, read and use your public site: which crawlers may visit, a summary written for language models, a machine-readable catalog of what the site offers, and forms and tools that agents in the browser can use directly.</p>
                </div>
                <a href="https://pagespeed.web.dev/analysis?url=<?= rawurlencode($o . '/') ?>" target="_blank" rel="noopener" class="inline-flex items-center gap-2 px-3 py-2 text-sm font-medium bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-lg"><?= icon('arrow-top-right-on-square', 'w-4 h-4') ?> Test in PageSpeed Insights</a>
            </div>
            <div class="mt-4"><?= $check('enabled', 'Make the site accessible to AI agents', 'Turns on llms.txt, the discovery catalog, WebMCP and the structured data below. Crawler rules in robots.txt apply either way.') ?></div>
            <div class="mt-4 overflow-x-auto">
                <table class="w-full text-sm">
                    <thead><tr class="text-left text-xs uppercase tracking-wide text-slate-500"><th class="py-2 pr-4 font-medium">What agents read</th><th class="py-2 pr-4 font-medium">Address</th><th class="py-2 font-medium">Status</th></tr></thead>
                    <tbody class="divide-y divide-slate-100">
                    <?php
                        $rows = [
                            ['Crawler rules', '/robots.txt', $on('robots_enabled'), !empty($staticRobots) ? 'A robots.txt file in the site folder is served instead of this one.' : ''],
                            ['Site summary for AI', '/llms.txt', $on('enabled') && $on('llms_enabled'), !empty($staticLlms) ? 'An llms.txt file in the site folder is served instead of this one.' : ''],
                            ['Full text for AI', '/llms-full.txt', $on('enabled') && $on('llms_enabled') && $on('llms_full'), ''],
                            ['Discovery catalog (ARD)', '/.well-known/ard.json', $on('enabled') && $on('catalog_enabled'), ($catalogCount ?? 0) . ' entr' . (($catalogCount ?? 0) === 1 ? 'y' : 'ies') . '. Also at /.well-known/ai-catalog.json.'],
                            ['In-page tools (WebMCP)', 'every public page', $on('enabled') && $on('webmcp_enabled'), count($tools ?? []) . ' tool' . (count($tools ?? []) === 1 ? '' : 's') . ($on('webmcp_forms') ? ', plus forms annotated' : '') . '.'],
                        ];
                        foreach ($rows as [$label, $path, $live, $note]):
                    ?>
                        <tr>
                            <td class="py-2.5 pr-4 text-slate-800"><?= $e($label) ?></td>
                            <td class="py-2.5 pr-4"><?php if ($path[0] === '/'): ?><a class="text-blue-600 hover:underline font-mono text-xs" href="<?= $e($o . $path) ?>" target="_blank" rel="noopener"><?= $e($path) ?></a><?php else: ?><span class="text-xs text-slate-500"><?= $e($path) ?></span><?php endif; ?></td>
                            <td class="py-2.5"><span class="inline-flex items-center gap-1.5 text-xs font-medium <?= $live ? 'text-emerald-700' : 'text-slate-400' ?>"><span class="w-2 h-2 rounded-full <?= $live ? 'bg-emerald-500' : 'bg-slate-300' ?>"></span><?= $live ? 'On' : 'Off' ?></span><?php if ($note !== ''): ?><span class="block text-xs text-slate-500 mt-0.5"><?= $e($note) ?></span><?php endif; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- robots.txt -->
        <div class="<?= $card ?>">
            <h3 class="font-semibold text-slate-900 mb-1">Crawler access — robots.txt</h3>
            <p class="text-sm text-slate-500 mb-3">Well-behaved crawlers read these rules before visiting. AI companies use separate crawlers for separate jobs, so each kind can be allowed or blocked on its own.</p>
            <?= $check('robots_enabled', 'Serve robots.txt', 'Includes your rules, the AI crawler choices below and your sitemap.') ?>
            <div class="pl-7"><?= $check('robots_agentmap', 'Add an Agentmap line for the discovery catalog', 'ARD\'s own directive. Most robots.txt checkers — including Lighthouse and Search Console — report it as an unknown directive, so it is off by default. Agents find the catalog through each page\'s link to it either way.') ?></div>
            <div class="mt-3 rounded-lg border border-slate-200 px-4">
                <?= $policy('ai_training', 'AI training crawlers', $bots['training'] ?? [], 'Collect pages to train AI models. Blocking them does not affect search engines or AI answers.') ?>
                <?= $policy('ai_search', 'AI search crawlers', $bots['search'] ?? [], 'Index pages so AI search tools can cite and link to them.') ?>
                <?= $policy('ai_user', 'AI assistants browsing for a person', $bots['user'] ?? [], 'Open a page because someone asked an assistant about it — the closest thing to a human visitor.') ?>
            </div>
            <div class="mt-4">
                <label class="block text-sm font-medium text-slate-700 mb-1.5">Your rules</label>
                <textarea name="robots_rules" rows="5" class="<?= $input ?> font-mono text-sm"><?= $e($robotsRules ?? '') ?></textarea>
                <p class="text-xs text-slate-500 mt-1">Placed first in robots.txt. The AI crawler groups, Sitemap and Agentmap lines are added after them.</p>
            </div>
        </div>

        <!-- llms.txt -->
        <div class="<?= $card ?>">
            <h3 class="font-semibold text-slate-900 mb-1">Site summary for AI — llms.txt</h3>
            <p class="text-sm text-slate-500 mb-3">A short markdown file (<a href="https://llmstxt.org" target="_blank" rel="noopener" class="text-blue-600 hover:underline">llmstxt.org</a>) that tells a language model what the site is and links to its most useful pages. It is built from your content: your site title as the heading, your tagline as the summary, then your pages, latest articles and topics.</p>
            <?= $check('llms_enabled', 'Publish /llms.txt') ?>
            <div class="grid sm:grid-cols-2 gap-x-6">
                <?= $check('llms_pages', 'List pages', 'About, Contact and your other pages.') ?>
                <?= $check('llms_categories', 'List topics', 'Each category with its number of articles.') ?>
            </div>
            <div class="grid sm:grid-cols-2 gap-4 mt-3">
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Latest articles to list</label>
                    <input type="number" name="llms_posts" min="0" max="500" value="<?= (int) ($v['llms_posts'] ?? 30) ?>" class="<?= $input ?>">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Articles in llms-full.txt</label>
                    <input type="number" name="llms_full_limit" min="1" max="500" value="<?= (int) ($v['llms_full_limit'] ?? 50) ?>" class="<?= $input ?>">
                </div>
            </div>
            <div class="mt-3"><?= $check('llms_full', 'Also publish /llms-full.txt', 'The full text of your pages and recent articles in one file, for agents that want everything at once.') ?></div>
            <div class="mt-3">
                <label class="block text-sm font-medium text-slate-700 mb-1.5">Introduction</label>
                <textarea name="llms_intro" rows="3" class="<?= $input ?>" placeholder="What the site is about, who writes it, and what a reader will find here."><?= $e($v['llms_intro'] ?? '') ?></textarea>
                <p class="text-xs text-slate-500 mt-1">Markdown. Shown after the summary line.</p>
            </div>
            <div class="mt-3">
                <label class="block text-sm font-medium text-slate-700 mb-1.5">Extra sections</label>
                <textarea name="llms_extra" rows="4" class="<?= $input ?> font-mono text-sm" placeholder="## Guides&#10;&#10;- [Getting started](https://example.com/start): the first steps"><?= $e($v['llms_extra'] ?? '') ?></textarea>
                <p class="text-xs text-slate-500 mt-1">Markdown, added before the Optional section. Use <code>## Heading</code> and <code>- [title](url): note</code> lines.</p>
            </div>
        </div>

        <!-- WebMCP -->
        <div class="<?= $card ?>">
            <h3 class="font-semibold text-slate-900 mb-1">In-page tools — WebMCP</h3>
            <p class="text-sm text-slate-500 mb-3">WebMCP lets an AI agent working in a visitor's browser use your site through named tools instead of guessing at buttons. Forms become tools the agent can fill in (the visitor still sends them), and read-only tools answer questions about your content directly.</p>
            <?= $check('webmcp_enabled', 'Enable WebMCP') ?>
            <div class="pl-7">
                <?= $check('webmcp_forms', 'Describe every form for agents', 'Adds a tool name and description to each form on your pages, and a description to each field. Search forms can be submitted by the agent; other forms are filled in for the visitor to review and send. Sign-in forms are never exposed.') ?>
                <?= $check('webmcp_comments', 'Comment form as a tool', 'Agents can draft a comment for the visitor, who reviews and sends it.') ?>
                <?= $check('tool_recent', 'Tool: list recent articles', 'Read-only. Returns titles, links, dates, topics and summaries.') ?>
                <?= $check('tool_categories', 'Tool: list topics', 'Read-only. Returns your categories with their article counts.') ?>
            </div>
            <?php if (!empty($tools)): ?>
                <p class="text-xs text-slate-500 mt-2">Registered on every page: <?php foreach ($tools as $i => $t): ?><code class="bg-slate-100 rounded px-1"><?= $e($t['name']) ?></code><?= $i < count($tools) - 1 ? ', ' : '' ?><?php endforeach; ?>. Apps can add their own.</p>
            <?php endif; ?>
            <div class="mt-4">
                <label class="block text-sm font-medium text-slate-700 mb-1.5">Chrome origin trial token</label>
                <input type="text" name="webmcp_token" value="<?= $e($v['webmcp_token'] ?? '') ?>" class="<?= $input ?> font-mono text-xs" placeholder="Paste the token for <?= $e(parse_url($o, PHP_URL_HOST)) ?>">
                <p class="text-xs text-slate-500 mt-1">While WebMCP is an experiment, Chrome only exposes it on sites registered for the <a href="https://developer.chrome.com/origintrials/#/register_trial/4163014905550602241" target="_blank" rel="noopener" class="text-blue-600 hover:underline">WebMCP origin trial</a> (free). Register your domain there and paste the token; Lighthouse's WebMCP checks need it too.</p>
            </div>
        </div>

        <!-- ARD catalog -->
        <div class="<?= $card ?>">
            <h3 class="font-semibold text-slate-900 mb-1">Discovery catalog — Agentic Resource Discovery</h3>
            <p class="text-sm text-slate-500 mb-3">A machine-readable list of what your site offers agents — the llms.txt files, feed, sitemap and WebMCP tools — published at <code>/.well-known/ard.json</code> and, for tools that look there, <code>/.well-known/ai-catalog.json</code>. Agents find it through robots.txt and a link on every page.</p>
            <?= $check('catalog_enabled', 'Publish the catalog') ?>
            <div class="mt-3">
                <label class="block text-sm font-medium text-slate-700 mb-1.5">Questions your site answers</label>
                <textarea name="catalog_queries" rows="4" class="<?= $input ?>" placeholder="how do I build a heart rate monitor with an LM358&#10;simple Arduino projects for beginners"><?= $e($v['catalog_queries'] ?? '') ?></textarea>
                <p class="text-xs text-slate-500 mt-1">Two to five, one per line, as a person would ask an assistant. Agent search services match on these. Left empty, they are written from your topics.</p>
            </div>
            <div class="mt-3">
                <label class="block text-sm font-medium text-slate-700 mb-1.5">Extra entries <span class="font-normal text-slate-400">(advanced)</span></label>
                <textarea name="catalog_extra" rows="5" class="<?= $input ?> font-mono text-xs" placeholder='[{"identifier": "urn:air:<?= $e(parse_url($o, PHP_URL_HOST) ?: 'example.com') ?>:server:support", "displayName": "Support MCP server", "type": "application/mcp-server-card+json", "url": "https://…/mcp.json"}]'><?= $e($v['catalog_extra'] ?? '') ?></textarea>
                <p class="text-xs <?= !empty($customInvalid) ? 'text-rose-600' : 'text-slate-500' ?> mt-1"><?= !empty($customInvalid) ? 'These entries are not valid and are left out of the catalog. ' : '' ?>JSON: one ARD entry or an array. Each needs an <code>identifier</code> (urn:air:your-domain:namespace:name), a <code>displayName</code>, a media <code>type</code>, and exactly one of <code>url</code> or <code>data</code>.</p>
            </div>
        </div>

        <!-- Structured data -->
        <div class="<?= $card ?>">
            <h3 class="font-semibold text-slate-900 mb-1">Structured data</h3>
            <?= $check('jsonld_website', 'Describe the site and its search on the home page', 'schema.org WebSite data with a SearchAction, so agents and search engines know how to search your site.') ?>
        </div>

        <div class="flex justify-end">
            <button type="submit" class="px-5 py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium">Save changes</button>
        </div>
    </form>
</div>
<script>
// Allow/Block segmented controls: highlight the chosen side immediately.
document.querySelectorAll('[role=radiogroup]').forEach(function (g) {
    g.addEventListener('change', function () {
        g.querySelectorAll('label').forEach(function (l) {
            var i = l.querySelector('input'); var block = i.value === 'block';
            l.className = 'px-3 py-1.5 cursor-pointer ' + (i.checked ? (block ? 'bg-rose-600 text-white' : 'bg-blue-600 text-white') : 'bg-white text-slate-700');
        });
    });
});
</script>
<?php $this->endSection(); ?>
