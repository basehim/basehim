/**
 * The permission window for an app (1.2.85), shared by the Apps screen and
 * the Marketplace. It draws inside the details window (partials/item-modal,
 * window.bhItemModal): one switch per permission the app asks for, with what
 * it allows and how risky it is, and the window's own footer buttons.
 *
 *   BasehimAppConsent.show({
 *     slug, data,                 // data: an apps.consent / apps.market_consent answer
 *     header: { name, iconHtml }, // used when the window isn't open for this app
 *     mode: 'activate' | 'review',// activate: "Allow and activate" / "Activate without them"
 *                                 // review:   "Save" (or saveLabel)
 *     needed: ['db.raw'],         // switched on and marked "Needed for this page"
 *     submit: function (granted, activate) { return Promise }, // the page's gateway call
 *     done: function (result) {}  // after a successful submit; the window is closed
 *   });
 *
 * Nothing here talks to the server: the page passes `submit`, so each screen
 * keeps its own gateway action and capability.
 */
(function () {
    'use strict';
    if (window.BasehimAppConsent) return;

    function ic(name, cls) { return window.BasehimIcon ? BasehimIcon(name, cls == null ? 'w-4 h-4' : cls) : ''; }
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
    function $(id) { return document.getElementById(id); }

    var SOURCE = {
        marketplace: 'Verified download from the Basehim marketplace',
        upload: 'Installed from your upload',
        devtools: 'Added through Basehim Dev Tools',
        legacy: 'Installed on this site'
    };

    /** "Checked for you": where the app came from, the scan, the code check. */
    function checks(d, slug) {
        var c = d.checks;
        if (!c) return '';
        var base = (document.body && document.body.getAttribute('data-base')) || '';
        var items = ['<li>' + ic('check-circle', '') + '<span>' + esc(SOURCE[c.source] || SOURCE.legacy) + '</span></li>'];
        if (c.scanned && !c.findings) items.push('<li>' + ic('check-circle', '') + '<span>Security scan found nothing to worry about</span></li>');
        else if (c.scanned) items.push('<li class="is-note">' + ic('information-circle', '') + '<span>The security scan noted ' + c.findings + ' thing' + (c.findings === 1 ? '' : 's')
            + ' worth a look. <a href="' + esc(base) + '/admin/apps/' + encodeURIComponent(slug) + '/consent">See what</a></span></li>');
        if (c.code_ok) items.push('<li>' + ic('check-circle', '') + '<span>Its code was checked before it can run</span></li>');
        return '<div class="bh-consent-checks"><p class="bh-consent-checks__title">' + ic('shield-check', '') + 'Checked for you</p><ul>' + items.join('') + '</ul></div>';
    }

    var current = null;

    function show(o) {
        if (!window.bhItemModal) return;
        var d = o.data || {};
        if (bhItemModal.current() !== o.slug) {
            bhItemModal.open({ key: o.slug, name: (o.header && o.header.name) || d.name || o.slug,
                               iconHtml: (o.header && o.header.iconHtml) || '', whoHtml: (o.header && o.header.whoHtml) || '' });
        }
        current = o;
        var review = o.mode === 'review';
        var granted = {};
        (d.granted || []).forEach(function (k) { granted[k] = true; });
        var reasons = d.reasons || {};
        var rows = (d.permissions || []).map(function (p, i) {
            var need = (o.needed || []).indexOf(p.key) !== -1;
            var on = need || (d.consented ? !!granted[p.key] : true);
            var id = 'bh-consent-' + i;
            var why = reasons[p.key];
            return '<li><label class="bh-mk-perm" for="' + id + '">'
                 + '<span class="bh-mk-perm__main"><span class="bh-mk-perm__top"><span class="bh-mk-perm__label">' + esc(p.label || p.key) + '</span>'
                 + (need ? '<span class="bh-badge bh-badge--blue">Needed for this page</span>' : '')
                 + (p.risk === 'high' ? '<span class="bh-consent-tag" title="' + esc(p.description || '') + '">Sensitive</span>' : '') + '</span>'
                 + '<span class="bh-mk-perm__desc">' + esc(p.friendly || p.description || '') + '</span>'
                 + (why ? '<span class="bh-consent-why">' + ic('chat-bubble-left-ellipsis', '') + '<span>' + esc(why) + '</span></span>' : '')
                 + '</span>'
                 + '<span class="bh-switch"><input type="checkbox" role="switch" class="bh-switch__input" id="' + id + '" name="perm" value="' + esc(p.key) + '"' + (on ? ' checked' : '') + '>'
                 + '<span class="bh-switch__track" aria-hidden="true"></span></span>'
                 + '</label></li>';
        }).join('');
        var unknown = d.unknown || [];
        ['bh-im-chips', 'bh-im-stats', 'bh-im-desc', 'bh-im-facts', 'bh-im-media'].forEach(function (id) { var el = $(id); if (el) el.classList.add('hidden'); });
        var name = esc((o.header && o.header.name) || d.name || o.slug);
        var n = (d.permissions || []).length;
        $('bh-im-extra').innerHTML =
            '<p class="bh-mk-consent__lead">' + ((o.needed || []).length
                ? 'Allow what’s marked “Needed for this page” and “' + name + '” opens straight away. The rest stay as they are.'
                : review
                ? 'These are what “' + name + '” can do on your site. Turn any off if you’d rather it didn’t; it may then do less.'
                : '“' + name + '” needs ' + (n === 1 ? 'one permission' : 'these ' + n + ' permissions') + ' to work. ' + (n === 1 ? 'It’s' : 'They’re') + ' switched on for you, and you can change ' + (n === 1 ? 'it' : 'them') + ' at any time from Apps.') + '</p>'
          + checks(d, o.slug)
          + (rows ? '<ul class="bh-mk-perms">' + rows + '</ul>' : '<p class="bh-mk-consent__lead">This app doesn’t need any special permissions.</p>')
          + (unknown.length ? '<p class="bh-consent-unknown">' + ic('information-circle', '') + '<span>It also lists ' + unknown.map(esc).join(', ') + ', which Basehim doesn’t recognise, so ' + (unknown.length === 1 ? 'it has' : 'they have') + ' no effect.</span></p>' : '');
        bhItemModal.state('');
        bhItemModal.action(review
            ? '<button type="button" class="bh-btn bh-btn--primary" data-consent-save>' + ic('check') + esc(o.saveLabel || 'Save') + '</button>'
            : '<button type="button" class="bh-btn bh-btn--ghost" data-consent-none>Activate without them</button>'
            + '<button type="button" class="bh-btn bh-btn--primary" data-consent-allow>' + ic('check') + 'Allow and activate</button>');
        var main = document.querySelector('#bh-im-action .bh-btn--primary');
        if (main) main.focus();
    }

    function checked() {
        return Array.prototype.map.call(document.querySelectorAll('#bh-im input[name="perm"]:checked'), function (c) { return c.value; });
    }

    function submit(btn, granted, activate) {
        var o = current; if (!o) return;
        var btns = document.querySelectorAll('#bh-im-action button');
        Array.prototype.forEach.call(btns, function (b) { b.disabled = true; });
        if (window.bhBusy) bhBusy(btn, true, activate ? 'Activating…' : 'Saving…');
        bhItemModal.state('');
        Promise.resolve(o.submit(granted, activate)).then(function (result) {
            if (window.bhBusy) bhBusy(btn, false);
            current = null;
            bhItemModal.close();
            if (o.done) o.done(result || {});
        }).catch(function (err) {
            if (window.bhBusy) bhBusy(btn, false);
            Array.prototype.forEach.call(btns, function (b) { b.disabled = false; });
            bhItemModal.state(ic('exclamation-triangle', '') + esc((err && err.message) || 'That didn’t work. Try again.'), 'error');
        });
    }

    document.addEventListener('click', function (e) {
        if (!current || !e.target.closest) return;
        var b;
        if ((b = e.target.closest('#bh-im [data-consent-allow]'))) submit(b, checked(), true);
        else if ((b = e.target.closest('#bh-im [data-consent-none]'))) submit(b, [], true);
        else if ((b = e.target.closest('#bh-im [data-consent-save]'))) submit(b, checked(), false);
    });
    var dlg = document.getElementById('bh-im');
    if (dlg) dlg.addEventListener('close', function () { current = null; });

    window.BasehimAppConsent = {
        show: show,
        /** Whether the window is showing the permission step for this app. */
        isOpen: function (slug) { return !!current && current.slug === slug; }
    };
})();
