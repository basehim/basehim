<?php $this->extend('layouts.app'); ?>
<?php $this->section('content'); ?>
<?php
$scheme  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$baseUrl = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $base;
$mcpUrl  = $baseUrl . '/mcp';
$prm     = $baseUrl . '/.well-known/oauth-protected-resource';
?>

<?php $this->include('api._nav', ['subtab' => $subtab]); ?>
<div class="space-y-5 sm:space-y-6">

    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3 sm:gap-4">
        <div class="min-w-0">
            <h2 class="text-xl font-semibold text-slate-900">MCP Server</h2>
            <p class="text-sm text-slate-500 mt-1">Let AI assistants read and write your content, safely and on your terms.</p>
        </div>
        <span class="inline-flex items-center gap-1.5 shrink-0 px-2.5 py-1 rounded-full bg-emerald-50 border border-emerald-200 text-xs font-semibold text-emerald-700">
            <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span> Enabled
        </span>
    </div>

    <!-- Endpoint -->
    <div class="bg-white rounded-xl border border-slate-200 p-5">
        <h3 class="text-sm font-semibold text-slate-900 mb-1">Your server URL</h3>
        <p class="text-xs text-slate-500 mb-3">This is the only thing most clients need. Note it is at the site root — <strong>not</strong> under <code class="bg-slate-100 px-1 rounded">/api/v1</code>.</p>
        <div class="flex items-stretch gap-2">
            <code class="flex-1 min-w-0 break-all bg-slate-50 border border-slate-200 rounded-lg px-4 py-2.5 text-sm text-slate-800 font-mono select-all"><?= htmlspecialchars($mcpUrl) ?></code>
            <button type="button" data-copy="<?= htmlspecialchars($mcpUrl, ENT_QUOTES) ?>"
                    class="shrink-0 inline-flex items-center px-3 py-2.5 bg-slate-100 hover:bg-slate-200 rounded-lg text-xs font-medium text-slate-600 transition whitespace-nowrap">
                <?= icon('document-duplicate', 'w-4 h-4 mr-1') ?> Copy
            </button>
        </div>
    </div>

    <!-- Claude connector -->
    <div class="bg-white rounded-xl border border-slate-200 p-5">
        <h3 class="text-sm font-semibold text-slate-900 mb-1">
            <?= icon('cpu-chip', 'w-4 h-4 mr-1 inline-block align-text-bottom text-violet-500') ?>
            Connect Claude (web, desktop or mobile)
        </h3>
        <p class="text-xs text-slate-500 mb-4">Claude's connector UI asks for an OAuth Client ID and Secret. <strong>Leave both blank</strong> — this server supports dynamic client registration, so Claude registers itself.</p>

        <ol class="space-y-3">
            <?php foreach ([
                ['In Claude, open <strong>Settings &rarr; Connectors</strong> and choose <strong>Add custom connector</strong>.', null],
                ['Paste the server URL above.', $mcpUrl],
                ['Leave <strong>Advanced settings</strong> empty — no Client ID, no Client Secret — and click <strong>Add</strong>.', null],
                ['Click <strong>Connect</strong>. You will be sent here to sign in and approve the permissions.', null],
                ['In any chat, open the <strong>+</strong> menu &rarr; <strong>Add connectors</strong> and switch it on.', null],
            ] as $i => [$text, $code]): ?>
            <li class="flex gap-3">
                <span class="w-6 h-6 rounded-full bg-violet-100 text-violet-700 grid place-items-center text-xs font-bold shrink-0 mt-0.5"><?= $i + 1 ?></span>
                <div class="min-w-0 flex-1">
                    <p class="text-sm text-slate-700"><?= $text ?></p>
                    <?php if ($code): ?>
                    <pre class="mt-2 bg-slate-900 text-emerald-300 rounded-lg p-3 text-xs overflow-x-auto"><?= htmlspecialchars($code) ?></pre>
                    <?php endif; ?>
                </div>
            </li>
            <?php endforeach; ?>
        </ol>

        <div class="mt-4 rounded-lg bg-amber-50 border border-amber-200 px-3 py-2.5">
            <p class="text-xs text-amber-800">
                <?= icon('exclamation-triangle', 'w-3.5 h-3.5 mr-1 inline-block align-text-bottom') ?>
                Claude connects from Anthropic's servers, so this site must be reachable from the public internet. A local install needs a tunnel (e.g. ngrok).
            </p>
        </div>
    </div>

    <!-- Direct clients -->
    <div class="bg-white rounded-xl border border-slate-200 p-5">
        <h3 class="text-sm font-semibold text-slate-900 mb-1">
            <?= icon('command-line', 'w-4 h-4 mr-1 inline-block align-text-bottom text-slate-400') ?>
            Direct clients (Claude Code, curl, scripts)
        </h3>
        <p class="text-xs text-slate-500 mb-3">Tools that let you set headers can skip OAuth and use an API key from <a href="<?= $base ?>/admin/api/keys" class="text-blue-600 hover:underline">API Keys</a>.</p>
        <pre class="bg-slate-900 text-slate-200 rounded-lg p-3 text-xs overflow-x-auto">curl -X POST <?= htmlspecialchars($mcpUrl) ?> \
  -H "Authorization: Bearer basehim_your_key" \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/list","params":{}}'</pre>
        <p class="text-[11px] text-slate-400 mt-2">Credentials must go in the header — the MCP spec forbids tokens in the query string.</p>
    </div>

    <!-- Tools -->
    <div class="bg-white rounded-xl border border-slate-200 p-5">
        <h3 class="text-sm font-semibold text-slate-900 mb-1">What an assistant can do</h3>
        <p class="text-xs text-slate-500 mb-4">Twenty-five tools, each gated by the scopes you approve on the consent screen. The default is read-only — approve write scopes only when you want an assistant drafting, tagging, moderating or maintaining the site. Most destructive actions are soft: <code class="bg-slate-100 px-1 rounded">trash_post</code> is reversible and settings secrets are always redacted. The one exception is <code class="bg-slate-100 px-1 rounded">delete_menu</code>, which permanently removes a menu and its items — it needs <code class="bg-slate-100 px-1 rounded">menus:write</code>.</p>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-200">
                        <th class="text-left font-semibold text-slate-700 py-2 pr-4">Tool</th>
                        <th class="text-left font-semibold text-slate-700 py-2 pr-4">Does</th>
                        <th class="text-left font-semibold text-slate-700 py-2 whitespace-nowrap">Scope</th>
                    </tr>
                </thead>
                <tbody class="text-slate-600">
                <?php foreach ([
                    ['get_site_info',    'Title, tagline, URL and post counts', '—'],
                    ['search_content',   'Full-text search across posts and pages', 'posts:read'],
                    ['list_posts',       'List posts, newest first', 'posts:read'],
                    ['list_pages',       'List pages, newest first', 'posts:read'],
                    ['get_post',         'Read one post in full', 'posts:read'],
                    ['get_page',         'Read one page in full', 'posts:read'],
                    ['create_post',      'Create a post (draft by default)', 'posts:write'],
                    ['create_page',      'Create a page (draft by default)', 'posts:write'],
                    ['update_post',      'Update an existing post', 'posts:write'],
                    ['update_page',      'Update an existing page', 'posts:write'],
                    ['trash_post',       'Move a post/page to the trash (reversible)', 'posts:write'],
                    ['set_post_terms',   'Set the categories/tags on a post', 'posts:write'],
                    ['list_taxonomies',  'List categories and tags', 'taxonomies:read'],
                    ['create_term',      'Create a category or tag', 'taxonomies:write'],
                    ['list_media',       'Browse the media library for embeddable URLs', 'media:read'],
                    ['get_media',        'One media item: URL, size, alt, caption', 'media:read'],
                    ['list_comments',    'List comments (find what needs moderating)', 'comments:read'],
                    ['moderate_comment', 'Approve, unapprove, spam or trash a comment', 'comments:write'],
                    ['get_settings',     'Read a settings group (secrets redacted)', 'settings:read'],
                    ['list_users',       'List site users and roles', 'users:read'],
                    ['list_widget_areas','List theme widget areas and their widget counts', 'settings:read'],
                    ['get_widget_area',  'One widget area with its widgets and rendered HTML', 'settings:read'],
                    ['list_menus',       'List navigation menus (id, name, location, item count)', 'menus:read'],
                    ['delete_menu',      'Permanently delete a menu and its items', 'menus:write'],
                    ['regenerate_thumbnails', 'Rebuild all image thumbnails from current Media settings', 'media:write'],
                ] as [$tool, $does, $scope]): ?>
                    <tr class="border-b border-slate-100 last:border-0">
                        <td class="py-2 pr-4 font-mono text-xs text-slate-800 whitespace-nowrap"><?= $tool ?></td>
                        <td class="py-2 pr-4 text-xs"><?= $does ?></td>
                        <td class="py-2 text-xs whitespace-nowrap">
                            <?php if ($scope === '—'): ?><span class="text-slate-400">—</span>
                            <?php else: ?><code class="bg-slate-100 px-1.5 py-0.5 rounded text-[11px]"><?= $scope ?></code><?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="text-[11px] text-slate-400 mt-3">Recent posts are also exposed as MCP <em>resources</em> at <code class="bg-slate-100 px-1 rounded">basehim://post/{slug}</code>.</p>
    </div>

    <!-- How auth works -->
    <div class="bg-white rounded-xl border border-slate-200 p-5">
        <h3 class="text-sm font-semibold text-slate-900 mb-3">How authorisation works</h3>
        <ol class="space-y-2 text-xs text-slate-600 mb-4">
            <li><strong>1.</strong> The client calls <code class="bg-slate-100 px-1 rounded">/mcp</code> with no token and gets <code class="bg-slate-100 px-1 rounded">401</code> plus a <code class="bg-slate-100 px-1 rounded">WWW-Authenticate</code> header.</li>
            <li><strong>2.</strong> It follows that header to the resource metadata and discovers this site's authorization server.</li>
            <li><strong>3.</strong> It registers itself (RFC 7591) — which is why there is no Client ID to copy.</li>
            <li><strong>4.</strong> You sign in and approve; the client gets a token scoped to what you allowed.</li>
        </ol>
        <div class="grid gap-3 md:grid-cols-2">
            <div class="min-w-0">
                <p class="text-[11px] font-semibold text-slate-500 uppercase tracking-wide mb-1.5">Resource metadata</p>
                <pre class="bg-slate-900 text-sky-200 rounded-lg p-3 text-[11px] overflow-x-auto"><?= htmlspecialchars($prm) ?></pre>
            </div>
            <div class="min-w-0">
                <p class="text-[11px] font-semibold text-slate-500 uppercase tracking-wide mb-1.5">Standards implemented</p>
                <pre class="bg-slate-900 text-slate-300 rounded-lg p-3 text-[11px] overflow-x-auto">OAuth 2.1 + PKCE (S256)
