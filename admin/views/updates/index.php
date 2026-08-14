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
        <p class="text-sm text-slate-500">Keep Basehim up to date. New releases are delivered automatically.</p>
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
?>
<div id="bh-updates" data-count="<?= count($updates) ?>" class="<?= empty($updates) ? 'hidden' : '' ?>">

    <!-- Install-all panel -->
    <div class="bg-white rounded-xl border border-blue-300 ring-1 ring-blue-100 p-5 mb-4">
        <div class="flex items-start justify-between gap-4 flex-wrap">
            <div class="min-w-0">
                <h3 class="text-base font-semibold text-slate-900" id="bh-sum-title">
                    <?= count($updates) ?> update<?= count($updates) === 1 ? '' : 's' ?> available
                </h3>
                <p class="text-xs text-slate-500 mt-1" id="bh-sum-sub">
                    <?php if ($patches && $fulls): ?>
                        <?= count($fulls) ?> release<?= count($fulls) === 1 ? '' : 's' ?> and <?= count($patches) ?> patch<?= count($patches) === 1 ? '' : 'es' ?> — installed in order, oldest first.
                    <?php elseif ($patches): ?>
                        <?= count($patches) ?> patch<?= count($patches) === 1 ? '' : 'es' ?> — applied in order so none is missed.
                    <?php else: ?>
                        Installed in order, oldest first.
                    <?php endif; ?>
                </p>
            </div>
            <button type="button" id="bh-install" class="shrink-0 inline-flex items-center gap-2 px-4 py-2 bg-emerald-600 hover:bg-emerald-700 disabled:opacity-60 text-white rounded-lg text-sm font-medium">
                <?= icon('arrow-down-tray', 'w-4 h-4') ?> <span id="bh-install-txt">Install all updates</span>
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

    <!-- The individual updates -->
    <div id="bh-list" class="space-y-3 mb-5"></div>
</div>

<!-- Feedback lives OUTSIDE #bh-updates: that wrapper is hidden when the site is
     up to date, which would have swallowed the "checked just now" confirmation. -->
<div id="bh-result" class="hidden mb-5 rounded-lg px-3 py-2.5 text-sm"></div>

<?php if ($configured): ?>
<div id="bh-uptodate" class="bg-white rounded-xl border border-slate-200 p-8 text-center text-slate-500 mb-5 <?= empty($updates) ? '' : 'hidden' ?>">
    <?= icon('check-circle', 'w-10 h-10 text-emerald-400 mb-3') ?>
    <p class="font-medium text-slate-700 mb-1">You're up to date</p>
    <p class="text-sm">You're running the latest version of Basehim<span class="bh-lastcheck-wrap<?= $lastCheck === '' ? ' hidden' : '' ?>"> — last checked <span class="bh-lastcheck"><?= htmlspecialchars($lastCheck) ?></span></span>.</p>
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
</div>


