/**
 * Apps screen (/admin/apps, 1.2.85).
 *
 * The list is the core Data Table `apps.installed` (datatable.js); this file
 * runs its actions over the AJAX gateway and draws the permission window:
 *
 *   bh:dt-bulk   activate / deactivate / retry / delete → apps.bulk
 *                (an app that needs approval opens the permission window)
 *   bh:dt-action permissions → apps.consent, then BasehimAppConsent
 *                (app-consent.js) → apps.consent_save
 *
 * Plus the upload card: the toggle in the header and the chosen file's name.
 * Every call goes through Basehim.ajax; messages come back as toasts.
 */
(function () {
    'use strict';

    var root = document.getElementById('apps-root');
    if (!root) return;
    var table = root.querySelector('.bh-dt');

    function toast(msg, type) { if (window.bhToast) bhToast(msg, type); }
    function call(name, data, method) { return Basehim.ajax.request('apps.' + name, data || {}, { method: method || 'POST' }); }
    function reload() { if (table) table.dispatchEvent(new CustomEvent('bh:dt-reload', { detail: { clearSelection: true } })); }
    function busy(on) { if (table) table.classList.toggle('is-loading', !!on); }
    function iconOf(id) {
        var el = table && table.querySelector('[data-dt-row="' + id + '"] .bh-app-icon, [data-dt-rowid="' + id + '"] .bh-app-icon');
        return el ? el.outerHTML : '';
    }

    /* ---------- upload card ---------- */
    document.addEventListener('click', function (e) {
        var t = e.target.closest && e.target.closest('[data-apps-upload-toggle]');
        if (!t) return;
        var panel = document.getElementById('app-upload-panel');
        if (!panel) return;
        var opening = panel.hasAttribute('hidden');
        if (opening) panel.removeAttribute('hidden'); else panel.setAttribute('hidden', '');
        t.setAttribute('aria-expanded', opening ? 'true' : 'false');
        if (opening) { var input = panel.querySelector('input[type="file"]'); if (input) input.focus(); }
    });
    var form = root.querySelector('[data-apps-upload]');
    if (form) {
        var file = form.querySelector('input[type="file"]');
        var label = form.querySelector('[data-apps-file]');
        file.addEventListener('change', function () {
            if (label) label.textContent = file.files && file.files[0] ? file.files[0].name : 'Choose the app’s .zip file';
        });
        form.addEventListener('submit', function () {
            var btn = form.querySelector('button[type="submit"]');
            if (btn && window.bhBusy) bhBusy(btn, true, 'Installing…');
        });
    }

    /* ---------- the permission window ---------- */
    function openPermissions(slug, mode, iconHtml) {
        busy(true);
        return call('consent', { slug: slug }, 'GET').then(function (d) {
            busy(false);
            BasehimAppConsent.show({
                slug: slug, data: d, mode: mode,
                header: { name: d.name, iconHtml: iconHtml || '' },
                submit: function (granted, activate) {
                    return call('consent_save', { slug: slug, permissions: granted, activate: !!activate });
                },
                done: function (r) {
                    var withheld = r.withheld_count ? ' ' + r.withheld_count + ' permission' + (r.withheld_count === 1 ? '' : 's') + ' withheld.' : '';
                    toast('“' + d.name + '” ' + (mode === 'activate' ? 'is active.' : 'permissions saved.') + withheld, 'success');
                    reload();
                }
            });
        }).catch(function (err) { busy(false); toast(err.message, 'error'); });
    }

    /* ---------- the table's actions ---------- */
    var WORDS = { activate: 'activated', deactivate: 'deactivated', retry: 'checked', delete: 'deleted' };

    function runBulk(action, ids) {
        busy(true);
        call('bulk', { action: action, ids: ids.map(Number) }).then(function (r) {
            busy(false);
            var results = r.results || [];
            var waiting = results.filter(function (x) { return x.needs_consent; });
            var failed = results.filter(function (x) { return !x.ok && !x.needs_consent; });
            // One app that needs approval: show its permissions right away.
            if (action === 'activate' && ids.length === 1 && waiting.length === 1) {
                openPermissions(waiting[0].slug, 'activate', iconOf(ids[0]));
                return;
            }
            if (r.done) {
                toast(r.done === 1 && results.length === 1
                    ? results[0].message
                    : r.done + ' app' + (r.done === 1 ? '' : 's') + ' ' + WORDS[action] + '.', 'success');
            }
            if (waiting.length) toast(waiting.length + (waiting.length === 1 ? ' app needs' : ' apps need') + ' your approval first: open its Permissions.', 'warning');
            failed.forEach(function (x) { toast((x.slug ? x.slug + ': ' : '') + x.message, 'error'); });
            reload();
        }).catch(function (err) { busy(false); toast(err.message, 'error'); });
    }

    if (table) {
        table.addEventListener('bh:dt-bulk', function (e) {
            var d = e.detail || {};
            if (['activate', 'deactivate', 'retry', 'delete'].indexOf(d.action) === -1) return;
            e.preventDefault();
            runBulk(d.action, d.ids || []);
        });
        table.addEventListener('bh:dt-action', function (e) {
            var d = e.detail || {};
            if (d.action === 'permissions' && d.key) openPermissions(d.key, 'review', iconOf(d.id));
        });
    }
})();
