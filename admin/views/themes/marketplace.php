<?php $this->extend('layouts.app'); ?>
<?php $this->section('content'); ?>
<?php
$base = defined('BASEHIM_BASE') ? rtrim((string) BASEHIM_BASE, '/') : '';
$csrf = \App\Core\Application::getInstance()->make(\App\Core\Session::class)->csrfToken();
?>

<div class="flex items-center justify-between mb-5 flex-wrap gap-3">
    <div>
        <h2 class="text-xl font-semibold text-slate-900">Theme Marketplace</h2>
        <p class="text-sm text-slate-500">Browse and install themes from the Basehim marketplace.</p>
    </div>
    <a href="<?= $base ?>/admin/themes" class="inline-flex items-center gap-2 px-3 py-2 border border-slate-200 rounded-lg text-sm text-slate-600 hover:bg-slate-50">
        <?= icon('arrow-left', 'w-4 h-4') ?> Back to Themes
    </a>
</div>

<!-- Search + sort bar -->
<div class="bg-white rounded-xl border border-slate-200 p-3 mb-4 flex items-center gap-2 flex-wrap">
    <div class="relative flex-1 min-w-[220px]">
        <span class="absolute inset-y-0 left-0 flex items-center pl-3 text-slate-400"><?= icon('magnifying-glass', 'w-4 h-4') ?></span>
        <input id="mk-q" type="text" placeholder="Search themes…" class="w-full pl-10 pr-3 py-2 border border-slate-300 rounded-lg text-sm outline-none focus:ring-2 focus:ring-sky-200 focus:border-sky-500">
    </div>
    <select id="mk-sort" class="px-3 py-2 border border-slate-300 rounded-lg text-sm outline-none focus:border-sky-500">
        <option value="featured">Featured</option>
        <option value="popular">Most installed</option>
        <option value="newest">Newest</option>
        <option value="name">Name A–Z</option>
    </select>
</div>

<div class="grid grid-cols-1 lg:grid-cols-4 gap-5">
    <!-- Filter rail -->
    <aside class="lg:col-span-1 space-y-5">
        <div class="bg-white rounded-xl border border-slate-200 p-4">
            <h3 class="text-xs font-semibold text-slate-500 uppercase tracking-wide mb-2">Categories</h3>
            <div id="mk-cats" class="space-y-1 text-sm">
                <button data-cat="" class="mk-cat block w-full text-left px-2 py-1.5 rounded-lg bg-sky-50 text-sky-700 font-medium">All themes</button>
            </div>
        </div>
        <div class="bg-white rounded-xl border border-slate-200 p-4">
            <h3 class="text-xs font-semibold text-slate-500 uppercase tracking-wide mb-2">Popular tags</h3>
            <div id="mk-tags" class="flex flex-wrap gap-1.5">
                <span class="text-xs text-slate-400">Loading…</span>
            </div>
        </div>
    </aside>

    <!-- Results -->
    <div class="lg:col-span-3">
        <div id="mk-status" class="hidden mb-4"></div>
        <div id="mk-grid" class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4">
            <div class="col-span-full text-center text-slate-400 py-16"><?= icon('arrow-path', 'w-6 h-6 animate-spin mb-2 block mx-auto') ?>Loading marketplace…</div>
        </div>
        <nav id="mk-pager" class="hidden items-center justify-center gap-3 mt-6"></nav>
    </div>
</div>

