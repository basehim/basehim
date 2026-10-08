<?php
/**
 * Details dialog for a marketplace item (app or theme).
 *
 * The marketplace grid shows each item as a card; everything else about it
 * opens here. The page fills it through window.bhItemModal.open({...}); see
 * partials/marketplace.php.
 *
 * Every string handed to open() as `text` is set with textContent. Only the
 * fields named *Html are inserted as markup, and the views build those from
 * escaped values.
 */
?>
<dialog id="bh-im" class="bh-im" aria-labelledby="bh-im-name">
    <div id="bh-im-media" class="bh-im__media hidden"></div>
    <div class="bh-im__head">
        <div id="bh-im-icon" class="shrink-0"></div>
        <div class="min-w-0 flex-1">
            <div class="flex items-center gap-2 flex-wrap">
                <h3 id="bh-im-name" class="bh-im__name"></h3>
                <span id="bh-im-badge"></span>
            </div>
            <div id="bh-im-who" class="text-sm text-slate-500"></div>
        </div>
        <button type="button" class="bh-im__x" data-close aria-label="Close"><?= icon('x-mark', 'w-5 h-5') ?></button>
    </div>
    <div class="bh-im__body">
        <div id="bh-im-chips" class="flex items-center gap-2 flex-wrap text-sm mb-3"></div>
        <dl id="bh-im-stats" class="bh-im__stats hidden"></dl>
        <p id="bh-im-desc" class="bh-im__desc"></p>
        <dl id="bh-im-facts" class="bh-im__facts"></dl>
        <div id="bh-im-extra"></div>
    </div>
    <div class="bh-im__foot">
        <div id="bh-im-state" class="text-xs mr-auto"></div>
        <button type="button" class="bh-im__btn bh-im__btn--cancel" data-close>Close</button>
        <span id="bh-im-action"></span>
    </div>
