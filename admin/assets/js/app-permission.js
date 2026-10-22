/**
 * An app's screen asking for a permission the app needs (1.2.85;
 * admin/views/apps/permission-needed.php). Opens the permission window with
 * the missing permission switched on; after Save the page reloads, and the
 * app's own screen appears.
 */
(function () {
    'use strict';
    var root = document.getElementById('perm-gate');
    if (!root || root.getAttribute('data-can') !== '1' || !window.Basehim || !Basehim.ajax) return;
    var slug = root.getAttribute('data-slug');
    var needed = root.getAttribute('data-permission');
    var iconEl = root.querySelector('[data-perm-icon]');

    function open() {
        Basehim.ajax.request('apps.consent', { slug: slug }, { method: 'GET' }).then(function (d) {
            BasehimAppConsent.show({
                slug: slug, data: d, mode: 'review', needed: [needed], saveLabel: 'Allow and open',
                header: { name: d.name, iconHtml: iconEl ? iconEl.outerHTML.replace('bh-perm-gate__icon', '') : '' },
                submit: function (granted) {
                    return Basehim.ajax.request('apps.consent_save', { slug: slug, permissions: granted, activate: true });
                },
                done: function () {
                    if (window.bhToast) bhToast('Allowed. Opening it now…', 'success');
                    window.location.reload();
                }
            });
        }).catch(function (err) { if (window.bhToast) bhToast(err.message, 'error'); });
    }

    root.addEventListener('click', function (e) { if (e.target.closest('[data-perm-review]')) open(); });
    open();
})();
