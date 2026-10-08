<?php $this->extend('layouts.app'); ?>
<?php $this->section('content'); ?>
<?php
/**
 * Admin > Updates.
 * @var array $config @var bool $configured @var array $updates
 * @var string $lastCheck @var string $version @var array|null $flash
 */
$base = defined('BASEHIM_BASE') ? rtrim((string) BASEHIM_BASE, '/') : '';
$csrf = \App\Core\Application::getInstance()->make(\App\Core\Session::class)->csrfToken();
?>

<div class="flex items-center justify-between mb-5 flex-wrap gap-3">
    <div>
        <h2 class="text-xl font-semibold text-slate-900">Updates</h2>
        <p class="text-sm text-slate-500">Keep Basehim and your apps up to date. New releases are delivered automatically.</p>
    </div>
    <div class="flex items-center gap-2">
        <span class="text-xs px-3 py-1.5 rounded-full bg-slate-100 text-slate-600">Current: <strong>v<span id="bh-current"><?= htmlspecialchars($version) ?></span></strong></span>
        <?php if ($configured): ?>
        <button type="button" id="bh-check" class="inline-flex items-center gap-2 px-4 py-2 bg-blue-600 hover:bg-blue-700 disabled:opacity-60 text-white rounded-lg text-sm font-medium shadow-sm">
            <span id="bh-check-ico"><?= icon('arrow-path', 'w-4 h-4') ?></span> <span id="bh-check-txt">Check for updates</span>
        </button>
        <?php endif; ?>
    </div>
</div>

<?php
// The list below is rendered server-side for the first paint, then handed to
// JS which keeps it in sync after checks and installs.
$patches = array_values(array_filter($updates, fn($u) => !empty($u['is_patch'])));
$fulls   = array_values(array_filter($updates, fn($u) => empty($u['is_patch'])));
$appUpdates = $appUpdates ?? [];
$allCount = count($updates) + count($appUpdates);
?>
<!-- Feedback sits OUTSIDE #bh-updates (hidden when the site is up to date, which
     would swallow "checked just now") and ABOVE it: below a long app list the
     outcome of Update all scrolled out of sight, and the page looked stuck. -->
<div id="bh-result" class="hidden mb-4 rounded-lg px-3 py-2.5 text-sm" role="status" aria-live="polite"></div>

<div id="bh-updates" data-count="<?= $allCount ?>" class="<?= $allCount === 0 ? 'hidden' : '' ?>">

    <!-- Install-all panel -->
    <div class="bg-white rounded-xl border border-blue-300 ring-1 ring-blue-100 p-5 mb-4">
        <div class="flex items-start justify-between gap-4 flex-wrap">
            <div class="min-w-0">
                <h3 class="text-base font-semibold text-slate-900" id="bh-sum-title">
                    <?= $allCount ?> update<?= $allCount === 1 ? '' : 's' ?> available
                </h3>
                <p class="text-xs text-slate-500 mt-1" id="bh-sum-sub">Basehim first, oldest release first, then apps.</p>
            </div>
            <button type="button" id="bh-install" class="shrink-0 inline-flex items-center gap-2 px-4 py-2 bg-emerald-600 hover:bg-emerald-700 disabled:opacity-60 text-white rounded-lg text-sm font-medium">
                <?= icon('arrow-down-tray', 'w-4 h-4') ?> <span id="bh-install-txt">Update all</span>
            </button>
        </div>

        <!-- Progress -->
        <div id="bh-progress" class="hidden mt-4">
            <div class="flex items-center justify-between text-xs text-slate-500 mb-1.5">
                <span id="bh-step">Preparing…</span>
                <span id="bh-pct">0%</span>
            </div>
            <div class="h-2 rounded-full bg-slate-100 overflow-hidden">
                <div id="bh-bar" class="h-full w-0 rounded-full bg-gradient-to-r from-blue-500 to-emerald-500 transition-all duration-300"></div>
            </div>
            <p class="text-[11px] text-slate-400 mt-2">Keep this tab open — each update is applied one at a time and verified before the next.</p>
        </div>

    </div>

    <!-- Basehim releases -->
    <div id="bh-core-wrap" class="<?= empty($updates) ? 'hidden' : '' ?>">
        <h3 class="text-sm font-semibold text-slate-700 mb-2">Basehim</h3>
        <div id="bh-list" class="space-y-3 mb-5"></div>
    </div>

    <!-- App updates (1.2.36) -->
    <div id="bh-apps-wrap" class="<?= empty($appUpdates) ? 'hidden' : '' ?>">
        <h3 class="text-sm font-semibold text-slate-700 mb-2">Apps</h3>
        <div id="bh-apps" class="space-y-3 mb-5"></div>
    </div>
</div>

