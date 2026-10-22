<?php $this->extend('layouts.app'); ?>
<?php $this->section('content'); ?>
<?php
require_once __DIR__ . '/_ui.php';
$v = $values ?? [];
$on = static fn(string $k): bool => ($v[$k] ?? '0') === '1';
$e = static fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
// These switches post nothing when off, as before; the saver reads a missing key as off.
$sw = static fn(string $key, string $label, string $help = '', array $o = []): string
    => bh_set_toggle($key, $on($key), $label, $help, $o + ['hidden0' => false]);
$policy = static function (string $key, string $label, array $bots, string $help) use ($v, $e): string {
    $cur = ($v[$key] ?? 'allow') === 'block' ? 'block' : 'allow';
    $seg = '<div class="bh-seg" role="radiogroup" aria-label="' . $e($label) . '">';
    foreach (['allow' => 'Allow', 'block' => 'Block'] as $val => $txt) {
        $seg .= '<label><input type="radio" class="sr-only" name="' . $e($key) . '" value="' . $val . '"' . ($cur === $val ? ' checked' : '') . '>' . $txt . '</label>';
    }
    $seg .= '</div>';
    return '<div class="bh-set-row bh-set-row--switch"><div class="bh-set-row__text"><span class="bh-set-row__label">' . $e($label) . '</span>'
        . '<p class="bh-set-row__help">' . $help . '</p><p class="bh-set-row__help font-mono text-[0.6875rem] text-slate-400">' . $e(implode(', ', $bots)) . '</p></div>' . $seg . '</div>';
};
$o = $origin ?? '';
$rows = [
    ['Crawler rules', '/robots.txt', $on('robots_enabled'), !empty($staticRobots) ? 'A robots.txt file in the site folder is served instead of this one.' : ''],
    ['Site summary for AI', '/llms.txt', $on('enabled') && $on('llms_enabled'), !empty($staticLlms) ? 'An llms.txt file in the site folder is served instead of this one.' : ''],
    ['Full text for AI', '/llms-full.txt', $on('enabled') && $on('llms_enabled') && $on('llms_full'), ''],
    ['Discovery catalog (ARD)', '/.well-known/ard.json', $on('enabled') && $on('catalog_enabled'), ($catalogCount ?? 0) . ' entr' . (($catalogCount ?? 0) === 1 ? 'y' : 'ies') . '. Also at /.well-known/ai-catalog.json.'],
    ['In-page tools (WebMCP)', 'every public page', $on('enabled') && $on('webmcp_enabled'), count($tools ?? []) . ' tool' . (count($tools ?? []) === 1 ? '' : 's') . ($on('webmcp_forms') ? ', plus forms annotated' : '') . '.'],
];
$host = (string) (parse_url($o, PHP_URL_HOST) ?: 'example.com');
?>
<div class="bh-set">
    <?php $this->include('settings._head', compact('tab', 'base')); ?>

    <form method="POST" action="<?= $base ?>/admin/settings/ai" class="bh-set__form">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">

        <?= bh_set_card_open('AI agent access', 'Which crawlers may visit, a summary written for language models, a catalog of what the site offers, and tools agents in the browser can use.', [
            'action' => '<a href="https://pagespeed.web.dev/analysis?url=' . rawurlencode($o . '/') . '" target="_blank" rel="noopener" class="bh-btn bh-btn--secondary bh-btn--sm">' . icon('arrow-top-right-on-square', 'w-4 h-4') . '<span class="hidden sm:inline">Test in PageSpeed</span></a>',
        ]) ?>
            <?= $sw('enabled', 'Make the site accessible to AI agents', 'Turns on llms.txt, the discovery catalog, WebMCP and the structured data below. Crawler rules in robots.txt apply either way.') ?>
            <div class="bh-set-card__block" data-settings-live="ai-status">
                <ul class="m-0 grid list-none gap-0 divide-y divide-slate-100 rounded-xl border border-slate-200 p-0">
                <?php foreach ($rows as [$label, $path, $live, $note]): ?>
                    <li class="flex items-start justify-between gap-4 px-3.5 py-2.5">
                        <div class="min-w-0">
                            <div class="text-sm font-medium text-slate-900"><?= $e($label) ?></div>
                            <div class="mt-0.5 text-xs text-slate-500 [overflow-wrap:anywhere]"><?php if ($path[0] === '/'): ?><a class="font-mono text-accent-600 no-underline hover:underline" href="<?= $e($o . $path) ?>" target="_blank" rel="noopener"><?= $e($path) ?></a><?php else: ?><?= $e($path) ?><?php endif; ?><?= $note !== '' ? ' · ' . $e($note) : '' ?></div>
                        </div>
                        <span class="bh-dtone <?= $live ? 'bh-dtone--blue' : 'bh-dtone--slate' ?> mt-0.5"><?= $live ? 'On' : 'Off' ?></span>
                    </li>
                <?php endforeach; ?>
                </ul>
            </div>
        <?= bh_set_card_close() ?>

        <?= bh_set_card_open('Crawler access', 'robots.txt: well-behaved crawlers read these rules before visiting. AI companies use separate crawlers for separate jobs, so each kind can be allowed or blocked on its own.') ?>
            <?= $sw('robots_enabled', 'Serve robots.txt', 'Includes your rules, the AI crawler choices below and your sitemap.') ?>
            <?= $sw('robots_agentmap', 'Add an Agentmap line for the discovery catalog', 'ARD\'s own directive. Most robots.txt checkers, including Lighthouse and Search Console, report it as an unknown directive, so it is off by default. Agents find the catalog through each page\'s link to it either way.', ['sub' => true]) ?>
            <?= $policy('ai_training', 'AI training crawlers', $bots['training'] ?? [], 'Collect pages to train AI models. Blocking them does not affect search engines or AI answers.') ?>
            <?= $policy('ai_search', 'AI search crawlers', $bots['search'] ?? [], 'Index pages so AI search tools can cite and link to them.') ?>
            <?= $policy('ai_user', 'AI assistants browsing for a person', $bots['user'] ?? [], 'Open a page because someone asked an assistant about it: the closest thing to a human visitor.') ?>
            <?= bh_set_row('Your rules', '<textarea id="set-robots-rules" name="robots_rules" rows="5" class="bh-textarea font-mono text-sm">' . $e($robotsRules ?? '') . '</textarea>', 'Placed first in robots.txt. The AI crawler groups, Sitemap and Agentmap lines are added after them.', ['for' => 'set-robots-rules', 'wide' => true]) ?>
        <?= bh_set_card_close() ?>

        <?= bh_set_card_open('Site summary for AI', 'llms.txt (<a href="https://llmstxt.org" target="_blank" rel="noopener" class="font-medium text-accent-600 no-underline hover:underline">llmstxt.org</a>): a short markdown file that tells a language model what the site is, built from your title, tagline, pages, latest articles and topics.') ?>
            <?= $sw('llms_enabled', 'Publish /llms.txt') ?>
            <?= $sw('llms_pages', 'List pages', 'About, Contact and your other pages.', ['sub' => true]) ?>
            <?= $sw('llms_categories', 'List topics', 'Each category with its number of articles.', ['sub' => true]) ?>
            <?= bh_set_row('Latest articles to list', '<input type="number" id="set-llms-posts" name="llms_posts" min="0" max="500" class="bh-input max-w-[8rem]" value="' . (int) ($v['llms_posts'] ?? 30) . '">', '', ['for' => 'set-llms-posts']) ?>
            <?= $sw('llms_full', 'Also publish /llms-full.txt', 'The full text of your pages and recent articles in one file, for agents that want everything at once.') ?>
            <?= bh_set_row('Articles in llms-full.txt', '<input type="number" id="set-llms-full-limit" name="llms_full_limit" min="1" max="500" class="bh-input max-w-[8rem]" value="' . (int) ($v['llms_full_limit'] ?? 50) . '">', '', ['for' => 'set-llms-full-limit', 'sub' => true]) ?>
            <?= bh_set_row('Introduction', '<textarea id="set-llms-intro" name="llms_intro" rows="3" class="bh-textarea" placeholder="What the site is about, who writes it, and what a reader will find here.">' . $e($v['llms_intro'] ?? '') . '</textarea>', 'Markdown. Shown after the summary line.', ['for' => 'set-llms-intro', 'wide' => true]) ?>
            <?= bh_set_row('Extra sections', '<textarea id="set-llms-extra" name="llms_extra" rows="4" class="bh-textarea font-mono text-sm" placeholder="## Guides&#10;&#10;- [Getting started](https://example.com/start): the first steps">' . $e($v['llms_extra'] ?? '') . '</textarea>', 'Markdown, added before the Optional section. Use <code>## Heading</code> and <code>- [title](url): note</code> lines.', ['for' => 'set-llms-extra', 'wide' => true]) ?>
        <?= bh_set_card_close() ?>

        <?= bh_set_card_open('In-page tools', 'WebMCP lets an AI agent in a visitor\'s browser use your site through named tools instead of guessing at buttons. Forms become tools the agent fills in (the visitor still sends them); read-only tools answer questions about your content.') ?>
            <?= $sw('webmcp_enabled', 'Enable WebMCP', !empty($tools) ? 'Registered on every page: ' . implode(', ', array_map(static fn($t) => '<code>' . $e($t['name']) . '</code>', $tools)) . '. Apps can add their own.' : '') ?>
            <?= $sw('webmcp_forms', 'Describe every form for agents', 'Adds a tool name and description to each form on your pages, and a description to each field. Search forms can be submitted by the agent; other forms are filled in for the visitor to review and send. Sign-in forms are never exposed.', ['sub' => true]) ?>
            <?= $sw('webmcp_comments', 'Comment form as a tool', 'Agents can draft a comment for the visitor, who reviews and sends it.', ['sub' => true]) ?>
            <?= $sw('tool_recent', 'Tool: list recent articles', 'Read-only. Returns titles, links, dates, topics and summaries.', ['sub' => true]) ?>
            <?= $sw('tool_categories', 'Tool: list topics', 'Read-only. Returns your categories with their article counts.', ['sub' => true]) ?>
            <?= bh_set_row('Chrome origin trial token', '<input type="text" id="set-webmcp-token" name="webmcp_token" class="bh-input font-mono text-xs" placeholder="Paste the token for ' . $e(parse_url($o, PHP_URL_HOST)) . '" value="' . $e($v['webmcp_token'] ?? '') . '">', 'While WebMCP is an experiment, Chrome only exposes it on sites registered for the <a href="https://developer.chrome.com/origintrials/#/register_trial/4163014905550602241" target="_blank" rel="noopener">WebMCP origin trial</a> (free). Register your domain there and paste the token; Lighthouse\'s WebMCP checks need it too.', ['for' => 'set-webmcp-token']) ?>
        <?= bh_set_card_close() ?>

        <?= bh_set_card_open('Discovery catalog', 'Agentic Resource Discovery: a machine-readable list of what your site offers agents (the llms.txt files, feed, sitemap and WebMCP tools) at <code>/.well-known/ard.json</code> and <code>/.well-known/ai-catalog.json</code>.') ?>
            <?= $sw('catalog_enabled', 'Publish the catalog') ?>
            <?= bh_set_row('Questions your site answers', '<textarea id="set-catalog-queries" name="catalog_queries" rows="4" class="bh-textarea" placeholder="how do I build a heart rate monitor with an LM358&#10;simple Arduino projects for beginners">' . $e($v['catalog_queries'] ?? '') . '</textarea>', 'Two to five, one per line, as a person would ask an assistant. Agent search services match on these. Left empty, they are written from your topics.', ['for' => 'set-catalog-queries', 'wide' => true]) ?>
            <div class="bh-set-row bh-set-row--wide">
                <div class="bh-set-row__text">
                    <label class="bh-set-row__label" for="set-catalog-extra">Extra entries <span class="font-normal text-slate-400">(advanced)</span></label>
                    <p data-settings-live="catalog-note" class="bh-set-row__help<?= !empty($customInvalid) ? ' text-red-600' : '' ?>"><?= !empty($customInvalid) ? 'These entries are not valid and are left out of the catalog. ' : '' ?>JSON: one ARD entry or an array. Each needs an <code>identifier</code> (urn:air:your-domain:namespace:name), a <code>displayName</code>, a media <code>type</code>, and exactly one of <code>url</code> or <code>data</code>.</p>
                </div>
                <div class="bh-set-row__control"><textarea id="set-catalog-extra" name="catalog_extra" rows="5" class="bh-textarea font-mono text-xs" placeholder='[{"identifier": "urn:air:<?= $e($host) ?>:server:support", "displayName": "Support MCP server", "type": "application/mcp-server-card+json", "url": "https://…/mcp.json"}]'><?= $e($v['catalog_extra'] ?? '') ?></textarea></div>
            </div>
        <?= bh_set_card_close() ?>

        <?= bh_set_card_open('Structured data') ?>
            <?= $sw('jsonld_website', 'Describe the site and its search on the home page', 'schema.org WebSite data with a SearchAction, so agents and search engines know how to search your site.') ?>
        <?= bh_set_card_close() ?>

        <?= bh_set_save() ?>
    </form>
</div>
<?php $this->endSection(); ?>