<script>
(function () {
    var CSRF = <?= json_encode($csrf) ?>;
    var BASE = <?= json_encode($base) ?>;
    var ICON_DOWN  = <?= json_encode(icon('arrow-down-tray', 'w-4 h-4')) ?>;
    var ICON_CHECK = <?= json_encode(icon('check-circle', 'w-4 h-4 inline-block align-text-bottom mr-1')) ?>;
    var ICON_WARN  = <?= json_encode(icon('exclamation-triangle', 'w-4 h-4 inline-block align-text-bottom mr-1')) ?>;

    var el = function (id) { return document.getElementById(id); };
    var wrap = el('bh-updates'), list = el('bh-list'), uptodate = el('bh-uptodate');
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
    function post(path) {
        var fd = new FormData();
        fd.append('_csrf', CSRF);
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
        // Keep the sidebar badge honest without a reload.
        var badge = document.querySelector('[data-bh-badge="updates"]');
        if (badge && typeof d.pending_count === 'number') {
            badge.textContent = d.pending_count > 99 ? '99+' : String(d.pending_count);
            badge.hidden = d.pending_count === 0;
        }

        wrap.classList.toggle('hidden', items.length === 0);
        if (uptodate) uptodate.classList.toggle('hidden', items.length !== 0);
        if (!items.length) return;

        el('bh-sum-title').textContent = items.length + ' update' + (items.length === 1 ? '' : 's') + ' available';
        var bits = [];
        if (d.full_count)  bits.push(d.full_count + ' release' + (d.full_count === 1 ? '' : 's'));
        if (d.patch_count) bits.push(d.patch_count + ' patch' + (d.patch_count === 1 ? '' : 'es'));
        el('bh-sum-sub').textContent = (bits.join(' and ') || 'Pending') + ' — installed in order, oldest first.';
        el('bh-install-txt').textContent = items.length === 1 ? 'Install update' : 'Install all updates';

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
            if (!d.pending_count) {
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
                progress(100, 'Finished');
                result('ok', ICON_CHECK + '<strong>Up to date.</strong> Installed ' + doneCount +
                       ' update' + (doneCount === 1 ? '' : 's') + ' — now on v' + esc(d.current || '') +
                       '. <a href="' + BASE + '/admin/updates" class="underline font-medium">Reload</a> to see the new version.');
                render(d);
                return false;
            }
            return true;   // keep going
        });
    }

    if (installBtn) installBtn.addEventListener('click', function () {
        if (!window.confirm('Install all pending updates now?\n\nThey are applied in order, oldest first. Core files are replaced (your .env, uploads, storage and .htaccess are never touched) and migrations run. A snapshot is taken before each step and restored automatically if one fails.\n\nHaving a recent host backup is still recommended.')) return;

        installBtn.disabled = true;
        if (checkBtn) checkBtn.disabled = true;
        el('bh-install-txt').textContent = 'Installing…';
        el('bh-result').classList.add('hidden');
        total = list.querySelectorAll('[data-v]').length ||
                parseInt(wrap.getAttribute('data-count'), 10) || 1;
        doneCount = 0;
        iterations = 0;
        lastInstalled = null;
        // Slack for a re-check, but never an unbounded loop.
        guardMax = total + 3;
        progress(2, 'Starting…');

        var first = list.querySelector('[data-v]');
        if (first) markRow(first.getAttribute('data-v'), 'busy');

        (function loop() {
            if (++iterations > guardMax) {
                result('warn', ICON_WARN + '<strong>Stopped after ' + (iterations - 1) + ' steps.</strong> ' +
                    'The server kept reporting more to install than were pending, which should not happen. ' +
                    '<a href="' + BASE + '/admin/updates" class="underline font-medium">Reload</a> to see the current state.');
                installBtn.disabled = false;
                if (checkBtn) checkBtn.disabled = false;
                el('bh-install-txt').textContent = 'Install all updates';
                return;
            }
            step().then(function (again) {
                if (again) {
                    var next = list.querySelector('[data-v]:not(.opacity-60)');
                    if (next) markRow(next.getAttribute('data-v'), 'busy');
                    loop();
                } else {
                    installBtn.disabled = false;
                    if (checkBtn) checkBtn.disabled = false;
                    el('bh-install-txt').textContent = 'Install all updates';
                }
            }).catch(function (e) {
                // Network drop mid-chain: be explicit that some steps may have landed.
                result('warn', ICON_WARN + '<strong>Connection lost during the update.</strong> ' +
                       esc(e.message || '') + ' Some updates may already be installed — ' +
                       '<a href="' + BASE + '/admin/updates" class="underline font-medium">reload this page</a> ' +
                       'to see where it got to, then run it again to finish.');
                installBtn.disabled = false;
                if (checkBtn) checkBtn.disabled = false;
                el('bh-install-txt').textContent = 'Install all updates';
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
        pending: <?= json_encode(array_values($updates), JSON_UNESCAPED_SLASHES) ?>,
        patch_count: <?= count($patches) ?>,
        full_count: <?= count($fulls) ?>,
        pending_count: <?= count($updates) ?>,
        current: <?= json_encode($version) ?>,
        last_check: <?= json_encode($lastCheck) ?>
    });
})();
</script>

<?php $this->endSection(); ?>
