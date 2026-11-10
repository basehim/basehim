/*
 * New category / tag dialog (1.2.123), on admin/views/taxonomies/index.php.
 *
 *  One:     terms.create. "Add" closes the dialog; "Add & add another" keeps
 *           it open with the name cleared and focused. The address preview
 *           under the name follows what is typed.
 *  Several: names one per line or comma-separated. A preview
 *           (terms.create_many with preview) says how many are new and which
 *           already exist, then "Add N" creates the new ones only.
 *
 * After a save the terms table reloads (bh:dt-reload). Text is set with
 * textContent; the server decides everything (names, slugs, duplicates).
 * Without this script the One form posts to the screen as before.
 */
(function () {
    'use strict';

    var dlg = document.getElementById('bh-term-new');
    if (!dlg || !window.Basehim || !window.Basehim.ajax) return;
    var ajax = window.Basehim.ajax;
    var taxonomy = dlg.getAttribute('data-taxonomy') || '';
    var one = dlg.getAttribute('data-one') || 'item';
    var many = dlg.getAttribute('data-many') || 'items';
    var prefix = dlg.getAttribute('data-prefix') || '';
    var tabs = Array.prototype.slice.call(dlg.querySelectorAll('[data-term-tab]'));
    var formOne = dlg.querySelector('[data-term-panel="one"]');
    var formMany = dlg.querySelector('[data-term-panel="many"]');
    var name = formOne.querySelector('[name="name"]');
    var slug = formOne.querySelector('[name="slug"]');
    var address = formOne.querySelector('[data-term-address]');
    var names = formMany.querySelector('[name="names"]');
    var summary = formMany.querySelector('[data-term-summary]');
    var addMany = formMany.querySelector('[data-term-many-add]');

    function el(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) n.className = cls;
        if (text != null) n.textContent = text;
        return n;
    }
    function reloadTable() {
        var t = document.querySelector('.bh-dt');
        if (!t) return;
        try { t.dispatchEvent(new CustomEvent('bh:dt-reload', { detail: { clearSelection: false } })); } catch (e) {}
    }
    function toast(msg, type) { if (window.bhToast) window.bhToast(msg, type); }
    function plural(n, a, b) { return n + ' ' + (n === 1 ? a : b); }

    // ---- tabs -------------------------------------------------------------
    function show(which, focus) {
        tabs.forEach(function (t) {
            var on = t.getAttribute('data-term-tab') === which;
            t.setAttribute('aria-selected', on ? 'true' : 'false');
            t.setAttribute('aria-pressed', on ? 'true' : 'false');
            t.tabIndex = on ? 0 : -1;
        });
        formOne.hidden = which !== 'one';
        formMany.hidden = which !== 'many';
        if (focus) (which === 'one' ? name : names).focus();
    }
    tabs.forEach(function (t, i) {
        t.addEventListener('click', function () { show(t.getAttribute('data-term-tab'), true); });
        t.addEventListener('keydown', function (e) {
            if (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft') return;
            e.preventDefault();
            var next = tabs[(i + (e.key === 'ArrowRight' ? 1 : tabs.length - 1)) % tabs.length];
            show(next.getAttribute('data-term-tab'), false);
            next.focus();
        });
    });

    // ---- errors -----------------------------------------------------------
    function clearErrors(form) {
        form.querySelectorAll('[data-term-error]').forEach(function (p) { p.hidden = true; p.textContent = ''; });
        form.querySelectorAll('.bh-field.has-error').forEach(function (f) { f.classList.remove('has-error'); });
        form.querySelectorAll('[aria-invalid]').forEach(function (i) { i.removeAttribute('aria-invalid'); });
    }
    /** Show field errors; true when at least one had a place to go. */
    function showErrors(form, fields) {
        var first = null;
        Object.keys(fields || {}).forEach(function (k) {
            var p = form.querySelector('[data-term-error="' + k + '"]');
            if (!p) return;
            p.textContent = [].concat(fields[k]).join(' ');
            p.hidden = false;
            var f = p.closest('.bh-field');
            if (f) f.classList.add('has-error');
            var input = form.querySelector('[name="' + k + '"]');
            if (input) { input.setAttribute('aria-invalid', 'true'); if (!first) first = input; }
            var more = p.closest('details');
            if (more) more.open = true;
        });
        if (first) first.focus();
        return !!first;
    }

    // ---- One --------------------------------------------------------------
    function slugify(s) {
        s = String(s || '').toLowerCase().trim();
        try { s = s.normalize('NFKD').replace(/[̀-ͯ]/g, ''); } catch (e) {}
        try { return s.replace(/[^\p{L}\p{N}]+/gu, '-').replace(/^-+|-+$/g, ''); }
        catch (e) { return s.replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, ''); }
    }
    function paintAddress() {
        if (!address || !prefix) return;
        var s = slug.value.trim() ? slugify(slug.value) : slugify(name.value);
        address.textContent = 'Its address: ' + prefix + (s || '…');
        slug.placeholder = slugify(name.value) || 'Made from the name';
    }
    name.addEventListener('input', function () { clearErrors(formOne); paintAddress(); });
    slug.addEventListener('input', paintAddress);

    var busy = false;
    formOne.addEventListener('submit', function (e) {
        e.preventDefault();
        if (busy) return;
        var again = !!(e.submitter && e.submitter.hasAttribute('data-term-again'));
        var btn = (e.submitter && e.submitter.form === formOne) ? e.submitter : formOne.querySelector('[data-term-add]');
        clearErrors(formOne);
        if (!name.value.trim()) { showErrors(formOne, { name: ['Give it a name.'] }); return; }
        var parent = formOne.querySelector('[name="parent_id"]');
        var desc = formOne.querySelector('[name="description"]');
        busy = true;
        var loader = window.bhLoader ? window.bhLoader(btn, 'Adding…') : null;
        ajax.request('terms.create', {
            taxonomy: taxonomy,
            name: name.value.trim(),
            slug: slug.value.trim(),
            parent_id: parent && parent.value ? parseInt(parent.value, 10) : 0,
            description: desc ? desc.value : ''
        }, { idempotencyKey: true }).then(function (d) {
            if (loader) loader.remove();
            toast(one.charAt(0).toUpperCase() + one.slice(1) + ' “' + d.name + '” added.', 'success');
            reloadTable();
            name.value = ''; slug.value = '';
            if (desc) desc.value = '';
            paintAddress();
            if (again) name.focus(); else dlg.close();
        }).catch(function (err) {
            if (loader) loader.remove();
            if (!(err && showErrors(formOne, err.fields))) toast((err && err.message) || 'It could not be added. Try again.', 'error');
        }).then(function () { busy = false; });
    });

    // ---- Several ----------------------------------------------------------
    var timer = 0, seq = 0, preview = null;
    function paintSummary(d) {
        summary.textContent = '';
        var counts = el('div', 'bh-termnew__counts');
        counts.appendChild(el('span', 'bh-termnew__count', plural(d.new.length, 'new ' + one, 'new ' + many)));
        if (d.existing.length) counts.appendChild(el('span', 'bh-termnew__count bh-termnew__count--skip', d.existing.length + ' already exist' + (d.existing.length === 1 ? 's' : '') + ' (skipped)'));
        if (d.too_long.length) counts.appendChild(el('span', 'bh-termnew__count bh-termnew__count--bad', d.too_long.length + ' too long (over 200 characters)'));
        summary.appendChild(counts);
        if (d.existing.length) {
            var chips = el('div', 'bh-termnew__chips');
            d.existing.slice(0, 40).forEach(function (n) { chips.appendChild(el('span', 'bh-termnew__chip', n)); });
            if (d.existing.length > 40) chips.appendChild(el('span', 'bh-termnew__chip', '+' + (d.existing.length - 40) + ' more'));
            summary.appendChild(chips);
        }
        summary.hidden = false;
        addMany.disabled = d.new.length === 0;
        addMany.textContent = d.new.length ? 'Add ' + plural(d.new.length, one, many) : 'Nothing new to add';
    }
    function resetMany() {
        preview = null;
        summary.hidden = true;
        summary.textContent = '';
        addMany.disabled = true;
        addMany.textContent = 'Add ' + many;
    }
    function manyData(isPreview) {
        var parent = formMany.querySelector('[name="parent_id"]');
        return { taxonomy: taxonomy, names: names.value, parent_id: parent && parent.value ? parseInt(parent.value, 10) : 0, preview: isPreview };
    }
    function runPreview() {
        clearErrors(formMany);
        if (!names.value.trim()) { resetMany(); return; }
        var mine = ++seq;
        addMany.disabled = true;
        ajax.request('terms.create_many', manyData(true), { idempotencyKey: true }).then(function (d) {
            if (mine !== seq) return;
            preview = d;
            paintSummary(d);
        }).catch(function (err) {
            if (mine !== seq) return;
            resetMany();
            if (!(err && showErrors(formMany, err.fields))) toast((err && err.message) || 'The list could not be checked.', 'error');
        });
    }
    names.addEventListener('input', function () { clearTimeout(timer); addMany.disabled = true; timer = setTimeout(runPreview, 350); });
    var manyParent = formMany.querySelector('[name="parent_id"]');
    if (manyParent) manyParent.addEventListener('change', function () { if (names.value.trim()) runPreview(); });

    formMany.addEventListener('submit', function (e) {
        e.preventDefault();
        if (busy || !preview || !preview.new.length) return;
        busy = true;
        var loader = window.bhLoader ? window.bhLoader(addMany, 'Adding…') : null;
        ajax.request('terms.create_many', manyData(false), { idempotencyKey: true }).then(function (d) {
            if (loader) loader.remove();
            var msg = plural(d.created.length, one, many) + ' added.';
            if (d.existing.length) msg += ' ' + d.existing.length + ' already existed.';
            toast(msg, 'success');
            reloadTable();
            names.value = '';
            resetMany();
            dlg.close();
        }).catch(function (err) {
            if (loader) loader.remove();
            if (!(err && showErrors(formMany, err.fields))) toast((err && err.message) || 'They could not be added. Try again.', 'error');
        }).then(function () { busy = false; });
    });

    // Opening starts on One with a clean form.
    dlg.addEventListener('close', function () {
        clearErrors(formOne); clearErrors(formMany);
        show('one', false);
    });
    paintAddress();

    // ?edit={id} (the front-end admin bar's "Edit category"): the screen
    // searched for the term, so it is on this page; open its Quick Edit.
    var m = /[?&]edit=(\d+)/.exec(window.location.search);
    if (m) {
        var openEdit = function () {
            var t = document.querySelector('.bh-dt');
            if (t) try { t.dispatchEvent(new CustomEvent('bh:dt-quickedit', { detail: { id: parseInt(m[1], 10) } })); } catch (e) {}
        };
        if (document.readyState === 'complete') openEdit(); else window.addEventListener('load', openEdit);
    }
})();