</dialog>
<style>
    .bh-im { border: 0; padding: 0; border-radius: 1.1rem; width: min(42rem, calc(100vw - 2rem)); max-height: min(46rem, calc(100vh - 2rem)); color: #0f172a; box-shadow: 0 30px 70px -25px rgba(2, 6, 23, .55); }
    .bh-im[open] { display: flex; flex-direction: column; animation: bh-ask-in .16s cubic-bezier(.2, .7, .3, 1); }
    .bh-im::backdrop { background: rgba(15, 23, 42, .4); -webkit-backdrop-filter: blur(5px); backdrop-filter: blur(5px); }
    .bh-im__media { flex: none; background: #f1f5f9; border-bottom: 1px solid #e2e8f0; }
    .bh-im__media img { display: block; width: 100%; max-height: 18rem; object-fit: cover; object-position: top; }
    .bh-im__head { display: flex; align-items: center; gap: 1.1rem; padding: 1.5rem 1.5rem 1rem; }
    .bh-im__name { font-size: 1.35rem; font-weight: 700; line-height: 1.25; color: #0f172a; letter-spacing: -.01em; }
    .bh-im__stats { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); margin: .25rem 0 1rem; padding: .85rem 0; border-top: 1px solid #f1f5f9; border-bottom: 1px solid #f1f5f9; }
    .bh-im__stats > div { display: flex; flex-direction: column-reverse; text-align: center; padding: 0 .4rem; min-width: 0; }
    .bh-im__stats > div + div { border-left: 1px solid #f1f5f9; }
    .bh-im__stats dd { margin: 0; font-size: 1.05rem; font-weight: 700; color: #0f172a; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .bh-im__stats dt { font-size: .72rem; color: #64748b; margin-top: .1rem; }
    .bh-im__desc { font-size: .9rem; line-height: 1.6; color: #334155; margin-bottom: 1rem; white-space: pre-line; }
    @media (max-width: 480px) { .bh-im__stats { grid-template-columns: repeat(2, minmax(0, 1fr)); row-gap: .8rem; } .bh-im__stats > div:nth-child(3) { border-left: 0; } }
    .bh-im__x { align-self: flex-start; padding: .25rem; border-radius: .4rem; color: #64748b; }
    .bh-im__x:hover { background: #f1f5f9; color: #0f172a; }
    .bh-im__body { padding: 0 1.5rem 1rem; overflow-y: auto; flex: 1 1 auto; min-height: 0; }
    .bh-im__facts { display: grid; grid-template-columns: auto 1fr; gap: .55rem 1.25rem; font-size: .82rem; padding: .25rem 0 .5rem; }
    .bh-im__facts dt { color: #64748b; }
    .bh-im__facts dd { color: #0f172a; margin: 0; overflow-wrap: anywhere; }
    .bh-im__foot { display: flex; align-items: center; justify-content: flex-end; gap: .5rem; flex-wrap: wrap; padding: 1rem 1.5rem 1.15rem; border-top: 1px solid #f1f5f9; }
    .bh-im__btn { padding: .55rem 1.1rem; border-radius: 999px; font: inherit; font-size: .875rem; font-weight: 600; cursor: pointer; border: 1px solid transparent; }
    .bh-im__btn--cancel { background: #fff; color: #0f172a; border-color: #cbd5e1; font-weight: 500; }
    .bh-im__btn--cancel:hover { background: #f8fafc; }
    .bh-im__btn:focus-visible, .bh-im__x:focus-visible, .mk-open:focus-visible { outline: 2px solid #93c5fd; outline-offset: 2px; border-radius: .5rem; }
    @media (prefers-reduced-motion: reduce) { .bh-im[open] { animation: none; } }
</style>
<script>
window.bhItemModal = (function () {
    var d = document.getElementById('bh-im');
    if (!d) return { open: function () {}, close: function () {}, current: function () { return null; } };
    var g = function (id) { return document.getElementById(id); };
    var cur = null, onClose = null;

    function html(id, v) { var el = g(id); el.innerHTML = v || ''; el.classList.toggle('hidden', !v); }

    /**
     * opts: { key, name, iconHtml, mediaHtml, badgeHtml, whoHtml, chipsHtml,
     *         stats: [[label, valueHtml]], description,
     *         facts: [[label, valueHtml]], extraHtml, onClose }
     */
    function open(opts) {
        cur = opts.key || null;
        onClose = opts.onClose || null;
        g('bh-im-name').textContent = opts.name || '';
        html('bh-im-media', opts.mediaHtml);
        html('bh-im-icon', opts.iconHtml);
        g('bh-im-badge').innerHTML = opts.badgeHtml || '';
        html('bh-im-who', opts.whoHtml);
        html('bh-im-chips', opts.chipsHtml);
        var stats = (opts.stats || []).filter(function (f) { return f && f[1]; });
        g('bh-im-stats').innerHTML = stats.map(function (f) { return '<div><dt>' + f[0] + '</dt><dd>' + f[1] + '</dd></div>'; }).join('');
        g('bh-im-stats').classList.toggle('hidden', !stats.length);
        var desc = g('bh-im-desc');
        desc.textContent = opts.description || '';
        desc.classList.toggle('hidden', !opts.description);
        g('bh-im-facts').innerHTML = (opts.facts || []).filter(function (f) { return f && f[1]; })
            .map(function (f) { return '<dt>' + f[0] + '</dt><dd>' + f[1] + '</dd>'; }).join('');
        g('bh-im-extra').innerHTML = opts.extraHtml || '';
        g('bh-im-state').innerHTML = '';
        g('bh-im-action').innerHTML = '';
        if (typeof d.showModal === 'function') { if (!d.open) d.showModal(); } else d.setAttribute('open', '');
        var x = d.querySelector('.bh-im__x'); if (x) x.focus();
    }
    function close() { if (d.open) d.close(); }
    d.addEventListener('click', function (e) {
        if (e.target === d || (e.target.closest && e.target.closest('[data-close]'))) close();
    });
    d.addEventListener('close', function () { var f = onClose; cur = null; onClose = null; if (f) f(); });
    return {
        open: open, close: close,
        current: function () { return cur; },
        action: function (htmlStr) { g('bh-im-action').innerHTML = htmlStr || ''; return g('bh-im-action'); },
        state: function (htmlStr, kind) {
            var s = g('bh-im-state');
            s.className = 'text-xs mr-auto ' + (kind === 'error' ? 'text-red-700' : kind === 'ok' ? 'text-emerald-700' : 'text-slate-500');
            s.innerHTML = htmlStr || '';
        }
    };
})();
</script>
