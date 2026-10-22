/**
 * Settings: move between sections without a page load (1.2.82).
 *
 * The tabs (desktop), the phone's list of sections and the phone's
 * "‹ Settings" back link load the section through the AJAX gateway
 * (settings.section), which returns the section's page content exactly as a
 * page load renders it. It replaces the content of <main>, the section's own
 * scripts run again, its forms get in-page saving (settings-save.js), and the
 * address, title and Back button follow. On phones the section slides in from
 * the right, and back to the list from the left, as in a phone's own
 * Settings app; reduced motion turns that off.
 *
 * Anything unexpected (no gateway client, an error, a modified click) falls
 * back to the ordinary link, so the page always loads one way or the other.
 */
(function () {
    'use strict';
    if (window.bhSettingsNav) return;   // loaded again with a swapped-in section
    window.bhSettingsNav = true;

    var REDUCED = window.matchMedia ? window.matchMedia('(prefers-reduced-motion: reduce)') : { matches: false };
    var PHONE = window.matchMedia ? window.matchMedia('(max-width: 639px)') : { matches: false };
    var busy = null;

    // /admin/settings → "home"; /admin/settings/reading → "reading".
    function tabOf(href) {
        var u;
        try { u = new URL(href, location.href); } catch (e) { return null; }
        if (u.origin !== location.origin || u.search || u.hash) return null;
        var m = u.pathname.match(/\/admin\/settings(?:\/([a-z]+))?\/?$/);
        if (!m) return null;
        // Appearance lives in the Customizer: its Settings address redirects there.
        if (m[1] === 'appearance') return null;
        return m[1] || 'home';
    }

    function current() { return tabOf(location.href); }

    // Scripts in swapped-in HTML don't run by themselves: re-create them. A
    // file already on the page (the shared settings scripts) isn't loaded twice.
    function runScripts(root) {
        Array.prototype.forEach.call(root.querySelectorAll('script'), function (old) {
            if (old.src) {
                var path = old.src.split('?')[0];
                var have = Array.prototype.some.call(document.scripts, function (s) { return s !== old && s.src && s.src.split('?')[0] === path; });
                if (have) { old.remove(); return; }
            }
            var s = document.createElement('script');
            Array.prototype.forEach.call(old.attributes, function (a) { s.setAttribute(a.name, a.value); });
            if (!old.src) s.textContent = old.textContent;
            old.replaceWith(s);
        });
    }

    function setHeading(text) {
        var h = document.querySelector('#bh-main > header h1');
        if (h && text) h.textContent = text;
    }

    function go(href, opts) {
        opts = opts || {};
        var tab = tabOf(href);
        var B = window.Basehim;
        if (!tab || !B || !B.ajax || typeof B.ajax.request !== 'function') { location.href = href; return; }
        var main = document.getElementById('bh-content') || document.querySelector('main');
        var page = main && main.querySelector('.bh-set');
        if (!main || !page) { location.href = href; return; }

        var from = current();
        var dir = opts.dir || (tab === 'home' ? 'back' : (from === 'home' ? 'fwd' : 'none'));
        var ticket = {};
        busy = ticket;

        // Show the choice at once: the tab, and a quiet "loading" if it takes a moment.
        Array.prototype.forEach.call(page.querySelectorAll('.bh-tab'), function (a) {
            var on = tabOf(a.href) === tab;
            a.classList.toggle('is-active', on);
            if (on) a.setAttribute('aria-current', 'page'); else a.removeAttribute('aria-current');
        });
        var slow = setTimeout(function () { if (busy === ticket) page.classList.add('is-loading'); }, 150);

        B.ajax.request('settings.section', { tab: tab }, { method: 'GET', timeout: 20000 })
            .then(function (data) {
                if (busy !== ticket) return;            // another section was chosen meanwhile
                clearTimeout(slow);
                if (!data || typeof data.html !== 'string') throw new Error('empty');
                if (opts.push !== false) history.pushState({ bhSettings: tab }, '', href);
                main.innerHTML = data.html;
                runScripts(main);
                if (typeof window.bhSettingsBind === 'function') window.bhSettingsBind();
                if (data.title) document.title = data.title;
                setHeading(data.heading);

                var next = main.querySelector('.bh-set');
                if (next) {
                    var anim = REDUCED.matches ? '' : (PHONE.matches && dir !== 'none' ? 'is-entering-' + dir : 'is-entering');
                    if (anim) {
                        next.classList.add(anim);
                        next.addEventListener('animationend', function () { next.classList.remove(anim); }, { once: true });
                    }
                    var active = next.querySelector('.bh-tab.is-active');
                    var bar = active && active.parentElement;
                    if (bar && bar.scrollWidth > bar.clientWidth) bar.scrollLeft = active.offsetLeft - (bar.clientWidth - active.offsetWidth) / 2;
                    // Keyboard and screen-reader users land on the new section's title.
                    var title = next.querySelector('.bh-set__title');
                    if (title && !opts.keepFocus) { title.setAttribute('tabindex', '-1'); title.focus({ preventScroll: true }); }
                }
                if (opts.scroll !== undefined) window.scrollTo(0, opts.scroll);
                else window.scrollTo(0, 0);
            })
            .catch(function () {
                if (busy === ticket) location.href = href;   // the ordinary page load, as without script
            });
    }

    document.addEventListener('click', function (e) {
        if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
        var a = e.target.closest && e.target.closest('a[href]');
        if (!a || a.target || a.hasAttribute('download') || !a.closest('.bh-set')) return;
        if (!a.matches('.bh-tab, .bh-set-home__link, .bh-set__back')) return;
        var tab = tabOf(a.href);
        if (!tab) return;
        e.preventDefault();
        if (tab === current() && tab !== 'home') return;
        go(a.href);
    });

    // Back and Forward: show the section of the address, sliding the right way.
    window.addEventListener('popstate', function (e) {
        var tab = current();
        if (!tab || !document.querySelector('main .bh-set')) return;
        go(location.href, { push: false, dir: tab === 'home' ? 'back' : 'fwd', keepFocus: true });
    });
    try { history.replaceState({ bhSettings: current() }, '', location.href); } catch (err) { /* nothing to do */ }
})();
