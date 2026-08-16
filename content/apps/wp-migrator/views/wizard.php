<?php
/**
 * WP Migrator Wizard
 *
 * @var \Basehim\WpMigrator\App $app
 * @var array|null $job
 * @var array|null $lastJob
 * @var string $csrf
 * @var int $maxUpload
 * @var string $base
 */
$running = $job && in_array($job['status'], ['pending','running'], true);
$cssUrl = $app->asset('css/wizard.css');
$jsUrl  = $app->asset('js/wizard.js');
?>
<link rel="stylesheet" href="<?= htmlspecialchars($cssUrl) ?>">

<div class="mb-5 flex items-start justify-between gap-4 flex-wrap">
    <div>
        <h2 class="text-xl font-semibold text-slate-900 flex items-center gap-2">
            <?= icon('globe-alt', 'w-4 h-4 text-blue-600') ?> WordPress Migrator
        </h2>
        <p class="text-sm text-slate-500">Move a WordPress site to Basehim — posts, pages, users, comments, media, SEO meta, redirects.</p>
    </div>
    <?php if ($lastJob && !$running): ?>
        <form method="POST" action="<?= $base ?>/admin/wp-migrator/reset"
              onsubmit="return confirm('Clear all migration history and start over? This wipes the ID map and removes redirects.');">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
            <button class="px-3 py-2 text-xs border border-slate-300 hover:bg-slate-50 rounded-lg font-medium text-slate-600">
                <?= icon('arrow-uturn-left', 'w-4 h-4 mr-1') ?> Reset migration data
            </button>
        </form>
    <?php endif; ?>
</div>

