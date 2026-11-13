/*
 * Appearance in the account menu (1.3.0): light, dark or the device's
 * setting, and an accent colour. A choice changes the page at once (the
 * colours are CSS variables keyed on <html data-theme / data-accent>) and is
 * saved on the account with profile.appearance, so every browser follows it.
 * If saving fails, the page goes back to what was saved and says so.
 */
(function () {
    'use strict';
    var root = document.querySelector('[data-bh-appearance]');
    if (!root) return;
    var html = document.documentElement;
    var dark = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;

    function current() {
        return { mode: html.getAttribute('data-appearance') || 'light', accent: html.getAttribute('data-accent') || 'blue' };
    }

    // Draw a choice: the attributes on <html>, and the menu's own state.
    function apply(a) {
        html.setAttribute('data-appearance', a.mode);
        html.setAttribute('data-theme', a.mode === 'system' ? (dark && dark.matches ? 'dark' : 'light') : a.mode);
        html.setAttribute('data-accent', a.accent);
        root.querySelectorAll('[data-bh-mode]').forEach(function (b) {
            b.setAttribute('aria-checked', b.getAttribute('data-bh-mode') === a.mode ? 'true' : 'false');
        });
        var name = '';
        root.querySelectorAll('[data-bh-accent]').forEach(function (b) {
            var on = b.getAttribute('data-bh-accent') === a.accent;
            b.setAttribute('aria-checked', on ? 'true' : 'false');
            if (on) name = b.textContent.trim();
        });
        var sw = root.querySelector('[data-bh-accent-swatch]');
        if (sw) sw.setAttribute('data-accent', a.accent);
        var label = root.querySelector('[data-bh-accent-name]');
        if (label && name) label.textContent = name;
    }

    var saved = current();
    function choose(change) {
        var next = Object.assign(current(), change);
        apply(next);
        if (!window.Basehim || !Basehim.ajax) return;
        Basehim.ajax.request('profile.appearance', change).then(function (res) {
            saved = (res && res.appearance) || next;
        }).catch(function () {
            apply(saved);
            if (window.bhToast) window.bhToast('Your appearance wasn’t saved. Check your connection and try again.', 'error');
        });
    }

    root.addEventListener('click', function (e) {
        var mode = e.target.closest('[data-bh-mode]');
        if (mode) { choose({ mode: mode.getAttribute('data-bh-mode') }); return; }
        var accent = e.target.closest('[data-bh-accent]');
        if (accent) {
            choose({ accent: accent.getAttribute('data-bh-accent') });
            // Close after a short pause, so the tick on the new choice is seen.
            setTimeout(function () { setOpen(false, true); }, 180);
            return;
        }
        if (e.target.closest('[data-bh-accent-toggle]')) setOpen(!isOpen());
    });

    // The accent list slides open and shut (CSS animates it from
    // aria-expanded); while shut it is inert, so Tab skips its buttons.
    var toggle = root.querySelector('[data-bh-accent-toggle]');
    var list = toggle && document.getElementById(toggle.getAttribute('aria-controls'));
    function isOpen() { return !!toggle && toggle.getAttribute('aria-expanded') === 'true'; }
    function setOpen(open, focusToggle) {
        if (!toggle || !list) return;
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        list.inert = !open;
        if (!open && focusToggle && list.contains(document.activeElement)) toggle.focus();
    }

    // Arrow keys move between the options of a group, as radio groups do.
    root.addEventListener('keydown', function (e) {
        if (['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown'].indexOf(e.key) < 0) return;
        var item = e.target.closest('[role="radio"]');
        var group = item && item.closest('[role="radiogroup"]');
        if (!group) return;
        var items = Array.prototype.slice.call(group.querySelectorAll('[role="radio"]'));
        var i = items.indexOf(item) + (e.key === 'ArrowLeft' || e.key === 'ArrowUp' ? -1 : 1);
        var next = items[(i + items.length) % items.length];
        e.preventDefault();
        next.focus();
        next.click();
    });

    // "System" following the device is the head script's job
    // (partials/appearance-head.php), as is telling apps about a change.
})();