RFC 9728  protected resource
RFC 8414  server metadata
RFC 7591  dynamic registration
RFC 8707  resource indicators</pre>
            </div>
        </div>
    </div>

    <!-- Troubleshooting -->
    <div class="bg-white rounded-xl border border-slate-200 p-5">
        <h3 class="text-sm font-semibold text-slate-900 mb-3">If it won't connect</h3>
        <dl class="space-y-3">
            <?php foreach ([
                ['Claude asks for a Client ID and Secret', 'Leave them blank. They are only for servers without dynamic registration — this one has it.'],
                ['"Could not reach server"', 'The site must be publicly reachable. Check it loads in a private window, and that no firewall or IP allowlist blocks Anthropic.'],
                ['Connects, but no tools appear', 'The tool list follows your approved scopes. Reconnect and approve read access, then check API → API Keys scopes.'],
                ['Works in Claude Code but not the web connector', 'Claude Code sends an API key header; the web connector only speaks OAuth. That is expected — use the URL alone and approve the consent screen.'],
            ] as [$q, $a]): ?>
            <div class="border-l-2 border-slate-200 pl-3">
                <dt class="text-xs font-semibold text-slate-700"><?= $q ?></dt>
                <dd class="text-xs text-slate-500 mt-0.5"><?= $a ?></dd>
            </div>
            <?php endforeach; ?>
        </dl>
    </div>
</div>

<script>
document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-copy]');
    if (!b) return;
    navigator.clipboard.writeText(b.getAttribute('data-copy'));
    var original = b.innerHTML;
    b.innerHTML = <?= json_encode(icon('check', 'w-4 h-4 mr-1')) ?> + ' Copied';
    setTimeout(function () { b.innerHTML = original; }, 1600);
});
</script>

<?php $this->endSection(); ?>
