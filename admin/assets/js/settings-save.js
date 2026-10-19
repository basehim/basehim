/**
 * Settings screens: Save without a page load (1.2.50).
 *
 * Each Settings form (POST /admin/settings/{tab}) is sent through the AJAX
 * gateway as `settings.save`, encoded exactly as the browser would have
 * posted it, while the shared loader (bhLoader) spins inside the Save button.
 * The server saves it with the same code as the form route, so a field means
 * the same either way. Afterwards:
 *
 *   - the message appears where the page's own message would have been;
 *   - fields the server adjusted (an empty From Email, a clamped number) show
 *     the stored value;
 *   - panels the server works out from the settings (the .htaccess rules, the
 *     sender-domain warning, the AI status table) are fetched again: any
 *     element marked data-settings-live="name" is replaced by its fresh copy.
 *
 * Without script, or if the gateway client is missing, the form posts as it
 * always did. A form can opt out with data-no-ajax.
 */
(function () {
    'use strict';

    var TABS = ['general', 'reading', 'writing', 'discussion', 'seo', 'ai', 'appearance', 'permalinks', 'media', 'email', 'authorization'];

    function tabOf(form) {
        var path;
        try { path = new URL(form.getAttribute('action') || location.href, location.href).pathname; } catch (e) { return null; }
        var m = path.match(/\/admin\/settings\/([a-z]+)\/?$/);
        return m && TABS.indexOf(m[1]) !== -1 ? m[1] : null;
    }

    // The fields as the browser would post them (application/x-www-form-urlencoded).
    function encode(form) {
        var params = new URLSearchParams();
        new FormData(form).forEach(function (v, k) { if (typeof v === 'string') params.append(k, v); });
        return params.toString();
    }

    function icon(name) {
        return typeof window.BasehimIcon === 'function' ? window.BasehimIcon(name, 'inline-block w-4 h-4 mr-2 shrink-0') : '';
    }

    // The same box the page shows after a normal save (layouts/app.php).
    function notice(type, message) {
        var main = document.querySelector('main');
        if (!main) return null;
        var old = main.querySelector('[data-bh-flash]');
        var box = document.createElement('div');
        box.setAttribute('data-bh-flash', '');
        box.setAttribute('role', type === 'error' ? 'alert' : 'status');
        box.className = 'mb-4 px-4 py-3 rounded-lg border ' + (type === 'error'
            ? 'bg-red-50 border-red-200 text-red-800'
            : 'bg-green-50 border-green-200 text-green-800');
        box.innerHTML = icon(type === 'error' ? 'x-circle' : 'check-circle');
        box.appendChild(document.createTextNode(String(message)));
        if (old) old.replaceWith(box); else main.insertBefore(box, main.firstChild);
        return box;
    }

    function inView(el) {
        if (!el) return false;
        var r = el.getBoundingClientRect();
        return r.bottom > 0 && r.top < (window.innerHeight || document.documentElement.clientHeight);
    }

    // Show what was stored, for fields the server adjusted on the way in.
    function applyValues(form, values) {
        if (!values) return;
        Array.prototype.forEach.call(form.elements, function (el) {
            if (!el.name || !Object.prototype.hasOwnProperty.call(values, el.name)) return;
            var t = (el.type || '').toLowerCase();
            if (['hidden', 'password', 'checkbox', 'radio', 'file', 'submit', 'button', 'reset'].indexOf(t) !== -1) return;
            var v = values[el.name] == null ? '' : String(values[el.name]);
            if (el.tagName === 'SELECT' && !Array.prototype.some.call(el.options, function (o) { return o.value === v; })) return;
            if (el.value !== v) el.value = v;
        });
        Array.prototype.forEach.call(form.querySelectorAll('input[type=password]'), function (el) { el.value = ''; });
    }

    // Re-read the screen and swap in the panels the server derives from the settings.
    function refreshLive() {
        var live = document.querySelectorAll('[data-settings-live]');
        if (!live.length || typeof DOMParser !== 'function') return Promise.resolve();
        return fetch(location.href, { credentials: 'same-origin', cache: 'no-store', headers: { 'Accept': 'text/html' } })
            .then(function (res) { return res.ok ? res.text() : ''; })
            .then(function (html) {
                if (!html) return;
                var doc = new DOMParser().parseFromString(html, 'text/html');
                Array.prototype.forEach.call(live, function (el) {
                    var name = el.getAttribute('data-settings-live');
                    var fresh = doc.querySelector('[data-settings-live="' + (window.CSS && CSS.escape ? CSS.escape(name) : name) + '"]');
                    if (fresh) el.replaceWith(document.importNode(fresh, true));
                });
            })
            .catch(function () { /* the save itself succeeded; the panels stay as they were */ });
    }

    function retitle(data) {
        if (data && typeof data.document_title === 'string' && data.document_title) document.title = data.document_title;
        if (data && typeof data.site_title === 'string') {
            var name = data.site_title || 'Basehim';
            Array.prototype.forEach.call(document.querySelectorAll('[data-bh-site-name]'), function (a) {
                a.textContent = name;
                a.title = 'View ' + name + ' — opens in a new tab';
            });
        }
    }

    function bind(form) {
        var tab = tabOf(form);
        if (!tab || form.hasAttribute('data-no-ajax') || form._bhSettings) return;
        form._bhSettings = true;

        form.addEventListener('submit', function (e) {
            if (e.defaultPrevented) return;              // a confirm() or another handler said no
            var B = window.Basehim;
            if (!B || !B.ajax || typeof B.ajax.request !== 'function' || typeof window.bhLoader !== 'function') return;
            e.preventDefault();
            if (form._bhSaving) return;
            form._bhSaving = true;

            // The spinner goes inside the Save button; bhLoader disables it meanwhile.
            var btn = e.submitter || form.querySelector('[type=submit]');
            var loader = window.bhLoader(btn, 'Saving…');

            B.ajax.request('settings.save', { tab: tab, form: encode(form) }, { timeout: tab === 'permalinks' ? 60000 : 30000 })
                .then(function (data) {
                    data = data || {};
                    var warn = data.type === 'error';
                    applyValues(form, data.values);
                    retitle(data);
                    // A screen's own script can follow what was saved (1.2.66).
                    try { form.dispatchEvent(new CustomEvent('bh:settings-saved', { detail: data })); } catch (e) { /* old browsers: nothing listens */ }
                    var box = notice(warn ? 'error' : 'success', data.message || 'Settings saved.');
                    // Saved with a warning (.htaccess not written, bad catalog JSON):
                    // the settings did save, so the button says so; the banner
                    // (or a toast, when it is scrolled away) says what to fix.
                    loader.done('Saved');
                    if (warn && !inView(box) && window.bhToast) window.bhToast(data.message, 'warning', { timeout: 8000 });
                    return refreshLive();
                }, function (err) {
                    var msg = (err && err.message) || 'The settings could not be saved.';
                    loader.fail('Not saved');
                    var box = notice('error', msg);
                    if (!inView(box) && window.bhToast) window.bhToast(msg, 'error');
                })
                .then(function () { form._bhSaving = false; });
        });
    }

    function init() {
        Array.prototype.forEach.call(document.querySelectorAll('form[method="POST"], form[method="post"]'), bind);
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
