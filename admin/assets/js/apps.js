/**
 * Apps screen (/admin/apps) — small progressive enhancements, no inline JS.
 *
 *  - [data-apps-upload-toggle] shows/hides the upload panel (#app-upload-panel).
 *  - form[data-confirm] asks with the admin dialog (bhConfirm) before submitting,
 *    so destructive actions get the same confirm UI as the rest of the admin.
 */
(function () {
    'use strict';

    document.addEventListener('click', function (e) {
        var t = e.target.closest('[data-apps-upload-toggle]');
        if (!t) return;
        var panel = document.getElementById('app-upload-panel');
        if (!panel) return;
        var opening = panel.hasAttribute('hidden');
        if (opening) panel.removeAttribute('hidden'); else panel.setAttribute('hidden', '');
        t.setAttribute('aria-expanded', opening ? 'true' : 'false');
        if (opening) {
            var input = panel.querySelector('input[type="file"]');
            if (input) input.focus();
        }
    });

    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form || !form.matches || !form.matches('form[data-confirm]')) return;
        if (form.getAttribute('data-confirmed') === '1') return;   // already confirmed
        e.preventDefault();
        var msg = form.getAttribute('data-confirm') || 'Are you sure?';
        var opts = {
            danger: form.hasAttribute('data-confirm-danger'),
            confirmLabel: form.getAttribute('data-confirm-label') || 'Confirm'
        };
        var ask = window.bhConfirm ? bhConfirm(msg, opts) : Promise.resolve(window.confirm(msg));
        Promise.resolve(ask).then(function (ok) {
            if (!ok) return;
            form.setAttribute('data-confirmed', '1');
            if (typeof form.requestSubmit === 'function') form.requestSubmit(); else form.submit();
        });
    }, true);
})();
