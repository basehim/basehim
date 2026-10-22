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
 *
 * Styles: .bh-im* in admin/assets/css/src/tailwind.css (1.2.84; until then an
 * inline style block here with its own colours).
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
            <div id="bh-im-who" class="bh-im__who"></div>
        </div>
        <button type="button" class="bh-btn bh-btn--ghost bh-btn--icon bh-btn--sm bh-im__x" data-close aria-label="Close"><?= icon('x-mark', 'w-5 h-5') ?></button>
    </div>
    <div class="bh-im__body">
        <div id="bh-im-chips" class="bh-im__chips"></div>
        <dl id="bh-im-stats" class="bh-im__stats hidden"></dl>
        <p id="bh-im-desc" class="bh-im__desc"></p>
        <dl id="bh-im-facts" class="bh-im__facts"></dl>
        <div id="bh-im-extra"></div>
    </div>
    <div class="bh-im__foot">
        <div id="bh-im-state" class="bh-im__state" role="status"></div>
        <button type="button" class="bh-btn bh-btn--secondary" data-close>Close</button>
        <span id="bh-im-action" class="bh-im__action"></span>
    </div>
</dialog>
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
            s.className = 'bh-im__state' + (kind === 'error' ? ' is-error' : kind === 'ok' ? ' is-ok' : '');
            s.innerHTML = htmlStr || '';
        }
    };
})();
</script>
<?php $__imBase = defined('BASEHIM_BASE') ? rtrim((string) BASEHIM_BASE, '/') : ''; ?>
<?php // The permission window drawn inside this dialog (1.2.85; Apps and Marketplace). ?>
<script src="<?= $__imBase ?>/admin/assets/js/app-consent.js?v=<?= urlencode(defined('BASEHIM_VERSION') ? (string) BASEHIM_VERSION : '1') ?>" defer></script>