<!-- App update details (1.2.37): the list stays compact; everything else is here. -->
<dialog id="bh-app-modal" class="bh-app-modal" aria-labelledby="bh-am-name">
    <div class="bh-am__head">
        <div id="bh-am-icon"></div>
        <div class="min-w-0 flex-1">
            <h3 id="bh-am-name" class="text-base font-semibold text-slate-900"></h3>
            <div id="bh-am-who" class="text-sm text-slate-500"></div>
        </div>
        <button type="button" class="bh-am__x" data-close aria-label="Close"><?= icon('x-mark', 'w-5 h-5') ?></button>
    </div>
    <div class="bh-am__body">
        <div id="bh-am-versions" class="flex items-center gap-2 text-sm mb-3"></div>
        <p id="bh-am-desc" class="text-sm text-slate-600 mb-3"></p>
        <dl id="bh-am-facts" class="bh-am__facts"></dl>
        <div id="bh-am-notes"></div>
    </div>
    <div class="bh-am__foot">
        <div id="bh-am-state" class="text-xs mr-auto"></div>
        <button type="button" class="bh-am__btn bh-am__btn--cancel" data-close>Close</button>
        <button type="button" id="bh-am-update" class="bh-am__btn bh-am__btn--ok">Update</button>
    </div>
</dialog>
<style>
    .bh-app-modal { border: 0; padding: 0; border-radius: .9rem; width: min(36rem, calc(100vw - 2rem)); max-height: min(44rem, calc(100vh - 2rem)); color: #0f172a; box-shadow: 0 30px 70px -25px rgba(2, 6, 23, .55); }
    .bh-app-modal[open] { display: flex; flex-direction: column; animation: bh-ask-in .16s cubic-bezier(.2, .7, .3, 1); }
    .bh-app-modal::backdrop { background: rgba(15, 23, 42, .4); -webkit-backdrop-filter: blur(5px); backdrop-filter: blur(5px); }
    .bh-am__head { display: flex; align-items: center; gap: .9rem; padding: 1.25rem 1.25rem .75rem; }
    .bh-am__x { align-self: flex-start; padding: .25rem; border-radius: .4rem; color: #64748b; }
    .bh-am__x:hover { background: #f1f5f9; color: #0f172a; }
    .bh-am__body { padding: 0 1.25rem; overflow-y: auto; flex: 1 1 auto; min-height: 0; }
    .bh-am__facts { display: grid; grid-template-columns: auto 1fr; gap: .3rem 1rem; font-size: .8rem; padding: .75rem 0; border-top: 1px solid #f1f5f9; border-bottom: 1px solid #f1f5f9; margin-bottom: 1rem; }
    .bh-am__facts dt { color: #64748b; }
    .bh-am__facts dd { color: #0f172a; margin: 0; overflow-wrap: anywhere; }
    .bh-am__notes h4 { font-size: .8rem; font-weight: 600; color: #0f172a; margin: 0 0 .3rem; }
    .bh-am__notes + .bh-am__notes { margin-top: 1rem; }
    .bh-am__notes div { font-size: .85rem; line-height: 1.55; color: #334155; white-space: pre-line; overflow-wrap: anywhere; }
    .bh-am__foot { display: flex; align-items: center; justify-content: flex-end; gap: .5rem; padding: 1rem 1.25rem 1.15rem; border-top: 1px solid #f1f5f9; }
    .bh-am__btn { padding: .5rem 1rem; border-radius: .55rem; font: inherit; font-size: .875rem; font-weight: 600; cursor: pointer; border: 1px solid transparent; }
    .bh-am__btn--ok { background: #059669; color: #fff; }
    .bh-am__btn--ok:hover { background: #047857; }
    .bh-am__btn--ok:disabled { opacity: .6; cursor: default; }
    .bh-am__btn--cancel { background: #fff; color: #0f172a; border-color: #cbd5e1; font-weight: 500; }
    .bh-am__btn--cancel:hover { background: #f8fafc; }
    .bh-am__btn:focus-visible, .bh-am__x:focus-visible { outline: 2px solid #93c5fd; outline-offset: 2px; }
    .bh-app-open:focus-visible { outline: 2px solid #93c5fd; outline-offset: 2px; border-radius: .5rem; }
    @media (prefers-reduced-motion: reduce) { .bh-app-modal[open] { animation: none; } }
</style>

<?php if ($configured): ?>
<div id="bh-uptodate" class="bg-white rounded-xl border border-slate-200 p-8 text-center text-slate-500 mb-5 <?= $allCount === 0 ? '' : 'hidden' ?>">
    <?= icon('check-circle', 'w-10 h-10 text-emerald-400 mb-3') ?>
    <p class="font-medium text-slate-700 mb-1">You're up to date</p>
    <p class="text-sm">You're running the latest version of Basehim and every installed app<span class="bh-lastcheck-wrap<?= $lastCheck === '' ? ' hidden' : '' ?>"> — last checked <span class="bh-lastcheck"><?= htmlspecialchars($lastCheck) ?></span></span>.</p>
</div>
<?php endif; ?>

<div class="max-w-2xl">
    <?php if ($configured): ?>
    <div class="bg-white rounded-xl border border-slate-200 p-4 flex items-center gap-3">
        <span class="w-2.5 h-2.5 rounded-full bg-emerald-500 shrink-0"></span>
        <div class="min-w-0 flex-1">
            <div class="text-sm font-medium text-slate-800">Update service connected <span class="text-[10px] px-1.5 py-0.5 rounded-full bg-sky-100 text-sky-700 font-semibold align-middle">AUTO</span></div>
            <div class="text-xs text-slate-500 truncate">This site checks for new Basehim releases automatically<?= $config['site_name'] !== '' ? ' · registered as "' . htmlspecialchars($config['site_name']) . '"' : '' ?><span class="bh-lastcheck-wrap<?= $lastCheck === '' ? ' hidden' : '' ?>"> · last check <span class="bh-lastcheck"><?= htmlspecialchars($lastCheck) ?></span></span></div>
        </div>
    </div>
    <?php else: ?>
    <div class="bg-amber-50 rounded-xl border border-amber-200 p-4">
        <div class="text-sm font-medium text-amber-800 mb-1"><?= icon('exclamation-triangle', 'w-4 h-4 mr-1') ?>Update service unavailable</div>
        <p class="text-xs text-amber-700 mb-1">This site connects on its own — no setup needed. The last attempt didn't get through<?= !empty($connectError) ? ':' : '.' ?></p>
        <?php if (!empty($connectError)): ?>
        <p class="text-xs font-mono bg-white/70 border border-amber-200 rounded-lg px-2.5 py-1.5 text-amber-900 mb-2 break-words"><?= htmlspecialchars($connectError) ?></p>
        <?php endif; ?>
        <p class="text-[11px] text-amber-700 mb-3">Basehim retries automatically every few minutes — the button below tries again right now. Your site keeps working normally in the meantime. If this keeps happening, check that this server can reach the internet, then contact support.</p>
        <form method="POST" action="<?= $base ?>/admin/updates/check" class="inline">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
            <button type="submit" class="inline-flex items-center gap-2 px-4 py-2 bg-amber-600 hover:bg-amber-700 text-white rounded-lg text-sm font-medium">
                <?= icon('arrow-path', 'w-4 h-4') ?> Retry connection now
            </button>
        </form>
    </div>
    <?php endif; ?>
</div>

<div class="mt-4 max-w-2xl text-xs text-slate-400 leading-relaxed">
    <?= icon('shield-check', 'w-4 h-4 mr-1') ?>
    Updates never touch <code>.env</code>, <code>content/uploads/</code>, <code>storage/</code>, your root <code>.htaccess</code>, or apps that aren't part of the release.
    Downloads are SHA-256 verified when the release provides a checksum. Database migrations run automatically after the files land.
    An app update replaces only that app's files; its settings, data and active state are kept.
</div>


<script>
(function () {
    var CSRF = <?= json_encode($csrf) ?>;
    var BASE = <?= json_encode($base) ?>;
    var ICON_DOWN  = <?= json_encode(icon('arrow-down-tray', 'w-4 h-4')) ?>;
    var ICON_CHECK = <?= json_encode(icon('check-circle', 'w-4 h-4 inline-block align-text-bottom mr-1')) ?>;
    var ICON_WARN  = <?= json_encode(icon('exclamation-triangle', 'w-4 h-4 inline-block align-text-bottom mr-1')) ?>;

    var el = function (id) { return document.getElementById(id); };
    var wrap = el('bh-updates'), list = el('bh-list'), uptodate = el('bh-uptodate'), appsList = el('bh-apps');
    var installBtn = el('bh-install'), checkBtn = el('bh-check');
    if (!wrap) return;

    var total = 0;        // how many we set out to install
    var doneCount = 0;
    var guardMax = 0;     // hard cap — a client loop must never rely on the server to stop it
    var iterations = 0;
    var lastInstalled = null;

    function esc(t) {
        return String(t == null ? '' : t).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function post(path, fields) {
        var fd = new FormData();
        fd.append('_csrf', CSRF);
        Object.keys(fields || {}).forEach(function (k) { fd.append(k, fields[k]); });
        return fetch(BASE + path, {
            method: 'POST', body: fd, credentials: 'same-origin',
            headers: { 'Accept': 'application/json' }
        }).then(function (r) {
            // A proxy/timeout can return HTML — treat anything unparseable as a
            // network fault rather than throwing an opaque error at the user.
            return r.text().then(function (t) {
                try { return JSON.parse(t); }
                catch (e) { throw new Error('The server returned an unexpected response (HTTP ' + r.status + ').'); }
            });
        });
    }
    // The framework's error handler answers JSON requests with
    // {type,title,status,detail} — no `error` key. Without this, a server fault
    // surfaced as the useless fallback text instead of what actually happened.
    function errText(d, fallback) {
        if (!d) return fallback;
        return d.error || d.detail || d.title || fallback;
    }
    function result(kind, html) {
        var box = el('bh-result');
        box.className = 'mb-5 rounded-lg px-3 py-2.5 text-sm border ' + (
            kind === 'ok'   ? 'bg-emerald-50 border-emerald-200 text-emerald-800' :
            kind === 'warn' ? 'bg-amber-50 border-amber-200 text-amber-800' :
                              'bg-red-50 border-red-200 text-red-700');
        box.innerHTML = html;
        box.classList.remove('hidden');
    }
    function progress(pct, step) {
        el('bh-progress').classList.remove('hidden');
        el('bh-bar').style.width = Math.max(0, Math.min(100, pct)) + '%';
        el('bh-pct').textContent = Math.round(pct) + '%';
        if (step) el('bh-step').textContent = step;
    }

    /** Paint the pending list from a server payload. */
    function render(d) {
        var items = d.pending || [];

        // Refresh the live bits FIRST — this used to sit after the early return
        // below, so on an up-to-date site a check updated nothing on screen and
        // the timestamp only moved on a full page reload.
        if (d.last_check) {
            document.querySelectorAll('.bh-lastcheck').forEach(function (el) {
                el.textContent = d.last_check;
            });
            document.querySelectorAll('.bh-lastcheck-wrap').forEach(function (el) {
                el.classList.remove('hidden');
            });
        }
        if (d.current) {
            var cur = el('bh-current');
            if (cur) cur.textContent = d.current;
        }
        var apps = d.apps || [];
        var all = items.length + apps.length;

        // Keep the sidebar badge honest without a reload — core and apps.
        setBadge(all);

        wrap.classList.toggle('hidden', all === 0);
        if (uptodate) uptodate.classList.toggle('hidden', all !== 0);
        el('bh-core-wrap').classList.toggle('hidden', items.length === 0);
        el('bh-apps-wrap').classList.toggle('hidden', apps.length === 0);
        renderApps(apps);
        if (!all) return;

        el('bh-sum-title').textContent = all + ' update' + (all === 1 ? '' : 's') + ' available';
        var bits = [];
        if (d.full_count)  bits.push(d.full_count + ' Basehim release' + (d.full_count === 1 ? '' : 's'));
        if (d.patch_count) bits.push(d.patch_count + ' patch' + (d.patch_count === 1 ? '' : 'es'));
        if (apps.length)   bits.push(apps.length + ' app' + (apps.length === 1 ? '' : 's'));
        el('bh-sum-sub').textContent = bits.join(', ').replace(/, ([^,]*)$/, ' and $1') +
            (items.length && apps.length ? ' — Basehim first, then apps.' : (items.length ? ' — installed in order, oldest first.' : '.'));
        el('bh-install-txt').textContent = all === 1 ? 'Update now' : 'Update all';
        installBtn.classList.remove('hidden');
        el('bh-progress').classList.add('hidden');

        list.innerHTML = items.map(function (u) {
            var badge = u.is_patch
                ? '<span class="text-[10px] px-2 py-0.5 rounded-full bg-amber-100 text-amber-700 font-semibold uppercase">Patch</span>'
                : '<span class="text-[10px] px-2 py-0.5 rounded-full bg-sky-100 text-sky-700 font-semibold uppercase">Release</span>';
            var meta = [];
            if (u.published_at) meta.push('Released ' + esc(String(u.published_at).slice(0, 10)));
            if (u.size) meta.push(Math.round(u.size / 1024) + ' KB');
            if (u.sha256) meta.push('SHA-256 verified');
            return '<div class="bg-white rounded-xl border border-slate-200 p-4" data-v="' + esc(u.version) + '">'
                 + '<div class="flex items-center gap-2 mb-1">'
                 +   '<span class="bh-dot w-2 h-2 rounded-full bg-slate-300 shrink-0"></span>'
                 +   '<h4 class="text-sm font-semibold text-slate-900">v' + esc(u.version) + '</h4>' + badge
                 + '</div>'
                 + '<div class="text-[11px] text-slate-400 mb-1 pl-4">' + meta.join(' · ') + '</div>'
                 + (u.notes ? '<div class="text-xs text-slate-600 whitespace-pre-line pl-4">' + esc(u.notes) + '</div>' : '')
                 + '</div>';
        }).join('');
    }

    function setBadge(n) {
        document.querySelectorAll('[data-bh-badge="updates"]').forEach(function (badge) {
            badge.textContent = n > 99 ? '99+' : String(n);
            badge.hidden = n === 0;
        });
    }

    /* ---------------- apps ---------------- */

    function appIcon(u) {
        var tile = 'w-10 h-10 rounded-lg grid place-items-center bg-gradient-to-br from-blue-100 to-blue-200 text-blue-600 font-semibold shrink-0';
        if (u.icon_url) {
            return '<img src="' + esc(u.icon_url) + '" alt="" width="40" height="40" class="w-10 h-10 rounded-lg object-cover bg-slate-100 shrink-0" data-initial="' + esc(u.initial || '?') + '">';
        }
        return '<div class="' + tile + '">' + (u.icon_html ? u.icon_html : esc(u.initial || '?')) + '</div>';
    }
    function party(p) {
        if (!p || !p.name) return '';
        return p.url
            ? '<a href="' + esc(p.url) + '" target="_blank" rel="noopener nofollow" class="hover:text-blue-600 hover:underline">' + esc(p.name) + '</a>'
            : esc(p.name);
    }
    // The last list rendered, by slug — what the details dialog shows.
    var appData = {};

    /** One compact row per app; release notes and the rest live in the dialog. */
    function renderApps(apps) {
        if (!appsList) return;
        appData = {};
        apps.forEach(function (u) { appData[u.slug] = u; });
        appsList.innerHTML = apps.map(function (u) {
            // Plain text here: the whole row is a button, and links belong in the dialog.
            var who = [];
            if (u.developer && u.developer.name) who.push('by ' + esc(u.developer.name));
            if (u.company && u.company.name && (!u.developer || u.company.name !== u.developer.name)) who.push(esc(u.company.name));
            return '<div class="bg-white rounded-xl border border-slate-200 px-4 py-3 hover:border-slate-300 transition-colors" data-app="' + esc(u.slug) + '">'
                 + '<div class="flex items-center gap-3">'
                 +   '<button type="button" class="bh-app-open flex items-center gap-3 min-w-0 flex-1 text-left" aria-haspopup="dialog" title="Details and release notes">'
                 +     appIcon(u)
                 +     '<span class="min-w-0 flex-1 block">'
                 +       '<span class="flex items-center gap-2 flex-wrap">'
                 +         '<span class="bh-dot w-2 h-2 rounded-full bg-slate-300 shrink-0"></span>'
                 +         '<h4 class="text-sm font-semibold text-slate-900 truncate">' + esc(u.name) + '</h4>'
                 +         '<span class="text-xs text-slate-500 whitespace-nowrap">v' + esc(u.installed) + ' → <strong class="text-slate-700">v' + esc(u.version) + '</strong></span>'
                 +       '</span>'
                 +       (who.length ? '<span class="block text-xs text-slate-500 truncate mt-0.5">' + who.join(' · ') + '</span>' : '')
                 +     '</span>'
                 +     '<span class="hidden sm:inline text-xs text-slate-400 shrink-0 mr-1">Details</span>'
                 +   '</button>'
                 +   '<button type="button" class="bh-app-btn shrink-0 inline-flex items-center gap-1.5 px-3 py-1.5 text-sm border border-slate-300 hover:bg-slate-50 disabled:opacity-60 rounded-lg font-medium text-slate-700" data-slug="' + esc(u.slug) + '">'
                 +     ICON_DOWN + '<span>Update</span></button>'
                 + '</div>'
                 + '<div class="bh-app-msg text-xs mt-2 hidden"></div>'
                 + '</div>';
        }).join('');
        // A broken icon image falls back to the app's initial.
        appsList.querySelectorAll('img[data-initial]').forEach(fallbackIcon);
    }
    function fallbackIcon(img) {
        img.addEventListener('error', function () {
            var d = document.createElement('div');
            d.className = img.className.replace('object-cover bg-slate-100', 'grid place-items-center bg-gradient-to-br from-blue-100 to-blue-200 text-blue-600 font-semibold');
            d.textContent = img.getAttribute('data-initial');
            img.replaceWith(d);
        }, { once: true });
    }

    /* ---------------- app details dialog ---------------- */

    var modal = el('bh-app-modal'), modalSlug = null;
    function openApp(slug) {
        var u = appData[slug];
        if (!u || !modal) return;
        modalSlug = slug;
        var big = appIcon(u).replace(/w-10 h-10/g, 'w-14 h-14').replace('width="40" height="40"', 'width="56" height="56"');
        el('bh-am-icon').innerHTML = big;
        el('bh-am-icon').querySelectorAll('img[data-initial]').forEach(fallbackIcon);
        el('bh-am-name').textContent = u.name;
        var who = [];
        if (u.developer && u.developer.name) who.push('by ' + party(u.developer));
        if (u.company && u.company.name && (!u.developer || u.company.name !== u.developer.name)) who.push(party(u.company));
        el('bh-am-who').innerHTML = who.join(' · ');
        el('bh-am-versions').innerHTML = '<span class="px-2 py-0.5 rounded-full bg-slate-100 text-slate-600">Installed v' + esc(u.installed) + '</span>'
            + '<span class="text-slate-400">→</span>'
            + '<span class="px-2 py-0.5 rounded-full bg-emerald-50 text-emerald-700 font-medium">New v' + esc(u.version) + '</span>';
        var desc = el('bh-am-desc');
        desc.textContent = u.description || '';
        desc.classList.toggle('hidden', !u.description);
        var facts = [];
        if (u.published_at) facts.push(['Released', esc(String(u.published_at).slice(0, 10))]);
        if (u.size) facts.push(['Download', u.size >= 1048576 ? (u.size / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(u.size / 1024)) + ' KB']);
        facts.push(['Checksum', u.sha256 ? 'SHA-256, verified before installing' : 'Not provided']);
        if (u.developer && u.developer.url) facts.push(['Developer', party({ name: u.developer.url.replace(/^https?:\/\//, ''), url: u.developer.url })]);
        if (u.company && u.company.url) facts.push(['Company', party({ name: u.company.url.replace(/^https?:\/\//, ''), url: u.company.url })]);
        el('bh-am-facts').innerHTML = facts.map(function (f) { return '<dt>' + f[0] + '</dt><dd>' + f[1] + '</dd>'; }).join('');
        // This version's notes first, then any versions in between, newest first.
        var notes = [];
        if (u.notes) notes.push({ version: u.version, notes: u.notes });
        (u.changelog || []).forEach(function (c) { if (c.version !== u.version && c.notes) notes.push(c); });
        el('bh-am-notes').innerHTML = notes.length
            ? notes.map(function (c, i) {
                return '<section class="bh-am__notes"><h4>' + (i === 0 && c.version === u.version ? "What's new in v" : 'v') + esc(c.version) + '</h4><div>' + esc(c.notes) + '</div></section>';
              }).join('')
            : '<p class="text-sm text-slate-400">No release notes were published for this version.</p>';
        syncModal();
        if (typeof modal.showModal === 'function') modal.showModal(); else modal.setAttribute('open', '');
        el('bh-am-update').focus();
    }
    /** Keep the dialog's footer in step with the row (busy, updated, failed). */
    function syncModal() {
        if (!modal || !modalSlug) return;
        var row = appsList.querySelector('[data-app="' + modalSlug + '"]');
        var rb = row && row.querySelector('.bh-app-btn');
        var msg = row && row.querySelector('.bh-app-msg');
        var btn = el('bh-am-update');
        btn.disabled = !rb || rb.disabled;
        btn.textContent = rb ? rb.querySelector('span').textContent : 'Update';
        btn.classList.toggle('hidden', !!(row && row.classList.contains('opacity-60')));
        var st = el('bh-am-state');
        st.className = 'text-xs mr-auto ' + (msg && msg.classList.contains('text-red-700') ? 'text-red-700' : 'text-emerald-700');
        st.innerHTML = msg && !msg.classList.contains('hidden') ? msg.innerHTML : '';
    }
    function closeModal() { if (modal && modal.open) modal.close(); }
    if (modal) {
        modal.addEventListener('click', function (e) {
            if (e.target === modal || (e.target.closest && e.target.closest('[data-close]'))) closeModal();
        });
        modal.addEventListener('close', function () { modalSlug = null; });
        el('bh-am-update').addEventListener('click', function () {
            if (!modalSlug || this.disabled || running) return;
            el('bh-result').classList.add('hidden');
            updateApp(modalSlug).then(afterApps);
        });
    }

    function appState(slug, state, msg) {
        var row = appsList.querySelector('[data-app="' + slug + '"]');
        if (!row) return;
        var dot = row.querySelector('.bh-dot');
        if (dot) dot.className = 'bh-dot w-2 h-2 rounded-full shrink-0 ' + (
            state === 'busy' ? 'bg-blue-500 animate-pulse' :
            state === 'ok'   ? 'bg-emerald-500' :
            state === 'fail' ? 'bg-red-500' : 'bg-slate-300');
        row.classList.toggle('opacity-60', state === 'ok');
        var btn = row.querySelector('.bh-app-btn');
        if (btn) {
            btn.disabled = state === 'busy' || state === 'ok' || (running && state !== 'fail');
            btn.querySelector('span').textContent = state === 'busy' ? 'Updating…' : state === 'ok' ? 'Updated' : 'Update';
        }
        var box = row.querySelector('.bh-app-msg');
        if (box) {
            box.className = 'bh-app-msg text-xs mt-2' + (msg ? '' : ' hidden') + (state === 'fail' ? ' text-red-700' : ' text-emerald-700');
            box.innerHTML = msg || '';
        }
        if (slug === modalSlug) syncModal();
    }

    /** Update one app. Resolves to the server's answer; never rejects. */
    function updateApp(slug) {
        appState(slug, 'busy');
        return post('/admin/updates/app-step.json', { slug: slug }).then(function (d) {
            if (!d.ok) {
                appState(slug, 'fail', ICON_WARN + esc(errText(d, 'Update failed.')));
                return d;
            }
            if (d.skipped) { appState(slug, 'ok', 'Already up to date.'); return d; }
            var note = 'Updated to v' + esc(d.installed) + '.';
            if (d.review) note += ' This version asks for new permissions — <a href="' + esc(d.review_url) + '" class="underline font-medium">review them</a>.';
            appState(slug, 'ok', ICON_CHECK + note);
            return d;
        }).catch(function (e) {
            appState(slug, 'fail', ICON_WARN + esc(e.message || 'Connection lost.'));
            return { ok: false, error: e.message };
        });
    }

    /** Badge and heading from a payload, leaving finished rows on screen. */
    function afterApps(d) {
        if (!d || typeof d.total_count !== 'number') return;
        setBadge(d.total_count);
        el('bh-sum-title').textContent = d.total_count
            ? d.total_count + ' update' + (d.total_count === 1 ? '' : 's') + ' available'
            : 'All updates installed';
        var core = d.pending_count || 0, apps = d.apps_count || 0, bits = [];
        if (core) bits.push(core + ' Basehim release' + (core === 1 ? '' : 's'));
        if (apps) bits.push(apps + ' app' + (apps === 1 ? '' : 's'));
        el('bh-sum-sub').textContent = bits.length ? bits.join(' and ') + ' still to install.' : 'Everything is up to date.';
        if (!running) installBtn.classList.toggle('hidden', d.total_count === 0);
    }

    var running = false;   // true while Update all is working through the list
    function lockAppButtons(on) {
        appsList.querySelectorAll('[data-app]').forEach(function (row) {
            var b = row.querySelector('.bh-app-btn');
            if (b && !row.classList.contains('opacity-60')) b.disabled = on;
        });
    }

    if (appsList) appsList.addEventListener('click', function (e) {
        var open = e.target.closest && e.target.closest('.bh-app-open');
        if (open) { openApp(open.closest('[data-app]').getAttribute('data-app')); return; }
        var btn = e.target.closest && e.target.closest('.bh-app-btn');
        if (!btn || btn.disabled || running) return;
        el('bh-result').classList.add('hidden');
        updateApp(btn.getAttribute('data-slug')).then(afterApps);
    });

    /** Every app still pending on screen, one request each, in order. */
    function updateAllApps(done, failed) {
        // Rows already updated (dimmed) are skipped; the rest were locked by the caller.
        var slugs = Array.prototype.map.call(appsList.querySelectorAll('[data-app]:not(.opacity-60)'), function (r) { return r.getAttribute('data-app'); });
        var i = 0, last = null;
        return new Promise(function (resolve) {
            (function next() {
                if (i >= slugs.length) { resolve(last); return; }
                var slug = slugs[i++];
                var row = appsList.querySelector('[data-app="' + slug + '"] h4');
                progress(total ? Math.min(99, ((doneCount + done.length + failed.length) / total) * 100) : 50,
                         'Updating ' + (row ? row.textContent : slug) + '…');
                updateApp(slug).then(function (d) {
                    if (d && typeof d.total_count === 'number') last = d;
                    if (d && d.ok && !d.skipped) done.push(slug);
                    else if (!(d && d.ok)) failed.push(slug);
                    next();
                });
            })();
        });
    }

    var coreFinished = null;

    /** After Basehim (if it had anything): every app, then one summary. */
    function finishAll() {
        var appDone = [], appFailed = [];
        var hasApps = appsList && appsList.querySelector('[data-app]:not(.opacity-60)');
        return (hasApps ? updateAllApps(appDone, appFailed) : Promise.resolve(null)).then(function (last) {
            running = false;
            // Done: the bar has nothing left to say. Leaving it parked at 100%
            // with "Keep this tab open" under it read as an update that hung.
            el('bh-progress').classList.add('hidden');
            var parts = [];
            if (doneCount) parts.push('Basehim is now on v' + esc((coreFinished && coreFinished.current) || ''));
            if (appDone.length) parts.push(appDone.length + ' app' + (appDone.length === 1 ? '' : 's') + ' updated');
            if (appFailed.length) {
                result('warn', ICON_WARN + '<strong>Finished with problems.</strong> ' + (parts.length ? parts.join(', ') + '. ' : '') +
                    appFailed.length + ' app' + (appFailed.length === 1 ? '' : 's') + ' could not be updated — the reason is shown on each. They are still on their previous version.');
            } else {
                result('ok', ICON_CHECK + '<strong>Up to date.</strong> ' + (parts.join(', ') || 'Nothing needed installing') + '.' +
                    (doneCount ? ' <a href="' + BASE + '/admin/updates" class="underline font-medium">Reload</a> to see the new version.' : ''));
            }
            if (last) afterApps(last);
            else if (coreFinished) afterApps(coreFinished);
            var box = el('bh-result'), r = box.getBoundingClientRect();
            if (r.top < 0 || r.bottom > window.innerHeight) box.scrollIntoView({ block: 'center', behavior: 'smooth' });
        });
    }

    function markRow(version, state) {
        var row = list.querySelector('[data-v="' + version + '"]');
        if (!row) return;
        var dot = row.querySelector('.bh-dot');
        if (dot) dot.className = 'bh-dot w-2 h-2 rounded-full shrink-0 ' + (
            state === 'busy' ? 'bg-blue-500 animate-pulse' :
            state === 'ok'   ? 'bg-emerald-500' : 'bg-red-500');
        row.classList.toggle('opacity-60', state === 'ok');
    }

    /* ---------------- check ---------------- */
    if (checkBtn) checkBtn.addEventListener('click', function () {
        checkBtn.disabled = true;
        var txt = el('bh-check-txt'); var was = txt.textContent;
        txt.textContent = 'Checking…';
        el('bh-check-ico').classList.add('animate-spin');
        post('/admin/updates/check.json').then(function (d) {
            if (!d.ok) { result('err', ICON_WARN + esc(errText(d, 'Check failed.'))); return; }
            el('bh-result').classList.add('hidden');
            render(d);
            if (!d.total_count) {
                result('ok', ICON_CHECK + "You're up to date — checked just now.");
            }
        }).catch(function (e) {
            result('err', ICON_WARN + esc(e.message || 'Could not reach the update service.'));
        }).finally(function () {
            checkBtn.disabled = false; txt.textContent = was;
            el('bh-check-ico').classList.remove('animate-spin');
        });
    });

    /* ---------------- install (one step at a time) ----------------
       Each request applies exactly one update. That keeps every request short
       (shared hosting kills long ones), lets the bar move honestly, and means a
       failure stops the chain with the rest still pending rather than a
       half-applied set. */
    function step() {
        return post('/admin/updates/install-step.json').then(function (d) {
            if (!d.ok) {
                if (d.failed_version) markRow(d.failed_version, 'fail');
                var extra = d.rolled_back
                    ? ' The previous files were restored, so the site is still on v' + esc(d.current || '') + '.'
                    : '';
                result('err', ICON_WARN + '<strong>Update stopped.</strong> ' + esc(errText(d, 'Install failed.')) + extra
                     + ' Nothing further was applied — fix the problem and run it again.');
                render(d);
                return false;
            }
            if (d.installed) {
                // The same version twice means the install isn't moving forward.
                // Stop rather than re-download it endlessly.
                if (d.installed === lastInstalled) {
                    result('err', ICON_WARN + '<strong>Update stopped.</strong> v' + esc(d.installed) +
                        ' reported success but the site is still on v' + esc(d.current || '?') +
                        '. That package is probably built without its version bump. Nothing further was applied.');
                    render(d);
                    return false;
                }
                lastInstalled = d.installed;
                markRow(d.installed, 'ok');
                doneCount++;
                // Clamp: the bar can never exceed 100%, whatever the server says.
                var pct = total ? Math.min(100, (doneCount / total) * 100) : 100;
                progress(pct, 'Installed v' + d.installed + (d.is_patch ? ' (patch)' : '') +
                              (d.done ? '' : ' · next…'));
            }
            if (d.done) {
                // Basehim is current; the apps come next (see finishAll).
                coreFinished = d;
                return false;
            }
            return true;   // keep going
        });
    }

    if (installBtn) installBtn.addEventListener('click', async function () {
        if (!(await bhConfirm('Install all pending updates now?\n\nBasehim releases go first, oldest first: core files are replaced (your .env, uploads, storage and .htaccess are never touched) and migrations run, with a snapshot restored automatically if a step fails. Then each app is updated in turn; an app keeps its settings and data, and stays on its current version if its update fails.\n\nHaving a recent host backup is still recommended.', { confirmLabel: 'Update all' }))) return;

        installBtn.disabled = true;
        if (checkBtn) checkBtn.disabled = true;
        el('bh-install-txt').textContent = 'Installing…';
        el('bh-result').classList.add('hidden');
        coreFinished = null;
        running = true;
        if (appsList) lockAppButtons(true);
        total = (list.querySelectorAll('[data-v]').length + (appsList ? appsList.querySelectorAll('[data-app]').length : 0)) ||
                parseInt(wrap.getAttribute('data-count'), 10) || 1;
        doneCount = 0;
        iterations = 0;
        lastInstalled = null;
        // Slack for a re-check, but never an unbounded loop.
        guardMax = total + 3;
        progress(2, 'Starting…');

        var first = list.querySelector('[data-v]');
        if (first) markRow(first.getAttribute('data-v'), 'busy');

        var restore = function () {
            running = false;
            if (appsList) lockAppButtons(false);
            installBtn.disabled = false;
            if (checkBtn) checkBtn.disabled = false;
            el('bh-install-txt').textContent = 'Update all';
            // Nothing left to install: no button inviting a second run.
            var left = list.querySelectorAll('[data-v]:not(.opacity-60)').length +
                       (appsList ? appsList.querySelectorAll('[data-app]:not(.opacity-60)').length : 0);
            installBtn.classList.toggle('hidden', left === 0);
        };
        if (!list.querySelector('[data-v]')) {
            finishAll().then(restore);   // apps only
            return;
        }

        (function loop() {
            if (++iterations > guardMax) {
                result('warn', ICON_WARN + '<strong>Stopped after ' + (iterations - 1) + ' steps.</strong> ' +
                    'The server kept reporting more to install than were pending, which should not happen. ' +
                    '<a href="' + BASE + '/admin/updates" class="underline font-medium">Reload</a> to see the current state.');
                installBtn.disabled = false;
                if (checkBtn) checkBtn.disabled = false;
                el('bh-install-txt').textContent = 'Update all';
                return;
            }
            step().then(function (again) {
                if (again) {
                    var next = list.querySelector('[data-v]:not(.opacity-60)');
                    if (next) markRow(next.getAttribute('data-v'), 'busy');
                    loop();
                } else if (coreFinished) {
                    finishAll().then(restore);   // Basehim is current: now the apps
                } else {
                    restore();                   // Basehim failed: stop here
                }
            }).catch(function (e) {
                // Network drop mid-chain: be explicit that some steps may have landed.
                result('warn', ICON_WARN + '<strong>Connection lost during the update.</strong> ' +
                       esc(e.message || '') + ' Some updates may already be installed — ' +
                       '<a href="' + BASE + '/admin/updates" class="underline font-medium">reload this page</a> ' +
                       'to see where it got to, then run it again to finish.');
                installBtn.disabled = false;
                if (checkBtn) checkBtn.disabled = false;
                el('bh-install-txt').textContent = 'Update all';
            });
        })();
    });

    // Warn if someone tries to leave mid-install.
    window.addEventListener('beforeunload', function (e) {
        if (installBtn && installBtn.disabled && el('bh-progress') && !el('bh-progress').classList.contains('hidden')) {
            e.preventDefault(); e.returnValue = '';
        }
    });

    // First paint: hand the server-rendered list to JS so data-v hooks exist.
    render({
        // Hex-escaped: text from the update service must not be able to close this <script>.
        apps: <?= json_encode(array_values($appUpdates), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
        pending: <?= json_encode(array_values($updates), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
        patch_count: <?= count($patches) ?>,
        full_count: <?= count($fulls) ?>,
        pending_count: <?= count($updates) ?>,
        current: <?= json_encode($version) ?>,
        last_check: <?= json_encode($lastCheck) ?>
    });
})();
</script>

<?php $this->endSection(); ?>
