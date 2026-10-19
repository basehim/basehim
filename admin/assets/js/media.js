/**
 * Basehim Media Picker
 * Self-contained — styling lives in admin/assets/css/media-picker.css
 */
(function (window, document) {
    'use strict';

    var BASE = (document.body && document.body.dataset && document.body.dataset.base) || '';
    var CSRF = (document.body && document.body.dataset && document.body.dataset.csrf) || '';

    function url(path) {
        if (!path.startsWith('/')) path = '/' + path;
        return BASE + path;
    }

    function escapeHtml(s) {
        if (s == null) return '';
        return String(s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    // The picker shows the generated medium copy of an image (falling back to
    // the original); what it inserts is always the original's URL.
    function previewUrl(m) {
        var s = m.sizes;
        if (typeof s === 'string') { try { s = JSON.parse(s); } catch (e) { s = null; } }
        if (s && typeof s === 'object') {
            if (s.medium && s.medium.url) return s.medium.url;
            if (s.large && s.large.url) return s.large.url;
        }
        return m.url;
    }

    function el(tag, attrs, html) {
        var node = document.createElement(tag);
        if (attrs) {
            for (var k in attrs) {
                if (k === 'class') node.className = attrs[k];
                else if (k === 'dataset') { for (var d in attrs[k]) node.dataset[d] = attrs[k][d]; }
                else node.setAttribute(k, attrs[k]);
            }
        }
        if (html != null) node.innerHTML = html;
        return node;
    }

    // ----- Upload (XHR with progress) ----------------------------------------
    function uploadFile(file, opts) {
        opts = opts || {};
        return new Promise(function (resolve, reject) {
            var fd = new FormData();
            fd.append('file', file);
            fd.append('_csrf', CSRF);
            if (opts.title)    fd.append('title', opts.title);
            if (opts.alt_text) fd.append('alt_text', opts.alt_text);
            // A page can say what its uploads are about (the post editor sends
            // the post title). The server uses it only to name files whose own
            // name is meaningless, like 1000477992.jpg or image.png.
            var hint = opts.name_hint != null ? opts.name_hint
                : (window.BasehimMedia && typeof window.BasehimMedia.nameHint === 'function' ? window.BasehimMedia.nameHint() : '');
            if (hint) fd.append('name_hint', String(hint).slice(0, 200));

            var xhr = new XMLHttpRequest();
            xhr.open('POST', url('/admin/media/upload?ajax=1'));
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            xhr.setRequestHeader('Accept', 'application/json');
            xhr.upload.onprogress = function (e) {
                if (opts.onProgress && e.lengthComputable) {
                    opts.onProgress(Math.round((e.loaded / e.total) * 100));
                }
            };
            xhr.onload = function () {
                try {
                    var resp = JSON.parse(xhr.responseText);
                    if (xhr.status >= 200 && xhr.status < 300 && resp.success !== false) resolve(resp);
                    else reject(new Error(resp.error || ('Upload failed (HTTP ' + xhr.status + ')')));
                } catch (e) {
                    reject(new Error('Invalid server response'));
                }
            };
            xhr.onerror = function () { reject(new Error('Network error')); };
            xhr.send(fd);
        });
    }

    // ----- Progress row ------------------------------------------------------
    function makeProgressRow(file, compact) {
        var row = el('div', { class: 'nm-progress-row' + (compact ? ' nm-progress-row-compact' : '') });
        row.innerHTML =
            ''+BasehimIcon('arrow-up-tray','w-4 h-4 nm-progress-row-icon')+'' +
            '<div class="nm-progress-row-body">' +
                '<div class="nm-progress-row-name">' + escapeHtml(file.name) + '</div>' +
                '<div class="nm-progress-row-bar"><div class="nm-progress-row-bar-fill"></div></div>' +
            '</div>' +
            '<span class="nm-progress-row-pct">0%</span>';
        return row;
    }
    // Inline SVGs can't be restyled by swapping className — replace the node.
    function swapRowIcon(row, name) {
        var el = row.querySelector('.nm-progress-row-icon');
        if (el) el.outerHTML = BasehimIcon(name, 'w-4 h-4 nm-progress-row-icon');
    }
    function progressDone(row) {
        row.classList.add('is-done');
        swapRowIcon(row, 'check');
        row.querySelector('.nm-progress-row-bar-fill').style.width = '100%';
        row.querySelector('.nm-progress-row-pct').textContent = 'Done';
    }
    function progressError(row, msg) {
        row.classList.add('is-error');
        swapRowIcon(row, 'x-circle');
        row.querySelector('.nm-progress-row-pct').textContent = 'Error';
        var m = el('div', { class: 'nm-progress-row-msg' });
        m.textContent = msg;
        row.querySelector('.nm-progress-row-body').appendChild(m);
    }

    // ----- Dropzone ----------------------------------------------------------
    function attachDropzone(zoneEl, opts) {
        opts = opts || {};
        var listEl = opts.progressList || zoneEl;

        function prevent(e) { e.preventDefault(); e.stopPropagation(); }
        ['dragenter','dragover','dragleave','drop'].forEach(function(ev){ zoneEl.addEventListener(ev, prevent, false); });
        ['dragenter','dragover'].forEach(function(ev){ zoneEl.addEventListener(ev, function(){ zoneEl.classList.add('is-dragging'); }, false); });
        ['dragleave','drop'].forEach(function(ev){ zoneEl.addEventListener(ev, function(){ zoneEl.classList.remove('is-dragging'); }, false); });

        zoneEl.addEventListener('drop', function (e) { handleFiles(e.dataTransfer.files); }, false);

        var input = zoneEl.querySelector('input[type=file]');
        if (input) input.addEventListener('change', function (e) { handleFiles(e.target.files); input.value = ''; });

        function handleFiles(files) { Array.prototype.forEach.call(files, function (file) { uploadOne(file); }); }

        function uploadOne(file) {
            var row = makeProgressRow(file, false);
            listEl.appendChild(row);
            var fill = row.querySelector('.nm-progress-row-bar-fill');
            var pct  = row.querySelector('.nm-progress-row-pct');

            uploadFile(file, {
                onProgress: function (n) { fill.style.width = n + '%'; pct.textContent = n + '%'; }
            }).then(function (resp) {
                progressDone(row);
                if (opts.onUploaded) opts.onUploaded(resp.data || resp);
                setTimeout(function () { row.remove(); }, 1200);
                if (opts.reloadAfter !== false) {
                    setTimeout(function () { window.location.reload(); }, 800);
                }
            }).catch(function (err) { progressError(row, err.message); });
        }
    }

    // ----- Modal picker ------------------------------------------------------
    //
    // One picker for every screen (1.2.67): the featured image, the
    // customizer's image fields, the logo and favicon, the block editor and
    // anything an app or theme opens. It reads the library a page at a time
    // through media.list and adds the next page when Load More is pressed, so
    // a library of any size is reachable without loading all of it.
    //
    //   BasehimMedia.openPicker({
    //     types:        ['image', 'svg'] | 'image/*' | ''   (default: everything)
    //     multiple:     false            pick several (Ctrl/⌘-click or click to toggle)
    //     selected:     12 | [12, 40]    ids to start with selected
    //     title:        'Select media'
    //     confirmLabel: 'Select'
    //     onSelect:     function (item) {}        once per chosen item
    //     onSelectMany: function (items) {}       with multiple, all at once (optional)
    //     onCancel:     function () {}
    //   })
    //
    // `item` is the media row media.list returns (id, url, mime_type, sizes,
    // alt_text, …) — the same object the picker has always passed. The server
    // decides what the user may see; `types` only narrows the list.

    var TYPE_KEYS = ['image', 'svg', 'video', 'audio', 'document'];
    var TYPE_LABELS = { all: 'All types', image: 'Images', svg: 'SVG', video: 'Video', audio: 'Audio', document: 'Documents' };
    var PAGE_SIZE = 40;

    /** 'image/*,video/*', ['image'], 'image' … → a list of type keys ([] = all). */
    function normalizeTypes(t) {
        if (t == null || t === '' || t === 'all' || t === '*') return [];
        var list = Array.isArray(t) ? t : String(t).split(',');
        var out = [];
        list.forEach(function (x) {
            x = String(x).trim().toLowerCase();
            var add = [];
            if (x === 'image/*' || x === 'image' || x === 'images') add = ['image', 'svg'];
            else if (x === 'image/svg+xml' || x === 'svg') add = ['svg'];
            else if (x.indexOf('image/') === 0) add = ['image'];
            else if (x === 'video/*' || x === 'video' || x.indexOf('video/') === 0) add = ['video'];
            else if (x === 'audio/*' || x === 'audio' || x.indexOf('audio/') === 0) add = ['audio'];
            else if (x === 'document' || x === 'documents' || x === 'file' || x === 'application/*' || x.charAt(0) === '.') add = ['document'];
            add.forEach(function (k) { if (out.indexOf(k) === -1) out.push(k); });
        });
        return out.length === TYPE_KEYS.length ? [] : out;
    }
    function kindOf(m) {
        var mime = String((m && m.mime_type) || '');
        if (mime === 'image/svg+xml') return 'svg';
        if (mime.indexOf('image/') === 0) return 'image';
        if (mime.indexOf('video/') === 0) return 'video';
        if (mime.indexOf('audio/') === 0) return 'audio';
        return 'document';
    }
    function sizesOf(m) {
        var s = m.sizes;
        if (typeof s === 'string') { try { s = JSON.parse(s); } catch (e) { s = null; } }
        return (s && typeof s === 'object' && !Array.isArray(s)) ? s : {};
    }
    // A grid card shows the small square copy; never the original when a copy exists.
    function thumbUrl(m) {
        var s = sizesOf(m);
        return (s.thumbnail && s.thumbnail.url) || (s.medium && s.medium.url) || m.url;
    }
    function formatBytes(n) {
        n = Number(n) || 0;
        if (n < 1024) return n + ' B';
        if (n < 1048576) return Math.round(n / 1024) + ' KB';
        return (n / 1048576).toFixed(1) + ' MB';
    }

    var modal = null;

    function openPicker(opts) {
        opts = opts || {};
        if (modal && modal.__close) modal.__close(true);

        var allowed = normalizeTypes(opts.types != null ? opts.types : opts.accept);
        var multiple = !!opts.multiple;
        var title = opts.title || (multiple ? 'Select media' : 'Select media');
        var confirmLabel = opts.confirmLabel || 'Select';
        var opener = document.activeElement;
        var uid = 'nm-' + Math.random().toString(36).slice(2, 8);

        var typeChoices = allowed.length ? allowed : TYPE_KEYS;
        var typeSelect = typeChoices.length > 1
            ? '<select class="nm-select nm-type" aria-label="File type"><option value="all">' + escapeHtml(TYPE_LABELS.all) + '</option>' +
                typeChoices.map(function (k) { return '<option value="' + k + '">' + escapeHtml(TYPE_LABELS[k]) + '</option>'; }).join('') + '</select>'
            : '';

        modal = el('div', { class: 'nm-overlay' });
        modal.innerHTML =
            '<div class="nm-dialog" role="dialog" aria-modal="true" aria-labelledby="' + uid + '-t">' +
                '<div class="nm-header">' +
                    '<h3 class="nm-title" id="' + uid + '-t">' + BasehimIcon('photo', 'w-5 h-5') + '<span>' + escapeHtml(title) + '</span></h3>' +
                    '<button type="button" class="nm-close" aria-label="Close">' + BasehimIcon('x-mark', 'w-5 h-5') + '</button>' +
                '</div>' +
                '<div class="nm-toolbar">' +
                    '<div class="nm-searchwrap">' + BasehimIcon('magnifying-glass', 'w-4 h-4') +
                        '<input type="search" placeholder="Search media" class="nm-search" aria-label="Search media" autocomplete="off">' +
                    '</div>' +
                    typeSelect +
                    '<select class="nm-select nm-month" aria-label="Date uploaded"><option value="all">All dates</option></select>' +
                    '<label class="nm-upload-btn">' + BasehimIcon('arrow-up-tray', 'w-4 h-4') + '<span>Upload</span><input type="file" class="nm-upload"' + (multiple ? ' multiple' : '') + acceptAttr(allowed) + '></label>' +
                '</div>' +
                '<div class="nm-progress"></div>' +
                '<div class="nm-body">' +
                    '<div class="nm-grid" role="listbox" aria-label="Media library"' + (multiple ? ' aria-multiselectable="true"' : '') + ' aria-busy="true"></div>' +
                    '<aside class="nm-details" hidden>' +
                        '<div class="nm-details-preview"></div>' +
                        '<div class="nm-details-meta"></div>' +
                        '<label class="nm-field">' +
                            '<span class="nm-field-label">Alt text</span>' +
                            '<textarea class="nm-alt" rows="2" placeholder="Describe the image for someone who cannot see it"></textarea>' +
                            '<span class="nm-field-hint">Leave empty only if the image is purely decorative.</span>' +
                        '</label>' +
                        '<label class="nm-field">' +
                            '<span class="nm-field-label">Caption</span>' +
                            '<textarea class="nm-caption" rows="2" placeholder="Shown beneath the image"></textarea>' +
                        '</label>' +
                        '<div class="nm-savestate" aria-live="polite"></div>' +
                    '</aside>' +
                '</div>' +
                '<div class="nm-footer">' +
                    '<span class="nm-status" aria-live="polite">Loading…</span>' +
                    '<div class="nm-actions">' +
                        '<button type="button" class="nm-btn-cancel">Cancel</button>' +
                        '<button type="button" class="nm-btn-confirm" disabled>' + escapeHtml(confirmLabel) + '</button>' +
                    '</div>' +
                '</div>' +
            '</div>';
        document.body.appendChild(modal);
        var me = modal;

        var grid     = modal.querySelector('.nm-grid');
        var status   = modal.querySelector('.nm-status');
        var btnOk    = modal.querySelector('.nm-btn-confirm');
        var search   = modal.querySelector('.nm-search');
        var typeSel  = modal.querySelector('.nm-type');
        var monthSel = modal.querySelector('.nm-month');
        var fileIn   = modal.querySelector('.nm-upload');
        var progress = modal.querySelector('.nm-progress');
        var details  = modal.querySelector('.nm-details');
        var dPreview = modal.querySelector('.nm-details-preview');
        var dMeta    = modal.querySelector('.nm-details-meta');
        var altIn    = modal.querySelector('.nm-alt');
        var capIn    = modal.querySelector('.nm-caption');
        var saveMsg  = modal.querySelector('.nm-savestate');

        // ---- state ----
        var q = { search: '', type: 'all', month: 'all' };
        var page = 0, hasMore = false, total = 0;
        var loading = false;       // a request is in flight: Load More waits for it
        var seq = 0;               // bumps on every new search/filter; older answers are dropped
        var shown = Object.create(null);   // id -> card, for the current list (no duplicates)
        var known = Object.create(null);   // id -> item, everything seen this session
        var chosen = [];                   // ids, in the order they were picked
        var focusId = null;                // the item in the details pane
        var monthsLoaded = false;

        var moreBox = el('div', { class: 'nm-more' });

        function close(silent) {
            if (!me.parentNode) return;
            document.removeEventListener('keydown', onKey, true);
            me.remove();
            if (modal === me) modal = null;
            if (!silent && opts.onCancel) { try { opts.onCancel(); } catch (e) {} }
            if (opener && opener.focus && document.contains(opener)) { try { opener.focus(); } catch (e) {} }
        }
        me.__close = close;
        modal.querySelector('.nm-close').onclick = function () { close(); };
        modal.querySelector('.nm-btn-cancel').onclick = function () { close(); };
        modal.addEventListener('click', function (e) { if (e.target === me) close(); });
        function onKey(e) {
            if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); close(); return; }
            if (e.key === 'Tab') {
                // Keep focus inside the dialog.
                var f = Array.prototype.filter.call(me.querySelectorAll('button, input, select, textarea, [tabindex="0"]'), function (n) { return !n.disabled && n.offsetParent !== null; });
                if (!f.length) return;
                var first = f[0], last = f[f.length - 1];
                if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
                else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
            }
        }
        document.addEventListener('keydown', onKey, true);

        // ---- details pane (alt text and caption save themselves) ----
        var saveTimer = null;
        function showDetails(m) {
            if (!details) return;
            if (!m) { details.hidden = true; focusId = null; return; }
            focusId = String(m.id);
            details.hidden = false;
            var isImg = kindOf(m) === 'image' || kindOf(m) === 'svg';
            var s = sizesOf(m);
            dPreview.innerHTML = isImg
                ? '<img src="' + escapeHtml((s.medium && s.medium.url) || m.url) + '" alt="">'
                : '<div class="nm-details-file">' + BasehimIcon('document', 'w-8 h-8') + '</div>';
            var facts = [];
            if (m.width && m.height) facts.push(m.width + ' × ' + m.height);
            if (m.file_size) facts.push(formatBytes(m.file_size));
            if (m.mime_type) facts.push(String(m.mime_type).replace('image/', '').toUpperCase());
            dMeta.innerHTML = '<div class="nm-details-name">' + escapeHtml(m.original_name || m.file_name || '') + '</div>' +
                (facts.length ? '<div class="nm-details-facts">' + escapeHtml(facts.join(' · ')) + '</div>' : '');
            altIn.value = m.alt_text || '';
            capIn.value = m.caption || '';
            altIn.closest('.nm-field').hidden = !isImg;
            saveMsg.textContent = '';
            clearTimeout(saveTimer);
        }
        function queueSave() { if (!focusId) return; clearTimeout(saveTimer); saveMsg.textContent = ''; saveTimer = setTimeout(saveMeta, 700); }
        function saveMeta() {
            var item = focusId && known[focusId];
            if (!item) return;
            if ((item.alt_text || '') === altIn.value && (item.caption || '') === capIn.value) return;
            saveMsg.textContent = 'Saving…'; saveMsg.className = 'nm-savestate';
            window.Basehim.ajax.request('media.update', { id: parseInt(item.id, 10), alt_text: altIn.value, caption: capIn.value })
                .then(function (data) {
                    if (data && data.item) { item.alt_text = data.item.alt_text; item.caption = data.item.caption; }
                    saveMsg.textContent = 'Saved'; saveMsg.className = 'nm-savestate is-ok';
                    setTimeout(function () { if (saveMsg.textContent === 'Saved') saveMsg.textContent = ''; }, 2000);
                }).catch(function (err) {
                    saveMsg.textContent = (err && err.message) || 'Could not save.'; saveMsg.className = 'nm-savestate is-error';
                });
        }
        altIn.addEventListener('input', queueSave);
        capIn.addEventListener('input', queueSave);
        altIn.addEventListener('blur', function () { clearTimeout(saveTimer); saveMeta(); });
        capIn.addEventListener('blur', function () { clearTimeout(saveTimer); saveMeta(); });

        // ---- selection ----
        function isChosen(id) { return chosen.indexOf(String(id)) !== -1; }
        function paintCard(id) {
            var c = shown[id]; if (!c) return;
            var on = isChosen(id);
            c.dataset.selected = on ? '1' : '';
            c.setAttribute('aria-selected', on ? 'true' : 'false');
        }
        function syncConfirm() {
            btnOk.disabled = chosen.length === 0;
            btnOk.textContent = multiple && chosen.length > 1 ? confirmLabel + ' (' + chosen.length + ')' : confirmLabel;
        }
        function choose(m, additive) {
            var id = String(m.id);
            known[id] = m;
            if (multiple) {
                if (isChosen(id)) chosen.splice(chosen.indexOf(id), 1); else chosen.push(id);
                paintCard(id);
            } else {
                var prev = chosen.slice();
                chosen = [id];
                prev.forEach(paintCard); paintCard(id);
            }
            syncConfirm();
            showDetails(isChosen(id) ? m : (chosen.length ? known[chosen[chosen.length - 1]] : null));
        }
        function confirm() {
            var items = chosen.map(function (id) { return known[id]; }).filter(Boolean);
            if (!items.length) return;
            close(true);
            if (multiple) {
                if (opts.onSelectMany) opts.onSelectMany(items);
                else if (opts.onSelect) items.forEach(function (m) { opts.onSelect(m); });
            } else if (opts.onSelect) {
                opts.onSelect(items[0]);
            }
        }
        btnOk.onclick = confirm;

        // ---- cards ----
        function makeCard(m) {
            var k = kindOf(m);
            var card = el('div', { class: 'nm-card', dataset: { id: m.id, url: m.url } });
            if (k === 'image' || k === 'svg') {
                card.innerHTML = '<img src="' + escapeHtml(thumbUrl(m)) + '" alt="' + escapeHtml(m.alt_text || '') + '" loading="lazy" decoding="async">';
            } else {
                card.innerHTML = '<div class="nm-card-file">' + BasehimIcon(k === 'video' ? 'film' : (k === 'audio' ? 'musical-note' : 'document'), 'w-4 h-4') +
                    '<div class="nm-card-file-name">' + escapeHtml(m.original_name || m.file_name || '') + '</div></div>';
            }
            card.setAttribute('role', 'option');
            card.setAttribute('tabindex', '0');
            card.title = m.title || m.original_name || m.file_name || '';
            card.addEventListener('click', function (ev) { choose(m, ev.ctrlKey || ev.metaKey || ev.shiftKey); });
            card.addEventListener('keydown', function (ev) {
                if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); choose(m, true); }
                else if (/^Arrow(Left|Right|Up|Down)$/.test(ev.key)) { ev.preventDefault(); moveFocus(card, ev.key); }
            });
            // A double click picks it outright (single choice only).
            if (!multiple) card.addEventListener('dblclick', function () { chosen = [String(m.id)]; known[m.id] = m; confirm(); });
            return card;
        }
        function moveFocus(card, key) {
            var cards = Array.prototype.slice.call(grid.querySelectorAll('.nm-card'));
            var i = cards.indexOf(card); if (i < 0) return;
            var cols = Math.max(1, Math.round(grid.clientWidth / (card.offsetWidth + 12)));
            var j = key === 'ArrowLeft' ? i - 1 : key === 'ArrowRight' ? i + 1 : key === 'ArrowUp' ? i - cols : i + cols;
            if (cards[j]) cards[j].focus();
            else if (j >= cards.length && hasMore && !loading) loadPage(false, true);
        }
        function addItems(items, atTop) {
            var firstNew = null;
            items.forEach(function (m) {
                var id = String(m.id);
                known[id] = m;
                if (shown[id]) return;                    // already on screen: never twice
                var card = makeCard(m);
                shown[id] = card;
                if (atTop) grid.insertBefore(card, grid.firstChild); else grid.insertBefore(card, moreBox);
                paintCard(id);
                if (!firstNew) firstNew = card;
            });
            return firstNew;
        }

        // ---- the Load More area: loading / more / end / error ----
        function renderMore(state, msg) {
            moreBox.innerHTML = '';
            moreBox.className = 'nm-more nm-more--' + state;
            if (state === 'loading') {
                moreBox.innerHTML = '<span class="nm-spinner" aria-hidden="true"></span><span>Loading…</span>';
            } else if (state === 'more') {
                var b = el('button', { type: 'button', class: 'nm-more-btn' }, 'Load more');
                b.addEventListener('click', function () { loadPage(false, true); });
                moreBox.appendChild(b);
            } else if (state === 'end') {
                moreBox.textContent = msg == null ? 'That is everything.' : msg;
            } else if (state === 'error') {
                moreBox.appendChild(el('span', { class: 'nm-more-error' }, escapeHtml(msg || 'Could not load more files.')));
                var r = el('button', { type: 'button', class: 'nm-more-btn' }, 'Retry');
                r.addEventListener('click', function () { loadPage(page === 0, page > 0); });
                moreBox.appendChild(r);
            } else if (state === 'empty') {
                moreBox.innerHTML = '<div class="nm-empty">' + BasehimIcon(msg ? 'magnifying-glass' : 'folder-open', 'w-4 h-4') +
                    '<p>' + (msg ? 'No files match your search or filters.' : 'No files here yet. Upload one to get started.') + '</p></div>';
            }
        }
        function filtered() { return q.search !== '' || q.type !== 'all' || q.month !== 'all'; }
        function updateStatus() {
            var n = Object.keys(shown).length;
            var sel = chosen.length ? ' · ' + chosen.length + ' selected' : '';
            status.textContent = total ? ('Showing ' + n + ' of ' + total + sel) : (sel ? sel.slice(3) : '0 items');
        }

        /**
         * Fetch the next page (or, with reset, page 1 of a new search).
         * One request at a time per list; a reset makes any answer still on its
         * way for the old list stale, and it is dropped when it arrives.
         */
        function loadPage(reset, focusNew) {
            if (reset) {
                seq++;
                page = 0; hasMore = false; total = 0;
                Object.keys(shown).forEach(function (id) { var c = shown[id]; if (c && c.parentNode) c.parentNode.removeChild(c); });
                shown = Object.create(null);
                loading = false;
                grid.scrollTop = 0;
            } else if (loading || (!hasMore && page > 0)) {
                return;   // a request is already on its way, or there is nothing more
            }
            if (!moreBox.parentNode) grid.appendChild(moreBox);
            var my = seq;
            loading = true;
            grid.setAttribute('aria-busy', 'true');
            renderMore('loading');
            var params = { page: page + 1, per_page: PAGE_SIZE, sort: 'newest' };
            if (allowed.length) params.types = allowed.join(',');
            if (q.type !== 'all') params.type = q.type;
            if (q.month !== 'all') params.month = q.month;
            if (q.search) params.q = q.search;
            if (!monthsLoaded && page === 0) params.months = 1;
            window.Basehim.ajax.get('media.list', params).then(function (d) {
                if (my !== seq || !me.parentNode) return;       // stale, or the picker closed
                loading = false;
                grid.setAttribute('aria-busy', 'false');
                var meta = d.meta || {};
                page = meta.page || (page + 1);
                total = meta.total || 0;
                hasMore = meta.has_more != null ? !!meta.has_more : page < (meta.last_page || 1);
                if (d.months && !monthsLoaded) fillMonths(d.months);
                var firstNew = addItems(d.data || [], false);
                if (!Object.keys(shown).length) renderMore('empty', filtered());
                else if (hasMore) renderMore('more');
                // The end of a list that needed Load More says so; a short list needs no footer.
                else renderMore('end', page > 1 ? 'All ' + total + ' files are shown.' : '');
                updateStatus();
                if (focusNew && firstNew) firstNew.focus();
            }).catch(function (err) {
                if (my !== seq || !me.parentNode) return;
                loading = false;
                grid.setAttribute('aria-busy', 'false');
                renderMore('error', (err && err.message) || 'Could not load files.');
                status.textContent = 'Could not load files';
            });
        }

        function fillMonths(months) {
            monthsLoaded = true;
            (months || []).forEach(function (m) {
                var o = document.createElement('option');
                o.value = m.value; o.textContent = m.label || m.value;
                monthSel.appendChild(o);
            });
            monthSel.hidden = !(months && months.length);
        }

        // ---- filters ----
        var searchTimer = null;
        search.addEventListener('input', function () {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(function () {
                var v = search.value.trim();
                if (v === q.search) return;
                q.search = v; loadPage(true);
            }, 300);
        });
        if (typeSel) typeSel.addEventListener('change', function () { q.type = typeSel.value; loadPage(true); });
        monthSel.addEventListener('change', function () { q.month = monthSel.value; loadPage(true); });

        // ---- upload inside the picker ----
        fileIn.addEventListener('change', function (e) {
            Array.prototype.forEach.call(e.target.files, function (file) { uploadInline(file); });
            fileIn.value = '';
        });
        function uploadInline(file) {
            var row = makeProgressRow(file, true);
            progress.appendChild(row);
            var pct = row.querySelector('.nm-progress-row-pct');
            uploadFile(file, { onProgress: function (n) { pct.textContent = n + '%'; } }).then(function (resp) {
                row.remove();
                var m = resp && (resp.data || resp);
                if (!m || !m.id || !me.parentNode) return;
                if (allowed.length && allowed.indexOf(kindOf(m)) === -1) {
                    var warn = makeProgressRow(file, true);
                    progress.appendChild(warn);
                    progressError(warn, 'Uploaded to the library, but this kind of file cannot be used here.');
                    return;
                }
                // New files go first and are picked straight away.
                if (moreBox.parentNode === null) grid.appendChild(moreBox);
                if (moreBox.classList.contains('nm-more--empty')) renderMore('end', '');
                addItems([m], true);
                total++;
                if (!isChosen(m.id)) choose(m, true);
                updateStatus();
            }).catch(function (err) { progressError(row, err.message); });
        }

        // ---- start: the initial selection, then page 1 ----
        var initial = opts.selected == null || opts.selected === '' ? [] : (Array.isArray(opts.selected) ? opts.selected : [opts.selected]);
        initial = initial.map(function (v) { return parseInt(v, 10); }).filter(function (v) { return v > 0; }).slice(0, multiple ? 100 : 1);
        initial.forEach(function (id) { chosen.push(String(id)); });
        syncConfirm();
        // An item selected before may be on a later page: fetch it so the
        // details pane and Select work without paging to it.
        initial.forEach(function (id) {
            window.Basehim.ajax.get('media.get', { id: id }).then(function (d) {
                if (!me.parentNode || !d || !d.item) return;
                known[String(id)] = d.item;
                if (String(id) === chosen[chosen.length - 1] && !focusId) showDetails(d.item);
            }).catch(function () {
                // Gone or not allowed: it is no longer selected.
                var i = chosen.indexOf(String(id)); if (i !== -1) chosen.splice(i, 1);
                syncConfirm(); updateStatus();
            });
        });
        loadPage(true);
        setTimeout(function () { try { search.focus(); } catch (e) {} }, 0);
        return { close: function () { close(true); } };
    }

    function acceptAttr(allowed) {
        if (!allowed.length) return '';
        var map = { image: 'image/*', svg: '.svg', video: 'video/*', audio: 'audio/*', document: '.pdf,.doc,.docx,.xls,.xlsx,.zip,.txt,.csv' };
        return ' accept="' + allowed.map(function (k) { return map[k]; }).join(',') + '"';
    }

    /**
     * Declarative binding, so a picker can be wired up without writing any
     * JavaScript at all.
     *
     * Mark up a field like this and it works anywhere in the admin:
     *
     *     <div data-bh-media data-bh-media-types="image">
     *       <input type="hidden" data-bh-media-value value="/uploads/x.png">
     *       <input type="hidden" data-bh-media-id value="12">   (optional)
     *       <div data-bh-media-preview></div>
     *       <button type="button" data-bh-media-pick>Choose</button>
     *       <button type="button" data-bh-media-clear>Remove</button>
     *     </div>
     *
     * The element fires a `bh:media` event when the value changes, carrying the
     * chosen item, so a screen that needs to react can listen rather than
     * reimplement the picker. Attributes are read at click time and the
     * listener is delegated from the document, so markup added later — a widget
     * form, a repeating field — works without being registered.
     */
    function valueOf(root) {
        var input = root.querySelector('[data-bh-media-value]');
        return input ? input.value : '';
    }

    function setValue(root, media) {
        var input   = root.querySelector('[data-bh-media-value]');
        var preview = root.querySelector('[data-bh-media-preview]');
        var clear   = root.querySelector('[data-bh-media-clear]');

        if (input) input.value = media ? (media.url || '') : '';
        var idIn = root.querySelector('[data-bh-media-id]');
        if (idIn) idIn.value = media && media.id ? String(media.id) : '';

        if (preview) {
            if (media && media.mime_type && media.mime_type.indexOf('image/') === 0) {
                var ps = sizesOf(media);
                preview.innerHTML = '<img src="' + escapeHtml((ps.medium && ps.medium.url) || media.url) + '" alt="">';
                preview.classList.remove('is-empty');
            } else if (media) {
                preview.textContent = media.original_name || media.file_name || 'File selected';
                preview.classList.remove('is-empty');
            } else {
                preview.innerHTML = '';
                preview.classList.add('is-empty');
            }
        }

        if (clear) clear.hidden = !media;

        // So a host screen can react — mark itself dirty, update a preview
        // frame — without knowing anything about how the picker works.
        root.dispatchEvent(new CustomEvent('bh:media', {
            bubbles: true,
            detail: { media: media || null, value: media ? media.url : '' }
        }));
    }

    document.addEventListener('click', function (e) {
        var pick = e.target.closest('[data-bh-media-pick]');
        if (pick) {
            var root = pick.closest('[data-bh-media]');
            if (!root) return;
            e.preventDefault();
            var idIn = root.querySelector('[data-bh-media-id]');
            openPicker({
                // data-bh-media-types="image" (or "image,svg", "video" …) narrows the list.
                types: root.getAttribute('data-bh-media-types') || '',
                title: root.getAttribute('data-bh-media-title') || undefined,
                selected: idIn && idIn.value ? idIn.value : null,
                onSelect: function (m) { setValue(root, m); }
            });
            return;
        }

        var clear = e.target.closest('[data-bh-media-clear]');
        if (clear) {
            var r = clear.closest('[data-bh-media]');
            if (!r) return;
            e.preventDefault();
            setValue(r, null);
        }
    });

    window.BasehimMedia = {
        openPicker: openPicker,
        attachDropzone: attachDropzone,
        uploadFile: uploadFile,
        url: url,
        // Exposed so a screen can drive a field it built itself.
        setValue: setValue,
        valueOf: valueOf,
        // 1.2.67: the type keys a picker understands, and how a string maps to them.
        types: TYPE_KEYS.slice(),
        normalizeTypes: normalizeTypes
    };
})(window, document);