<style>
.mk-cat.active { background:#e0f2fe; color:#0369a1; font-weight:600; }
.mk-tag.active { background:#0ea5e9; color:#fff; border-color:#0ea5e9; }
.line-clamp-2{display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;}
</style>

<script>
(function () {
    var BASE = <?= json_encode($base) ?>, CSRF = <?= json_encode($csrf) ?>;
    var state = { q: '', category: '', tag: '', sort: 'featured', page: 1 };
    var $ = function (id) { return document.getElementById(id); };
    function esc(s){return String(s==null?'':s).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];});}
    function bytes(n){n=+n||0;return n>1048576?(n/1048576).toFixed(1)+' MB':n>1024?Math.round(n/1024)+' KB':n+' B';}
    function get(url){return fetch(BASE+url,{credentials:'same-origin',headers:{'Accept':'application/json'}}).then(function(r){return r.json();});}

    var debounce;
    $('mk-q').addEventListener('input', function () {
        clearTimeout(debounce);
        debounce = setTimeout(function () { state.q = $('mk-q').value.trim(); state.page = 1; load(); }, 350);
    });
    $('mk-sort').addEventListener('change', function () { state.sort = $('mk-sort').value; state.page = 1; load(); });

    function loadFacets() {
        get('/admin/themes/marketplace/facets.json').then(function (d) {
            if (!d || !d.ok) return;
            var cats = $('mk-cats');
            (d.categories || []).forEach(function (c) {
                var b = document.createElement('button');
                b.className = 'mk-cat block w-full text-left px-2 py-1.5 rounded-lg text-slate-600 hover:bg-slate-50';
                b.dataset.cat = c.name;
                b.innerHTML = esc(c.name) + ' <span class="text-slate-400 text-xs">' + c.count + '</span>';
                cats.appendChild(b);
            });
            cats.addEventListener('click', function (e) {
                var btn = e.target.closest('.mk-cat'); if (!btn) return;
                cats.querySelectorAll('.mk-cat').forEach(function (x) { x.classList.remove('active','bg-sky-50','text-sky-700','font-medium'); });
                btn.classList.add('active');
                state.category = btn.dataset.cat; state.page = 1; load();
            });
            var tags = $('mk-tags'); tags.innerHTML = '';
            (d.tags || []).forEach(function (t) {
                var b = document.createElement('button');
                b.className = 'mk-tag text-xs px-2 py-1 rounded-full border border-slate-200 text-slate-600 hover:border-sky-400';
                b.dataset.tag = t.name;
                b.textContent = t.name;
                tags.appendChild(b);
            });
            if (!(d.tags || []).length) tags.innerHTML = '<span class="text-xs text-slate-400">No tags yet</span>';
            tags.addEventListener('click', function (e) {
                var btn = e.target.closest('.mk-tag'); if (!btn) return;
                if (state.tag === btn.dataset.tag) { state.tag = ''; btn.classList.remove('active'); }
                else {
                    tags.querySelectorAll('.mk-tag').forEach(function (x) { x.classList.remove('active'); });
                    btn.classList.add('active'); state.tag = btn.dataset.tag;
                }
                state.page = 1; load();
            });
        });
    }

    function showStatus(html, kind) {
        var s = $('mk-status');
        s.className = 'mb-4 rounded-xl border p-4 text-sm ' +
            (kind === 'error' ? 'bg-red-50 border-red-200 text-red-700' : 'bg-amber-50 border-amber-200 text-amber-800');
        s.innerHTML = html;
        s.classList.remove('hidden');
    }

    function load() {
        var qs = 'q=' + encodeURIComponent(state.q) + '&category=' + encodeURIComponent(state.category)
               + '&tag=' + encodeURIComponent(state.tag) + '&sort=' + encodeURIComponent(state.sort) + '&page=' + state.page;
        $('mk-grid').innerHTML = '<div class="col-span-full text-center text-slate-400 py-16"><?= icon('arrow-path', 'w-6 h-6 animate-spin mb-2 block mx-auto') ?>Loading…</div>';
        $('mk-status').classList.add('hidden');
        get('/admin/themes/marketplace/browse.json?' + qs).then(function (d) {
            if (!d || !d.ok) {
                if (d && d.error === 'not_connected') {
                    showStatus('The Basehim marketplace isn\'t reachable from this site yet. It connects automatically — open <a href="' + BASE + '/admin/updates" class="underline font-medium">Updates</a> to retry, then come back.', 'error');
                } else {
                    showStatus('<?= icon('exclamation-triangle', 'w-4 h-4 mr-1') ?>' + esc((d && d.error) || 'Could not load the marketplace.'), 'error');
                }
                $('mk-grid').innerHTML = ''; $('mk-pager').classList.add('hidden');
                return;
            }
            renderGrid(d.themes || []);
            renderPager(d.meta || {});
        }).catch(function () {
            showStatus('<?= icon('exclamation-triangle', 'w-4 h-4 mr-1') ?>Network error loading the marketplace.', 'error');
            $('mk-grid').innerHTML = '';
        });
    }

    function renderGrid(themes) {
        var grid = $('mk-grid');
        if (!themes.length) {
            grid.innerHTML = '<div class="col-span-full text-center text-slate-400 py-16"><?= icon('swatch', 'w-8 h-8 mb-2 block mx-auto opacity-40') ?>No themes match your search.</div>';
            return;
        }
        grid.innerHTML = themes.map(function (t) {
            var shot = t.screenshot_url
                ? '<img src="' + esc(t.screenshot_url) + '" alt="" class="w-full h-40 object-cover bg-slate-100" loading="lazy" onerror="this.style.display=\'none\'">'
                : '<div class="w-full h-40 grid place-items-center bg-gradient-to-br from-slate-100 to-slate-200 text-slate-300"><?= icon('photo', 'w-8 h-8') ?></div>';
            var feat = t.featured == 1 ? '<span class="absolute top-2 left-2 text-[10px] px-2 py-0.5 rounded-full bg-amber-400 text-amber-900 font-bold shadow"><?= icon('star', 'w-4 h-4 mr-0.5') ?>Featured</span>' : '';
            var tags = (t.tag_list || []).slice(0, 3).map(function (x) {
                return '<span class="text-[10px] px-1.5 py-0.5 rounded bg-slate-100 text-slate-500">' + esc(x) + '</span>';
            }).join(' ');

            var action;
            if (t.installed && t.installed_version === t.version) {
                action = '<button disabled class="w-full px-3 py-2 bg-slate-100 text-slate-400 rounded-lg text-sm font-medium"><?= icon('check', 'w-4 h-4 mr-1') ?>Installed</button>';
            } else if (t.installed) {
                action = '<button class="mk-install w-full px-3 py-2 bg-amber-500 hover:bg-amber-600 text-white rounded-lg text-sm font-medium" data-slug="' + esc(t.slug) + '"><?= icon('arrow-up', 'w-4 h-4 mr-1') ?>Update to v' + esc(t.version) + '</button>';
            } else {
                action = '<button class="mk-install w-full px-3 py-2 bg-sky-600 hover:bg-sky-700 text-white rounded-lg text-sm font-medium" data-slug="' + esc(t.slug) + '"><?= icon('arrow-down-tray', 'w-4 h-4 mr-1') ?>Install</button>';
            }

            return '<div class="bg-white rounded-xl border border-slate-200 overflow-hidden flex flex-col">'
                + '<div class="relative">' + shot + feat + '</div>'
                + '<div class="p-3.5 flex flex-col flex-1">'
                + '<div class="flex items-center justify-between gap-2"><h4 class="text-sm font-semibold text-slate-800 truncate">' + esc(t.name) + '</h4><span class="text-[11px] text-slate-400 shrink-0">v' + esc(t.version) + '</span></div>'
                + '<div class="text-[11px] text-slate-400 mb-1.5">' + (t.author ? 'by ' + esc(t.author) : '') + (t.category ? ' · ' + esc(t.category) : '') + '</div>'
                + '<p class="text-xs text-slate-500 mb-2 line-clamp-2">' + esc(t.description || '') + '</p>'
                + '<div class="flex flex-wrap gap-1 mb-2">' + tags + '</div>'
                + '<div class="text-[11px] text-slate-400 mb-3"><?= icon('arrow-down-tray', 'w-4 h-4 mr-1') ?>' + (t.installs || 0) + ' installs · ' + bytes(t.size_bytes) + '</div>'
                + '<div class="mt-auto">' + action + '</div>'
                + '</div></div>';
        }).join('');

        grid.querySelectorAll('.mk-install').forEach(function (b) {
            b.addEventListener('click', function () { install(b); });
        });
    }

    function install(btn) {
        var slug = btn.dataset.slug;
        var original = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<?= icon('arrow-path', 'w-4 h-4 animate-spin mr-1') ?>Installing…';
        var fd = new FormData(); fd.append('_csrf', CSRF); fd.append('slug', slug);
        fetch(BASE + '/admin/themes/marketplace/install', { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d.ok) {
                    btn.className = 'w-full px-3 py-2 bg-emerald-100 text-emerald-700 rounded-lg text-sm font-medium';
                    btn.innerHTML = '<?= icon('check', 'w-4 h-4 mr-1') ?>' + (d.replaced ? 'Updated' : 'Installed') + '!';
                    showStatus('<?= icon('check-circle', 'w-4 h-4 mr-1 text-emerald-600') ?>"' + esc(d.manifest && d.manifest.name || slug) + '" ' + (d.replaced ? 'updated' : 'installed') + '. <a href="' + BASE + '/admin/themes" class="underline font-medium">Go to Themes</a> to activate it.', 'ok');
                    $('mk-status').className = 'mb-4 rounded-xl border p-4 text-sm bg-emerald-50 border-emerald-200 text-emerald-800';
                } else {
                    btn.disabled = false; btn.innerHTML = original;
                    showStatus('<?= icon('exclamation-triangle', 'w-4 h-4 mr-1') ?>' + esc(d.error || 'Install failed.'), 'error');
                }
            })
            .catch(function () {
                btn.disabled = false; btn.innerHTML = original;
                showStatus('<?= icon('exclamation-triangle', 'w-4 h-4 mr-1') ?>Network error during install.', 'error');
            });
    }

    function renderPager(meta) {
        var pager = $('mk-pager');
        if (!meta.last_page || meta.last_page <= 1) { pager.classList.add('hidden'); return; }
        pager.classList.remove('hidden');
        pager.classList.add('flex');
        var html = '';
        if (meta.page > 1) html += '<button data-pg="' + (meta.page - 1) + '" class="mk-pg px-3 py-1.5 border border-slate-200 rounded-lg text-sm hover:bg-slate-50"><?= icon('arrow-left', 'w-4 h-4') ?></button>';
        html += '<span class="text-sm text-slate-500">Page ' + meta.page + ' of ' + meta.last_page + '</span>';
        if (meta.page < meta.last_page) html += '<button data-pg="' + (meta.page + 1) + '" class="mk-pg px-3 py-1.5 border border-slate-200 rounded-lg text-sm hover:bg-slate-50"><?= icon('arrow-right', 'w-4 h-4') ?></button>';
        pager.innerHTML = html;
        pager.querySelectorAll('.mk-pg').forEach(function (b) {
            b.addEventListener('click', function () { state.page = +b.dataset.pg; load(); window.scrollTo({ top: 0, behavior: 'smooth' }); });
        });
    }

    loadFacets();
    load();
})();
</script>

<?php $this->endSection(); ?>
