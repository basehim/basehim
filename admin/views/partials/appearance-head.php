<?php
/**
 * Appearance, first thing in <head> (1.3.0).
 *
 * 1. "System": the server can't know the device, so this sets data-theme from
 *    it before anything paints, and follows it if it changes (a phone
 *    switching to dark at sunset).
 * 2. Basehim.appearance, for apps and core scripts alike, ready before any of
 *    them runs (docs/ADMIN-UI.md, "Light, dark and accent"):
 *      get()              {mode, theme, dark, accent, colors: {...}, palette: [4]}
 *                         with colours as #rrggbb, for canvas and chart libraries;
 *      color(name, alpha) 'rgb(r g b / a)' for a colour name (accent, text, palette-2…);
 *      onChange(fn)       call fn(get()) whenever the mode or accent changes;
 *                         returns a function that stops it.
 *    Every change also fires a "basehim:appearance" event on document.
 */
?>
    <script>(function () {
        var d = document.documentElement, q = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;
        // Scripts run here: components drawn by script hide their server
        // markup until they are drawn, instead of flashing it (1.3.0).
        d.classList.add('bh-js');
        var follow = function () { if (d.getAttribute('data-appearance') === 'system') d.setAttribute('data-theme', q && q.matches ? 'dark' : 'light'); };
        follow();
        if (q && q.addEventListener) q.addEventListener('change', follow);

        var NAMES = { page: 'page', surface: 'surface', raised: 'raised', text: 'text', strong: 'strong', muted: 'muted', line: 'line', lineStrong: 'line-strong', accent: 'accent', accentSoft: 'accent-soft', accentText: 'accent-text', onAccent: 'on-accent', success: 'success', warning: 'warning', danger: 'danger', info: 'info' };
        var channels = function (name) { return getComputedStyle(d).getPropertyValue('--bh-' + name).trim(); };
        var hex = function (name) {
            var c = channels(name).split(/\s+/).map(Number);
            return c.length === 3 ? '#' + c.map(function (n) { return (n < 16 ? '0' : '') + n.toString(16); }).join('') : '';
        };
        var get = function () {
            var theme = d.getAttribute('data-theme') === 'dark' ? 'dark' : 'light', colors = {}, palette = [];
            for (var k in NAMES) colors[k] = hex(NAMES[k]);
            for (var i = 1; i <= 4; i++) palette.push(hex('palette-' + i));
            return { mode: d.getAttribute('data-appearance') || theme, theme: theme, dark: theme === 'dark', accent: d.getAttribute('data-accent') || '', colors: colors, palette: palette };
        };
        var subs = [];
        var B = window.Basehim = window.Basehim || {};
        B.appearance = {
            get: get,
            color: function (name, alpha) {
                var c = channels(NAMES[name] || name);
                return c ? 'rgb(' + c + (alpha == null ? '' : ' / ' + alpha) + ')' : '';
            },
            onChange: function (fn) {
                subs.push(fn);
                return function () { subs = subs.filter(function (f) { return f !== fn; }); };
            }
        };
        // Whatever changed it (the account menu, the device, a script), tell
        // everyone once per change.
        var last = '';
        new MutationObserver(function () {
            var key = d.getAttribute('data-appearance') + d.getAttribute('data-theme') + d.getAttribute('data-accent');
            if (key === last) return;
            last = key;
            var a = get();
            subs.slice().forEach(function (fn) { try { fn(a); } catch (e) { console.error(e); } });
            document.dispatchEvent(new CustomEvent('basehim:appearance', { detail: a }));
        }).observe(d, { attributes: true, attributeFilter: ['data-appearance', 'data-theme', 'data-accent'] });
        last = d.getAttribute('data-appearance') + d.getAttribute('data-theme') + d.getAttribute('data-accent');
    })();</script>
