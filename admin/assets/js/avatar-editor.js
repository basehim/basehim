/* ─────────────────────────────────────────────────────────────────────────────
   Profile photo editor — My Profile and Edit User.

   Pencil → dialog (page blurred behind) → upload or pick from the media
   library → crop in a round frame with Cropper.js → save. The saved photo
   replaces every avatar of that user on the page (top bar, lists, previews):
   they are all marked data-user-avatar="{id}" by bh_user_avatar().

   Cropper.js is loaded from jsDelivr on first use, pinned to 1.6.2 with
   Subresource Integrity. If it cannot load, the photo is used as it is.
   ───────────────────────────────────────────────────────────────────────────── */
(function () {
    'use strict';

    var root = document.querySelector('[data-avatar-editor]');
    var dlg = document.getElementById('bh-av-dialog');
    if (!root || !dlg || typeof dlg.showModal !== 'function') return;

    var POST = root.getAttribute('data-post');
    var CSRF = root.getAttribute('data-csrf');
    var UID  = root.getAttribute('data-user-id');
    var BASE = root.getAttribute('data-base') || '';
    var MAX  = +root.getAttribute('data-max') || 5242880;
    var CROPPER = {
        js:  'https://cdn.jsdelivr.net/npm/cropperjs@1.6.2/dist/cropper.min.js',
        jsI: 'sha384-jrOgQzBlDeUNdmQn3rUt/PZD+pdcRBdWd/HWRqRo+n2OR2QtGyjSaJC0GiCeH+ir',
        css: 'https://cdn.jsdelivr.net/npm/cropperjs@1.6.2/dist/cropper.min.css',
        cssI: 'sha384-6LFfkTKLRlzFtgx8xsWyBdKGpcMMQTkv+dB7rAbugeJAu1Ym2q1Aji1cjHBG12Xh'
    };
    var OUT = 512;   // saved photo: 512 × 512

    var q = function (sel) { return dlg.querySelector(sel); };
    var views = { choose: q('[data-av-view="choose"]'), crop: q('[data-av-view="crop"]') };
    var cropBox = q('.bh-av-crop'), cropImg = q('[data-av-crop-img]');
    var btnSave = q('[data-av-save]'), btnBack = q('[data-av-back]'), btnRemove = q('[data-av-remove]');
    var msg = q('[data-av-msg]'), status = root.querySelector('[data-av-status]');
    var cropper = null, origin = null, objectUrl = null, cropperPromise = null;

    function say(text) { msg.textContent = text || ''; }
    function note(text, bad) { if (!status) return; status.textContent = text || ''; status.classList.toggle('is-error', !!bad); }

    // ── Cropper.js, on demand ────────────────────────────────────────────────
    function loadCropper() {
        if (window.Cropper) return Promise.resolve(window.Cropper);
        if (cropperPromise) return cropperPromise;
        cropperPromise = new Promise(function (resolve, reject) {
            var link = document.createElement('link');
            link.rel = 'stylesheet'; link.href = CROPPER.css; link.integrity = CROPPER.cssI; link.crossOrigin = 'anonymous';
            document.head.appendChild(link);
            var s = document.createElement('script');
            s.src = CROPPER.js; s.integrity = CROPPER.jsI; s.crossOrigin = 'anonymous';
            var timer = setTimeout(function () { reject(new Error('timeout')); }, 10000);
            s.onload = function () { clearTimeout(timer); window.Cropper ? resolve(window.Cropper) : reject(new Error('missing')); };
            s.onerror = function () { clearTimeout(timer); reject(new Error('blocked')); };
            document.head.appendChild(s);
        }).catch(function (e) { cropperPromise = null; throw e; });
        return cropperPromise;
    }

    // ── dialog ───────────────────────────────────────────────────────────────
    function show(view) {
        views.choose.hidden = view !== 'choose';
        views.crop.hidden = view !== 'crop';
        btnSave.hidden = btnBack.hidden = view !== 'crop';
        q('[data-av-title]').textContent = view === 'crop' ? 'Crop your photo' : 'Profile photo';
        say('');
    }
    function open() {
        show('choose');
        btnRemove.hidden = root.getAttribute('data-has') !== '1';
        dlg.showModal();
        loadCropper().catch(function () {});   // warm it up while they choose
    }
    function close() { teardown(); dlg.close(); }
    function teardown() {
        if (cropper) { cropper.destroy(); cropper = null; }
        if (objectUrl) { URL.revokeObjectURL(objectUrl); objectUrl = null; }
        cropBox.classList.remove('no-cropper');
        cropImg.removeAttribute('src');
        origin = null;
    }

    root.querySelector('[data-av-open]').addEventListener('click', open);
    dlg.querySelectorAll('[data-av-close]').forEach(function (b) { b.addEventListener('click', close); });
    dlg.addEventListener('close', teardown);
    // A click on the blurred backdrop (outside the box) closes it.
    dlg.addEventListener('click', function (e) { if (e.target === dlg) close(); });
    btnBack.addEventListener('click', function () { teardown(); show('choose'); });

    // ── tabs ─────────────────────────────────────────────────────────────────
    dlg.querySelectorAll('[data-av-tab]').forEach(function (t) {
        t.addEventListener('click', function () {
            var which = t.getAttribute('data-av-tab');
            dlg.querySelectorAll('[data-av-tab]').forEach(function (o) {
                var on = o === t; o.classList.toggle('is-on', on); o.setAttribute('aria-selected', on ? 'true' : 'false');
            });
            dlg.querySelectorAll('[data-av-pane]').forEach(function (p) { p.hidden = p.getAttribute('data-av-pane') !== which; });
            if (which === 'library' && !lib.loaded) loadLibrary(true);
        });
    });

    // ── upload ───────────────────────────────────────────────────────────────
    var drop = q('[data-av-drop]'), fileInput = q('[data-av-file]');
    function takeFile(f) {
        if (!f) return;
        if (!/^image\/(jpeg|png|gif|webp)$/.test(f.type)) { say('Choose a JPG, PNG, GIF or WebP image.'); return; }
        if (f.size > 20 * 1024 * 1024) { say('That image is too large to crop here (over 20 MB).'); return; }
        objectUrl = URL.createObjectURL(f);
        startCrop(objectUrl, { file: f });
    }
    fileInput.addEventListener('change', function () { takeFile(fileInput.files && fileInput.files[0]); fileInput.value = ''; });
    ['dragenter', 'dragover'].forEach(function (ev) { drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('is-over'); }); });
    ['dragleave', 'drop'].forEach(function (ev) { drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.remove('is-over'); }); });
    drop.addEventListener('drop', function (e) { takeFile(e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0]); });

    // ── media library ────────────────────────────────────────────────────────
    var grid = q('[data-av-grid]'), more = q('[data-av-more]'), search = q('[data-av-search]');
    var lib = { page: 1, last: 1, q: '', loaded: false, busy: false };
    function sizesOf(item) {
        var s = item.sizes;
        if (typeof s === 'string') { try { s = JSON.parse(s); } catch (e) { s = null; } }
        return s || {};
    }
    function loadLibrary(reset) {
        if (!grid || lib.busy) return;
        if (reset) { lib.page = 1; grid.innerHTML = ''; }
        lib.busy = true;
        fetch(BASE + '/admin/media/json?type=image&per_page=40&sort=newest&page=' + lib.page + '&q=' + encodeURIComponent(lib.q),
              { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                lib.loaded = true;
                var items = (d.data || d.items || []).filter(function (m) { return /^image\/(jpeg|png|gif|webp)$/.test(m.mime_type || ''); });
                var meta = d.meta || {};
                lib.last = +(meta.last_page || meta.lastPage || 1);
                items.forEach(function (m) {
                    var s = sizesOf(m), thumb = (s.thumbnail && s.thumbnail.url) || (s.medium && s.medium.url) || m.url;
                    var b = document.createElement('button');
                    b.type = 'button'; b.title = m.title || m.original_name || '';
                    b.innerHTML = '<img loading="lazy" alt="">';
                    b.firstChild.src = thumb;
                    b.addEventListener('click', function () {
                        var big = (s.large && s.large.url) || m.url;
                        startCrop(big, { mediaId: m.id });
                    });
                    grid.appendChild(b);
                });
                if (!grid.children.length) grid.innerHTML = '<p>No images' + (lib.q ? ' match “' + lib.q.replace(/[<>&]/g, '') + '”' : ' in the media library yet') + '.</p>';
                more.hidden = lib.page >= lib.last;
            })
            .catch(function () { say('The media library could not be loaded.'); })
            .finally(function () { lib.busy = false; });
    }
    if (more) more.addEventListener('click', function () { lib.page++; loadLibrary(false); });
    if (search) {
        var st = null;
        search.addEventListener('input', function () {
            clearTimeout(st);
            st = setTimeout(function () { lib.q = search.value.trim(); loadLibrary(true); }, 300);
        });
    }

    // ── crop ─────────────────────────────────────────────────────────────────
    function startCrop(src, from) {
        origin = from;
        show('crop');
        q('[data-av-tools]').hidden = false;
        q('[data-av-crop-note]').textContent = 'Drag to position. Scroll, or use + and −, to zoom.';
        cropImg.onload = function () {
            loadCropper().then(function (Cropper) {
                if (cropper) cropper.destroy();
                cropper = new Cropper(cropImg, {
                    aspectRatio: 1, viewMode: 1, dragMode: 'move', autoCropArea: 0.9,
                    background: false, guides: false, center: false, highlight: false,
                    cropBoxMovable: false, cropBoxResizable: false, toggleDragModeOnDblclick: false
                });
            }).catch(function () {
                // No cropping tool: show the photo as it will look, and save it as it is.
                cropBox.classList.add('no-cropper');
                q('[data-av-tools]').hidden = true;
                q('[data-av-crop-note]').textContent = 'The cropping tool could not load, so the photo will be used as it is.';
            });
        };
        cropImg.src = src;
    }
    q('[data-av-tools]').addEventListener('click', function (e) {
        var b = e.target.closest('[data-av-act]'); if (!b || !cropper) return;
        var act = b.getAttribute('data-av-act');
        if (act === 'zoom-in') cropper.zoom(0.1);
        else if (act === 'zoom-out') cropper.zoom(-0.1);
        else if (act === 'rotate-left') cropper.rotate(-90);
        else if (act === 'rotate-right') cropper.rotate(90);
        else if (act === 'reset') cropper.reset();
    });

    // ── save ─────────────────────────────────────────────────────────────────
    function post(url, body) {
        body.append('_csrf', CSRF);
        return fetch(url, { method: 'POST', body: body, credentials: 'same-origin',
                            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'The server answered with an error (' + r.status + ').' }; }); });
    }
    function asIs() {
        var fd = new FormData();
        if (origin && origin.file) fd.append('avatar', origin.file);
        else if (origin && origin.mediaId) fd.append('media_id', origin.mediaId);
        return fd;
    }
    function croppedForm() {
        return new Promise(function (resolve) {
            if (!cropper) { resolve(asIs()); return; }
            var canvas;
            try {
                canvas = cropper.getCroppedCanvas({ width: OUT, height: OUT, fillColor: '#ffffff', imageSmoothingEnabled: true, imageSmoothingQuality: 'high' });
            } catch (e) { canvas = null; }
            if (!canvas) { resolve(asIs()); return; }
            try {
                canvas.toBlob(function (blob) {
                    if (!blob) { resolve(asIs()); return; }
                    if (blob.size > MAX) { resolve(asIs()); return; }
                    var fd = new FormData(); fd.append('avatar', blob, 'avatar.jpg'); resolve(fd);
                }, 'image/jpeg', 0.9);
            } catch (e) {
                resolve(asIs());   // e.g. an image from another origin taints the canvas
            }
        });
    }
    btnSave.addEventListener('click', function () {
        btnSave.disabled = true; btnSave.textContent = 'Saving…'; say('');
        croppedForm().then(function (fd) { return post(POST, fd); }).then(function (d) {
            btnSave.disabled = false; btnSave.textContent = 'Save photo';
            if (!d.ok) { say(d.error || 'The photo could not be saved.'); return; }
            apply(d.avatar);
            close();
            note('Photo saved.');
        }).catch(function () { btnSave.disabled = false; btnSave.textContent = 'Save photo'; say('The photo could not be saved. Check your connection.'); });
    });
    btnRemove.addEventListener('click', function () {
        if (!confirm('Remove this profile photo? Initials will be shown instead.')) return;
        btnRemove.disabled = true;
        post(POST + '/delete', new FormData()).then(function (d) {
            btnRemove.disabled = false;
            if (!d.ok) { say(d.error || 'The photo could not be removed.'); return; }
            apply(null);
            close();
            note('Photo removed.');
        });
    });

    // ── every avatar of this user on the page ────────────────────────────────
    function apply(av) {
        root.setAttribute('data-has', av ? '1' : '0');
        document.querySelectorAll('[data-user-avatar="' + UID + '"]').forEach(function (el) {
            var cls = el.getAttribute('data-avatar-class') || '';
            var big = el.offsetWidth > 48;
            var url = av ? (big ? (av.medium_url || av.url) : (av.thumbnail_url || av.url)) : '';
            var next;
            if (url) {
                if (el.tagName === 'IMG') { el.src = url; return; }
                next = document.createElement('img');
                next.alt = '';
                next.className = ('rounded-full object-cover shrink-0 ' + cls).trim();
                next.src = url;
            } else {
                if (el.tagName !== 'IMG') return;
                next = document.createElement('span');
                next.className = ('rounded-full bg-gradient-to-br from-blue-400 to-blue-600 grid place-items-center text-white font-semibold shrink-0 ' + cls).trim();
                next.textContent = el.getAttribute('data-initial') || '?';
            }
            ['data-user-avatar', 'data-avatar-class', 'data-initial'].forEach(function (a) { next.setAttribute(a, el.getAttribute(a) || ''); });
            el.replaceWith(next);
        });
    }
})();
