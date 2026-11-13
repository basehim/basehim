/*
 * Scroll strips (1.3.0): a row that scrolls sideways with its scrollbar
 * hidden, such as the section tabs (.bh-tabs), the Marketplace's categories
 * or any element marked data-bh-strip. See ADMIN-UI.md, "Scroll strips".
 *
 * - With a mouse, an arrow at each end, shown only while there is more that
 *   way (CSS: has-more-left / has-more-right). They are out of the tab
 *   order: keyboard focus scrolls an item into view by itself.
 * - On touch screens, swiping and edge fades (the same two classes).
 * - The active item (.is-active, [aria-current], [aria-selected="true"]) is
 *   brought into view when the page opens.
 * - A vertical wheel scrolls the strip sideways.
 * - Items added later (the Marketplace draws its chips after loading) keep
 *   the arrows at the ends.
 *
 * window.bhScrollStrip(el) sets one up by hand, for a strip added after the
 * page loaded; it is safe to call twice.
 */
(function () {
    'use strict';
    var fine = window.matchMedia ? window.matchMedia('(hover: hover) and (pointer: fine)') : null;
    var calm = window.matchMedia ? window.matchMedia('(prefers-reduced-motion: reduce)') : null;

    function arrow(strip, dir) {
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'bh-strip__arrow bh-strip__arrow--' + dir;
        b.tabIndex = -1;
        b.setAttribute('aria-hidden', 'true');
        b.innerHTML = window.BasehimIcon ? BasehimIcon(dir === 'left' ? 'chevron-left' : 'chevron-right', 'w-4 h-4') : '';
        b.addEventListener('click', function () {
            var by = Math.max(120, Math.round(strip.clientWidth * 0.7)) * (dir === 'left' ? -1 : 1);
            strip.scrollBy({ left: by, behavior: calm && calm.matches ? 'auto' : 'smooth' });
        });
        return b;
    }

    function setup(strip) {
        if (!strip || strip.__bhStrip) return;
        strip.__bhStrip = true;
        if (!strip.hasAttribute('data-bh-strip')) strip.setAttribute('data-bh-strip', '');
        var left = arrow(strip, 'left'), right = arrow(strip, 'right');
        var place = function () {
            if (strip.firstChild !== left) strip.insertBefore(left, strip.firstChild);
            if (strip.lastChild !== right) strip.appendChild(right);
        };
        var sync = function () {
            var max = strip.scrollWidth - strip.clientWidth;
            strip.classList.toggle('has-more-right', max > 1 && strip.scrollLeft < max - 1);
            strip.classList.toggle('has-more-left', strip.scrollLeft > 1);
        };
        place();

        var active = strip.querySelector('.is-active, [aria-current="page"], [aria-current="true"], [aria-selected="true"]');
        if (active) {
            var s = strip.getBoundingClientRect(), a = active.getBoundingClientRect();
            if (a.left < s.left || a.right > s.right) strip.scrollLeft = active.offsetLeft - strip.clientWidth / 2 + active.offsetWidth / 2;
        }
        sync();
        strip.addEventListener('scroll', sync, { passive: true });
        window.addEventListener('resize', sync);
        if (window.ResizeObserver) new ResizeObserver(sync).observe(strip);
        if (window.MutationObserver) new MutationObserver(function () { place(); sync(); }).observe(strip, { childList: true });
        if (fine && fine.addEventListener) fine.addEventListener('change', sync);
        strip.addEventListener('wheel', function (e) {
            if (Math.abs(e.deltaY) <= Math.abs(e.deltaX) || strip.scrollWidth <= strip.clientWidth) return;
            e.preventDefault();
            strip.scrollLeft += e.deltaY;
        }, { passive: false });
    }

    window.bhScrollStrip = setup;
    function all() { document.querySelectorAll('.bh-tabs, [data-bh-strip]').forEach(setup); }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', all); else all();
})();
