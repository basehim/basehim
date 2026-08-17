/* ─────────────────────────────────────────────────────────────────────────────
   The Customizer.

   Controls on the left, the site in an iframe on the right. A change is applied
   to the preview immediately and is not written anywhere until Save.

   Two ways a change reaches the preview:

     css     the value is a CSS custom property, so the frame's variables are
             rewritten and the change lands instantly — no request, no reload.
             This is what makes dragging a colour picker feel live.
     reload  the value changes the markup, so the frame is reloaded. Debounced,
             because reloading on every keystroke of a site title is unusable.

   Nothing is written to the database until Save, so abandoning the screen
   leaves the site exactly as it was.
   ───────────────────────────────────────────────────────────────────────────── */
(function () {
    'use strict';

    var CSRF = (window.BH_CZ && window.BH_CZ.csrf) || '';
    var BASE = (window.BH_CZ && window.BH_CZ.base) || '';

    var frame   = document.getElementById('bh-cz-frame');
    var saveBtn = document.getElementById('bh-cz-save');
    var status  = document.getElementById('bh-cz-status');
    if (!frame || !saveBtn) return;

    /** Values changed since the last save, keyed by "section.option". */
    var pending = {};
    var frameReady = false;
    var reloadTimer = null;

    // ── talking to the preview ───────────────────────────────────────────────

    function post(msg) {
        if (!frame.contentWindow) return;
        msg.channel = 'bh-customizer';
        try {
            // The frame is same-origin, so its own origin is the right target.
            frame.contentWindow.postMessage(msg, window.location.origin);
        } catch (e) { /* frame not ready yet */ }
    }

    /** Rebuild every CSS-backed variable and send them as one block. */
    function pushVars() {
        var decls = [];
        document.querySelectorAll('.bh-cz__field[data-preview="css"]').forEach(function (f) {
            var v = readField(f);
            if (v === '' || v === null) return;
            var name = f.getAttribute('data-var') ||
                       ('--bh-' + f.getAttribute('data-path').split('.').pop().replace(/_/g, '-'));
            decls.push(name + ': ' + v + (f.getAttribute('data-unit') || ''));
        });
        post({ type: 'vars', css: decls.length ? ':root{' + decls.join(';') + '}' : '' });
    }

    function pushCss() {
        var f = document.querySelector('.bh-cz__field[data-preview="css"][data-type="textarea"]')
             || document.querySelector('.bh-cz__field[data-type="textarea"]');
        if (!f) return;
        post({ type: 'css', css: readField(f) });
    }

    /* A structural change needs the page rebuilt. Debounced hard: someone
       typing a site title would otherwise trigger a reload per character, and
       every one of those throws away the partly-typed result of the last. */
    function scheduleReload() {
        clearTimeout(reloadTimer);
        setStatus('Updating preview…');
        reloadTimer = setTimeout(function () {
            saveDraft().then(function () {
                frame.contentWindow.location.reload();
            });
        }, 900);
    }

    /*
     * A reload-type change cannot be shown without the server knowing about it,
     * because the markup is produced by PHP. Rather than write to the settings
     * table, the pending values are handed to the preview request itself — the
     * frame reloads with them applied, and nothing is stored.
     */
    function saveDraft() {
        return fetch(BASE + '/admin/customize/draft', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest'
            },
            credentials: 'same-origin',
            body: new URLSearchParams({ _csrf: CSRF, values: JSON.stringify(pending) }).toString()
        }).catch(function () { /* the reload will simply show saved values */ });
    }

    // ── reading a field ──────────────────────────────────────────────────────

    function readField(field) {
        var input = field.querySelector('[data-input]');
        if (!input) return '';
        if (field.getAttribute('data-type') === 'toggle') return input.checked;
        return input.value;
    }

    function markChanged(field) {
        pending[field.getAttribute('data-path')] = readField(field);
        saveBtn.disabled = false;
        saveBtn.textContent = 'Save';
        setStatus('');
    }

    function setStatus(text, tone) {
        status.textContent = text || '';
        status.className = 'bh-cz__status' + (tone ? ' is-' + tone : '');
        status.hidden = !text;
    }

    // ── wiring the fields ────────────────────────────────────────────────────

    document.querySelectorAll('.bh-cz__field').forEach(function (field) {
        var type    = field.getAttribute('data-type');
        var preview = field.getAttribute('data-preview');
        var input   = field.querySelector('[data-input]');
        if (!input) return;

        function changed() {
            markChanged(field);
            if (preview === 'css') {
                type === 'textarea' ? pushCss() : pushVars();
            } else {
                scheduleReload();
            }
        }

        // A colour has two controls that must agree: the swatch and the hex box.
        if (type === 'color') {
            var hex = field.querySelector('[data-hex]');
            input.addEventListener('input', function () {
                if (hex) hex.value = input.value;
                changed();
            });
            if (hex) {
                hex.addEventListener('input', function () {
                    // Only push a value that is actually a colour — a half-typed
                    // "#e1" would blank the property and flash the preview.
                    if (/^#([0-9a-f]{3}|[0-9a-f]{6}|[0-9a-f]{8})$/i.test(hex.value.trim())) {
                        input.value = hex.value.trim();
                        changed();
                    }
                });
                hex.addEventListener('blur', function () {
                    if (!/^#([0-9a-f]{3}|[0-9a-f]{6}|[0-9a-f]{8})$/i.test(hex.value.trim())) {
                        hex.value = input.value;   // put back the last good value
                    }
                });
            }
            return;
        }

        if (type === 'range') {
            var out = field.querySelector('[data-output]');
            var unit = field.getAttribute('data-unit') || '';
            input.addEventListener('input', function () {
                if (out) out.textContent = input.value + unit;
                changed();
            });
            return;
        }

        if (type === 'image') {
            /* Core's shared media field owns the picking, the preview and the
               clearing. This screen only needs to know the value changed —
               which is what the bh:media event is for. */
            field.addEventListener('bh:media', changed);
            return;
        }

        input.addEventListener(type === 'select' || type === 'toggle' ? 'change' : 'input', changed);
    });

    // ── sections open and close ──────────────────────────────────────────────

    document.querySelectorAll('.bh-cz__sectionhead').forEach(function (head, i) {
        var body = head.nextElementSibling;
        function toggle(open) {
            head.setAttribute('aria-expanded', open ? 'true' : 'false');
            body.hidden = !open;
        }
        head.addEventListener('click', function () {
            toggle(head.getAttribute('aria-expanded') !== 'true');
        });
        // The first section starts open, so the screen is not a wall of
        // closed headings with nothing to act on.
        if (i === 0) toggle(true);
    });

    // ── preview width ────────────────────────────────────────────────────────

    document.querySelectorAll('[data-device]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.querySelectorAll('[data-device]').forEach(function (b) { b.classList.remove('is-on'); });
            btn.classList.add('is-on');
            document.querySelector('.bh-cz__frame').setAttribute('data-device', btn.getAttribute('data-device'));
        });
    });

    document.getElementById('bh-cz-refresh').addEventListener('click', function () {
        frame.contentWindow.location.reload();
    });

    // ── saving ───────────────────────────────────────────────────────────────

    saveBtn.addEventListener('click', function () {
        if (!Object.keys(pending).length) return;
        saveBtn.disabled = true;
        saveBtn.textContent = 'Saving…';
        setStatus('');

        fetch(BASE + '/admin/customize/save', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            },
            credentials: 'same-origin',
            body: new URLSearchParams({ _csrf: CSRF, values: JSON.stringify(pending) }).toString()
        })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (!d.ok) {
                setStatus(d.error || 'Could not save.', 'error');
                saveBtn.disabled = false;
                saveBtn.textContent = 'Save';
                return;
            }
            pending = {};
            saveBtn.textContent = 'Saved';
            // Skipped values are named rather than dropped quietly: a setting
            // that appears to save and then is not there is a miserable thing
            // to debug.
            setStatus(d.message, d.skipped && d.skipped.length ? 'warn' : 'ok');
            setTimeout(function () {
                if (!Object.keys(pending).length) { saveBtn.textContent = 'Saved'; saveBtn.disabled = true; }
            }, 1200);
        })
        .catch(function () {
            setStatus('The request failed. Nothing was saved.', 'error');
            saveBtn.disabled = false;
            saveBtn.textContent = 'Save';
        });
    });

    // Leaving with unsaved work should not be silent.
    window.addEventListener('beforeunload', function (e) {
        if (!Object.keys(pending).length) return;
        e.preventDefault();
        e.returnValue = '';
    });

    // ── the frame announces itself ───────────────────────────────────────────

    window.addEventListener('message', function (e) {
        if (e.origin !== window.location.origin) return;
        var m = e.data;
        if (!m || m.channel !== 'bh-customizer' || m.type !== 'ready') return;
        frameReady = true;
        setStatus('');
        // Send the current state, so a reload does not discard what was being
        // previewed before it.
        pushVars();
        pushCss();
    });
})();