<!-- Wizard panel: shows form OR progress depending on state. -->
<div id="wpmig-wizard"
     data-base="<?= htmlspecialchars($base) ?>"
     data-csrf="<?= htmlspecialchars($csrf) ?>"
     data-running="<?= $running ? '1' : '0' ?>">

    <?php if (!$running): ?>

    <!-- ============================================================== -->
    <!-- Setup form                                                     -->
    <!-- ============================================================== -->
    <form id="wpmig-setup" class="bg-white border border-slate-200 rounded-xl p-6 max-w-4xl" enctype="multipart/form-data">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">

        <!-- Source tabs -->
        <div class="mb-6">
            <h3 class="font-semibold text-slate-900 mb-3">1. Source</h3>
            <div class="flex gap-2 mb-4" role="tablist">
                <button type="button" class="wpmig-tab px-4 py-2 text-sm font-medium rounded-lg border border-slate-200 hover:bg-slate-50 active:bg-blue-50" data-tab="wxr">
                    <?= icon('code-bracket-square', 'w-4 h-4 mr-1') ?> WXR Export File
                </button>
                <button type="button" class="wpmig-tab px-4 py-2 text-sm font-medium rounded-lg border border-slate-200 hover:bg-slate-50" data-tab="mysql">
                    <?= icon('circle-stack', 'w-4 h-4 mr-1') ?> Direct MySQL
                </button>
            </div>
            <input type="hidden" name="source" id="wpmig-source" value="wxr">

            <!-- WXR -->
            <div class="wpmig-pane" data-pane="wxr">
                <label class="block text-sm font-medium text-slate-700 mb-1">Upload WXR (.xml) file</label>
                <input type="file" name="wxr_file" accept=".xml,application/xml,text/xml"
                       class="block w-full text-sm file:mr-3 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-medium file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100">
                <p class="text-xs text-slate-500 mt-1">
                    In WordPress: <em>Tools &rarr; Export</em> &rarr; "All content". Max upload: <?= number_format($maxUpload / 1048576, 0) ?> MB.
                </p>
            </div>

            <!-- MySQL -->
            <div class="wpmig-pane hidden" data-pane="mysql">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Host</label>
                        <input type="text" name="mysql_host" value="127.0.0.1" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Port</label>
                        <input type="number" name="mysql_port" value="3306" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Database</label>
                        <input type="text" name="mysql_database" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Table prefix</label>
                        <input type="text" name="mysql_prefix" value="wp_" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Username</label>
                        <input type="text" name="mysql_username" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Password</label>
                        <input type="password" name="mysql_password" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm">
                    </div>
                </div>
                <p class="text-xs text-slate-500 mt-2">
                    Read-only credentials are sufficient. Connection is closed at the end of each batch.
                </p>
            </div>
        </div>

        <!-- Options -->
        <div class="mb-6">
            <h3 class="font-semibold text-slate-900 mb-3">2. What to import</h3>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-2 text-sm">
                <?php foreach ([
                    'opt_users'          => 'Users & authors',
                    'opt_taxonomies'     => 'Categories & tags',
                    'opt_media'          => 'Media (downloads + rehosts)',
                    'opt_posts'          => 'Posts & pages (incl. postmeta, SEO)',
                    'opt_featured_media' => 'Featured images',
                    'opt_comments'       => 'Comments',
                    'opt_menus'          => 'Menus (MySQL source only)',
                    'opt_redirects'      => 'URL redirects (301)',
                    'opt_rewrite_content'=> 'Rewrite inline URLs in content',
                ] as $name => $label): ?>
                <label class="flex items-center gap-2">
                    <input type="checkbox" name="<?= $name ?>" value="1" checked class="w-4 h-4 text-blue-600 rounded border-slate-300">
                    <span><?= htmlspecialchars($label) ?></span>
                </label>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Auth options -->
        <div class="mb-6">
            <h3 class="font-semibold text-slate-900 mb-3">3. User options</h3>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Default password for imported users</label>
                    <input type="text" name="default_password" placeholder="Leave blank to auto-generate"
                           class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm">
                    <p class="text-xs text-slate-500 mt-1">Users will need to reset on first login.</p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Default role</label>
                    <select name="default_role" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm">
                        <option value="author" selected>Author</option>
                        <option value="editor">Editor</option>
                        <option value="contributor">Contributor</option>
                        <option value="subscriber">Subscriber</option>
                    </select>
                </div>
            </div>
        </div>

        <div class="pt-4 border-t border-slate-100 flex items-center gap-3">
            <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium">
                <?= icon('play', 'w-4 h-4 mr-1') ?> Start migration
            </button>
            <span id="wpmig-setup-msg" class="text-sm text-slate-500"></span>
        </div>
    </form>

    <?php endif; ?>

    <!-- ============================================================== -->
    <!-- Progress panel (shown while running)                            -->
    <!-- ============================================================== -->
    <div id="wpmig-progress" class="bg-white border border-slate-200 rounded-xl p-6 max-w-4xl <?= $running ? '' : 'hidden' ?>">
        <div class="flex items-center justify-between mb-4">
            <h3 class="font-semibold text-slate-900">Migration in progress</h3>
            <button type="button" id="wpmig-cancel" class="text-xs px-3 py-1.5 border border-red-200 hover:bg-red-50 text-red-700 rounded-lg font-medium">
                <?= icon('stop', 'w-4 h-4 mr-1') ?> Cancel
            </button>
        </div>

        <div class="mb-3">
            <div class="flex items-center justify-between text-sm mb-1">
                <span id="wpmig-step-label" class="font-medium text-slate-700">Starting…</span>
                <span id="wpmig-step-progress" class="text-slate-500"></span>
            </div>
            <div class="h-2 w-full bg-slate-100 rounded-full overflow-hidden">
                <div id="wpmig-step-bar" class="h-full bg-blue-500 transition-all" style="width:0%"></div>
            </div>
        </div>

        <div id="wpmig-counts" class="grid grid-cols-2 sm:grid-cols-4 gap-3 mt-4 text-sm"></div>

        <div class="mt-5">
            <details>
                <summary class="text-sm text-slate-600 cursor-pointer">View log</summary>
                <pre id="wpmig-log" class="mt-2 max-h-72 overflow-auto bg-slate-900 text-slate-100 text-xs p-3 rounded-lg font-mono"></pre>
            </details>
        </div>
    </div>

    <!-- ============================================================== -->
    <!-- Completion panel                                                -->
    <!-- ============================================================== -->
    <div id="wpmig-done" class="bg-white border border-green-200 rounded-xl p-6 max-w-4xl mt-4 hidden">
        <div class="flex items-start gap-3">
            <div class="w-10 h-10 rounded-lg bg-green-100 grid place-items-center text-green-600">
                <?= icon('check', 'w-4 h-4') ?>
            </div>
            <div class="flex-1">
                <h3 class="font-semibold text-slate-900">Migration complete</h3>
                <p class="text-sm text-slate-500 mt-1">All selected entities have been imported.</p>
                <div id="wpmig-summary" class="mt-3 text-sm"></div>
                <div class="mt-4 flex gap-2">
                    <a href="<?= $base ?>/admin/posts" class="px-3 py-2 text-sm bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium">View posts</a>
                    <a href="<?= $base ?>/admin/wp-migrator" class="px-3 py-2 text-sm border border-slate-300 hover:bg-slate-50 rounded-lg font-medium">Migrate another site</a>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="<?= htmlspecialchars($jsUrl) ?>"></script>
