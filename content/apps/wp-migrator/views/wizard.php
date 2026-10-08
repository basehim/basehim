<?php
/**
 * WP Migrator Wizard
 *
 * @var \Basehim\WpMigrator\App $app
 * @var array|null $job
 * @var array|null $lastJob   (secrets redacted)
 * @var string $lastLog
 * @var string $csrf
 * @var int $maxUpload
 * @var string $base
 */
$running = $job && in_array($job['status'], ['pending','running'], true);
$e = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$lastLog = $lastLog ?? '';
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
        <button type="button" id="wpmig-reset" class="px-3 py-2 text-xs border border-slate-300 hover:bg-slate-50 rounded-lg font-medium text-slate-600">
            <?= icon('arrow-uturn-left', 'w-4 h-4 mr-1') ?> Reset migration data
        </button>
    <?php endif; ?>
</div>

<!-- Wizard panel: shows form OR progress depending on state. -->
<div id="wpmig-wizard"
     data-base="<?= $e($base) ?>"
     data-csrf="<?= $e($csrf) ?>"
     data-running="<?= $running ? '1' : '0' ?>">

    <?php if (!$running): ?>

    <!-- ============================================================== -->
    <!-- Setup form                                                     -->
    <!-- ============================================================== -->
    <form id="wpmig-setup" class="bg-white border border-slate-200 rounded-xl p-6 max-w-4xl" enctype="multipart/form-data">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">

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
                    In WordPress: <em>Tools &rarr; Export</em> &rarr; "All content". Max import size: 500 MB —
                    uploaded in small background chunks, so this isn't limited by the server's normal upload size setting.
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
                    <input type="checkbox" name="<?= $e($name) ?>" value="1" checked class="w-4 h-4 text-blue-600 rounded border-slate-300">
                    <span><?= $e($label) ?></span>
                </label>
                <?php endforeach; ?>
            </div>

            <!-- Media filter (only applies when "Media" is ticked) -->
            <fieldset id="wpmig-media-opts" class="mt-4 border border-slate-200 rounded-lg p-4">
                <legend class="px-1 text-sm font-medium text-slate-700">Media filter</legend>
                <p class="text-xs text-slate-500 mb-2">Which attachments to download.</p>
                <div class="flex flex-wrap gap-x-5 gap-y-2 text-sm">
                    <?php foreach ([
                        'image' => 'Images', 'video' => 'Video', 'audio' => 'Audio',
                        'document' => 'Documents (PDF, Office…)', 'archive' => 'Archives (ZIP…)', 'other' => 'Other files',
                    ] as $t => $label): ?>
                    <label class="flex items-center gap-2">
                        <input type="checkbox" name="media_types[]" value="<?= $e($t) ?>" checked class="w-4 h-4 text-blue-600 rounded border-slate-300">
                        <span><?= $e($label) ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>

                <label class="flex items-start gap-2 mt-3 text-sm">
                    <input type="checkbox" name="media_originals_only" value="1" class="w-4 h-4 mt-0.5 text-blue-600 rounded border-slate-300">
                    <span><strong class="font-medium">Originals only</strong>
                        <span class="block text-xs text-slate-500">Skip thumbnails and resized copies (<code>photo-300x200.jpg</code>, <code>photo@2x.jpg</code>),
                        download the original instead of WordPress's <code>-scaled</code> copy, and link resized images in posts to the full image
                        instead of downloading each size.</span></span>
                </label>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mt-3">
                    <label class="block sm:col-span-2">
                        <span class="block text-sm font-medium text-slate-700 mb-1">Exclude file names <span class="font-normal text-slate-400">(optional)</span></span>
                        <input type="text" name="media_exclude" placeholder="*-150x150.*, *.webp, logo-old*"
                               class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm font-mono">
                        <span class="block text-xs text-slate-500 mt-1">Comma-separated wildcards, matched against the file name (<code>*</code> any text, <code>?</code> one character).</span>
                    </label>
                    <label class="block">
                        <span class="block text-sm font-medium text-slate-700 mb-1">Max file size (MB)</span>
                        <input type="number" name="media_max_mb" value="25" min="1" max="512"
                               class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm">
                    </label>
                </div>

                <details class="mt-3">
                    <summary class="text-xs text-slate-600 cursor-pointer">Advanced</summary>
                    <label class="flex items-start gap-2 mt-2 text-sm">
                        <input type="checkbox" name="media_allow_svg" value="1" class="w-4 h-4 mt-0.5 text-blue-600 rounded border-slate-300">
                        <span>Allow SVG files <span class="block text-xs text-slate-500">SVG can contain scripts. Only for a source you trust.</span></span>
                    </label>
                    <label class="flex items-start gap-2 mt-2 text-sm">
                        <input type="checkbox" name="media_allow_private" value="1" class="w-4 h-4 mt-0.5 text-blue-600 rounded border-slate-300">
                        <span>Allow downloads from private network addresses
                            <span class="block text-xs text-slate-500">Only if the old site is on localhost or your LAN. Off, downloads from 127.0.0.1, 10.x, 192.168.x and similar are refused.</span></span>
                    </label>
                </details>
            </fieldset>
        </div>

        <!-- Auth options -->
        <div class="mb-6">
            <h3 class="font-semibold text-slate-900 mb-3">3. User options</h3>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Default password for imported users</label>
                    <input type="text" name="default_password" placeholder="Leave blank to auto-generate"
                           class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm">
                    <p class="text-xs text-slate-500 mt-1">Users will need to reset on first login. If left blank, the generated password is shown once when the migration starts and written to the log.</p>
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

        <div class="pt-4 border-t border-slate-100">
            <div class="flex items-center gap-3">
                <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium">
                    <?= icon('play', 'w-4 h-4 mr-1') ?> Start migration
                </button>
                <span id="wpmig-setup-msg" class="text-sm text-slate-500" role="status"></span>
            </div>

            <!-- Upload progress (chunked WXR upload only; hidden otherwise) -->
            <div id="wpmig-upload-progress" class="mt-3 hidden">
                <div class="flex items-center justify-between text-xs mb-1">
                    <span class="font-medium text-slate-700">Uploading file&hellip;</span>
                    <span id="wpmig-upload-bytes" class="text-slate-500"></span>
                </div>
                <div class="h-2 w-full bg-slate-100 rounded-full overflow-hidden">
                    <div id="wpmig-upload-bar" class="h-full bg-blue-500 transition-all" style="width:0%"></div>
                </div>
            </div>
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

        <div id="wpmig-notice" class="hidden mb-3 p-3 rounded-lg bg-amber-50 border border-amber-200 text-sm text-amber-900"></div>

        <div id="wpmig-counts" class="grid grid-cols-2 sm:grid-cols-4 gap-3 mt-4 text-sm"></div>

        <div class="mt-5">
            <details open>
                <summary class="text-sm text-slate-600 cursor-pointer">Log
                    <a href="<?= $e($base) ?>/admin/wp-migrator/log" class="ml-2 text-xs text-blue-700 hover:underline">Download</a>
                </summary>
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
                <details class="mt-3">
                    <summary class="text-sm text-slate-600 cursor-pointer">Log
                        <a href="<?= $e($base) ?>/admin/wp-migrator/log" class="ml-2 text-xs text-blue-700 hover:underline">Download</a>
                    </summary>
                    <pre id="wpmig-done-log" class="mt-2 max-h-72 overflow-auto bg-slate-900 text-slate-100 text-xs p-3 rounded-lg font-mono whitespace-pre-wrap"></pre>
                </details>
                <div class="mt-4 flex gap-2">
                    <a href="<?= $e($base) ?>/admin/posts" class="px-3 py-2 text-sm bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium">View posts</a>
                    <a href="<?= $e($base) ?>/admin/wp-migrator" class="px-3 py-2 text-sm border border-slate-300 hover:bg-slate-50 rounded-lg font-medium">Migrate another site</a>
                </div>
            </div>
        </div>
    </div>

    <?php if (!$running && $lastJob): ?>
    <!-- ============================================================== -->
    <!-- Last migration (status, counts, log)                            -->
    <!-- ============================================================== -->
    <?php
        $st = (string) $lastJob['status'];
        $tone = ['completed' => 'text-green-700 bg-green-50', 'failed' => 'text-red-700 bg-red-50',
                 'cancelled' => 'text-amber-800 bg-amber-50'][$st] ?? 'text-slate-700 bg-slate-100';
    ?>
    <div id="wpmig-last" class="bg-white border border-slate-200 rounded-xl p-6 max-w-4xl mt-4">
        <div class="flex items-center justify-between gap-3 flex-wrap">
            <h3 class="font-semibold text-slate-900">Last migration
                <span class="ml-2 px-2 py-0.5 rounded text-xs font-medium <?= $e($tone) ?>"><?= $e($st) ?></span>
            </h3>
            <span class="text-xs text-slate-500">
                #<?= (int) $lastJob['id'] ?> · <?= $e($lastJob['source']) ?>
                · started <?= $e($lastJob['started_at'] ?? '') ?>
                <?php if (!empty($lastJob['finished_at'])): ?> · ended <?= $e($lastJob['finished_at']) ?><?php endif; ?>
            </span>
        </div>
        <?php if (!empty($lastJob['counts'])): ?>
        <ul class="mt-3 flex flex-wrap gap-2 text-xs">
            <?php foreach ((array) $lastJob['counts'] as $k => $v): ?>
                <li class="px-2 py-1 bg-slate-50 rounded"><span class="text-slate-500"><?= $e(str_replace('_', ' ', (string) $k)) ?></span>
                    <strong class="text-slate-900"><?= (int) $v ?></strong></li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
        <details class="mt-3" <?= $st !== 'completed' ? 'open' : '' ?>>
            <summary class="text-sm text-slate-600 cursor-pointer">Log
                <a href="<?= $e($base) ?>/admin/wp-migrator/log?job=<?= (int) $lastJob['id'] ?>" class="ml-2 text-xs text-blue-700 hover:underline">Download full log</a>
            </summary>
            <pre class="mt-2 max-h-72 overflow-auto bg-slate-900 text-slate-100 text-xs p-3 rounded-lg font-mono whitespace-pre-wrap"><?= $lastLog !== '' ? $e($lastLog) : 'No log lines were recorded.' ?></pre>
        </details>
    </div>
    <?php endif; ?>

    <!-- ============================================================== -->
    <!-- Repair image links in posts already on the site                 -->
    <!-- ============================================================== -->
    <div id="wpmig-repair" class="bg-white border border-slate-200 rounded-xl p-6 max-w-4xl mt-4 <?= $running ? 'hidden' : '' ?>">
        <h3 class="font-semibold text-slate-900">Repair image links</h3>
        <p class="text-sm text-slate-500 mt-1 max-w-2xl">
            Fixes images in posts that are already on this site: galleries, resized copies,
            <code>srcset</code>, <code>[gallery]</code> and <code>[caption]</code> shortcodes, and the
            <code>/wp-content/uploads/…</code> addresses left by WP Migrator 1.2.0 and earlier.
            Preview first — it changes nothing.
        </p>
        <div class="mt-4 flex flex-wrap items-end gap-3">
            <label class="block">
                <span class="block text-sm font-medium text-slate-700 mb-1">Old WordPress address <span class="font-normal text-slate-400">(optional)</span></span>
                <input type="text" id="wpmig-repair-site" placeholder="https://old-site.com"
                       class="w-72 px-3 py-2 text-sm border border-slate-300 rounded-lg">
            </label>
            <button type="button" id="wpmig-repair-preview" class="px-4 py-2 text-sm border border-slate-300 hover:bg-slate-50 rounded-lg font-medium">Preview</button>
            <button type="button" id="wpmig-repair-apply" class="px-4 py-2 text-sm bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium">Repair</button>
        </div>
        <p class="text-xs text-slate-500 mt-2 max-w-2xl">If the old site is still online, give its address: resized copies are then fetched so gallery thumbnails keep their crop. Without it, a resized copy links to the full image.</p>
        <div id="wpmig-repair-out" class="mt-4 text-sm hidden"></div>
    </div>
</div>

<script src="<?= htmlspecialchars($jsUrl) ?>"></script>
