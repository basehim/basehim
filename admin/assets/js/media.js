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
    var modal = null;

    function openPicker(opts) {
        opts = opts || {};
        if (modal) modal.remove();

        modal = el('div', { class: 'nm-overlay' });
        modal.innerHTML =
            '<div class="nm-dialog">' +
                '<div class="nm-header">' +
                    '<h3 class="nm-title">'+BasehimIcon('photo','w-5 h-5')+'<span>Select media</span></h3>' +
                    '<button type="button" class="nm-close" aria-label="Close">'+BasehimIcon('x-mark','w-5 h-5')+'</button>' +
                '</div>' +
                '<div class="nm-toolbar">' +
                    '<div class="nm-searchwrap">' +
                        BasehimIcon('magnifying-glass','w-4 h-4') +
                        '<input type="text" placeholder="Search media" class="nm-search" aria-label="Search media">' +
                    '</div>' +
                    '<label class="nm-upload-btn">'+BasehimIcon('arrow-up-tray','w-4 h-4')+'<span>Upload</span><input type="file" class="nm-upload" multiple></label>' +
                '</div>' +
                '<div class="nm-progress"></div>' +
                /* Two panes: the library on the left, the selected item on the
                   right. The details pane is where alt text and caption are
                   edited — previously the picker could only choose a file, so
                   the only way to give an image alt text was to leave the post,
                   open the media library, edit it, and come back. In practice
                   that meant images went out with no alt text at all. */
                '<div class="nm-body">' +
                    '<div class="nm-grid" role="listbox" aria-label="Media library"></div>' +
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
                    '<span class="nm-status">Loading…</span>' +
                    '<div class="nm-actions">' +
                        '<button type="button" class="nm-btn-cancel">Cancel</button>' +
                        '<button type="button" class="nm-btn-confirm" disabled>Select</button>' +
                    '</div>' +
                '</div>' +
            '</div>';
        document.body.appendChild(modal);

        var selected = null;
        var grid     = modal.querySelector('.nm-grid');
        var status   = modal.querySelector('.nm-status');
        var btnOk    = modal.querySelector('.nm-btn-confirm');
        var search   = modal.querySelector('.nm-search');
        var fileIn   = modal.querySelector('.nm-upload');
        var progress = modal.querySelector('.nm-progress');
        var details  = modal.querySelector('.nm-details');
        var dPreview = modal.querySelector('.nm-details-preview');
        var dMeta    = modal.querySelector('.nm-details-meta');
        var altIn    = modal.querySelector('.nm-alt');
        var capIn    = modal.querySelector('.nm-caption');
        var saveMsg  = modal.querySelector('.nm-savestate');

        function close() { if (modal) { modal.remove(); modal = null; } }
        modal.querySelector('.nm-close').onclick = close;
        modal.querySelector('.nm-btn-cancel').onclick = close;
        modal.addEventListener('click', function (e) { if (e.target === modal) close(); });
        document.addEventListener('keydown', function escH(e) {
            if (e.key === 'Escape' && modal) { close(); document.removeEventListener('keydown', escH); }
        });

        /**
         * Fill the details pane for the selected item.
         *
         * Alt text and caption save on their own a moment after typing stops,
         * and immediately when a field loses focus — which matters, because the
         * next thing anyone does is press Select. An explicit Save button would
         * be one more thing to forget, and the edit is to the media item rather
         * than to the post, so there is nothing for it to belong to.
         */
        var saveTimer = null;
        function showDetails(m) {
            if (!details) return;
            details.hidden = false;
            var isImg = m.mime_type && m.mime_type.indexOf('image/') === 0;

            dPreview.innerHTML = isImg
                ? '<img src="' + escapeHtml(m.url) + '" alt="">'
                : '<div class="nm-details-file">' + BasehimIcon('document', 'w-8 h-8') + '</div>';

            var bits = [];
            bits.push('<div class="nm-details-name">' + escapeHtml(m.original_name || m.file_name || '') + '</div>');
            var facts = [];
            if (m.width && m.height) facts.push(m.width + ' × ' + m.height);
            if (m.file_size) facts.push(formatBytes(m.file_size));
            if (m.mime_type) facts.push(String(m.mime_type).replace('image/', '').toUpperCase());
            if (facts.length) bits.push('<div class="nm-details-facts">' + escapeHtml(facts.join(' · ')) + '</div>');
            dMeta.innerHTML = bits.join('');

            altIn.value = m.alt_text || '';
            capIn.value = m.caption || '';
            // Alt text means nothing on a file that is not an image.
            altIn.closest('.nm-field').hidden = !isImg;
            saveMsg.textContent = '';
            clearTimeout(saveTimer);
        }

        function formatBytes(n) {
            n = Number(n) || 0;
            if (n < 1024) return n + ' B';
            if (n < 1048576) return Math.round(n / 1024) + ' KB';
            return (n / 1048576).toFixed(1) + ' MB';
        }

        function queueSave() {
            if (!selected) return;
            clearTimeout(saveTimer);
            saveMsg.textContent = '';
            saveTimer = setTimeout(saveMeta, 700);
        }

        function saveMeta() {
            if (!selected) return;
            var id = selected.id;
            var body = new URLSearchParams();
            body.set('alt_text', altIn.value);
            body.set('caption', capIn.value);
            // The same token the uploader uses, from body[data-csrf]. There is no
            // csrf-token meta tag in the admin layout, so looking for one would
            // send an empty token and be refused with a 419.
            body.set('_csrf', CSRF);

            saveMsg.textContent = 'Saving…';
            saveMsg.className = 'nm-savestate';

            fetch(url('/admin/media/' + encodeURIComponent(id) + '/update'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                },
                credentials: 'same-origin',
                body: body.toString()
            }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
              .then(function (res) {
                if (!res.ok || res.j.error) {
                    saveMsg.textContent = res.j.error || 'Could not save.';
                    saveMsg.className = 'nm-savestate is-error';
                    return;
                }
                // Keep the in-memory copy current, so re-selecting the card shows
                // what was typed rather than what was first loaded.
                if (res.j.data) {
                    selected.alt_text = res.j.data.alt_text;
                    selected.caption  = res.j.data.caption;
                }
                saveMsg.textContent = 'Saved';
                saveMsg.className = 'nm-savestate is-ok';
                setTimeout(function () {
                    if (saveMsg.textContent === 'Saved') saveMsg.textContent = '';
                }, 2000);
            }).catch(function () {
                saveMsg.textContent = 'Could not save.';
                saveMsg.className = 'nm-savestate is-error';
            });
        }

        altIn.addEventListener('input', queueSave);
        capIn.addEventListener('input', queueSave);
        altIn.addEventListener('blur', function () { clearTimeout(saveTimer); saveMeta(); });
        capIn.addEventListener('blur', function () { clearTimeout(saveTimer); saveMeta(); });

        function loadMedia(query) {
            status.textContent = 'Loading…';
            var q = query ? '&q=' + encodeURIComponent(query) : '';
            fetch(url('/admin/media/json?per_page=60' + q), {
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                credentials: 'same-origin'
            }).then(function (r) { return r.json(); }).then(function (data) {
                grid.innerHTML = '';
                var items = data.data || [];
                if (items.length === 0) {
                    grid.innerHTML = '<div class="nm-empty">'+BasehimIcon('folder-open','w-4 h-4')+'<p>No media found.</p></div>';
                    status.textContent = '0 items';
                    return;
                }
                items.forEach(function (m) {
                    var isImg = m.mime_type && m.mime_type.indexOf('image/') === 0;
                    var card = el('div', { class: 'nm-card', dataset: { id: m.id, url: m.url } });
                    if (isImg) {
                        card.innerHTML = '<img src="' + escapeHtml(m.url) + '" alt="' + escapeHtml(m.alt_text || '') + '">';
                    } else {
                        card.innerHTML =
                            '<div class="nm-card-file">' +
                                ''+BasehimIcon('document','w-4 h-4')+'' +
                                '<div class="nm-card-file-name">' + escapeHtml(m.original_name || m.file_name) + '</div>' +
                            '</div>';
                    }
                    card.setAttribute('role', 'option');
                    card.setAttribute('tabindex', '0');
                    card.setAttribute('aria-selected', 'false');
                    card.title = m.original_name || m.file_name || '';
                    function choose() {
                        grid.querySelectorAll('.nm-card[data-selected="1"]').forEach(function (e) {
                            e.dataset.selected = ''; e.setAttribute('aria-selected', 'false');
                        });
                        card.dataset.selected = '1';
                        card.setAttribute('aria-selected', 'true');
                        selected = m;
                        btnOk.disabled = false;
                        showDetails(m);
                    }
                    card.onclick = choose;
                    // Enter or Space selects, so the grid works from the keyboard.
                    card.onkeydown = function (ev) {
                        if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); choose(); }
                    };
                    // A double click picks it outright. Choose-then-confirm is the
                    // common case, and making that two actions instead of three is
                    // the difference people actually notice.
                    card.ondblclick = function () { choose(); btnOk.click(); };
                    grid.appendChild(card);
                });
                status.textContent = items.length + ' item' + (items.length === 1 ? '' : 's');
            }).catch(function () {
                grid.innerHTML = '<div class="nm-error">'+BasehimIcon('x-circle','w-4 h-4')+'<p>Failed to load media.</p></div>';
                status.textContent = 'Error';
            });
        }

        var searchTimer = null;
        search.addEventListener('input', function () {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(function () { loadMedia(search.value); }, 300);
        });

        fileIn.addEventListener('change', function (e) {
            Array.prototype.forEach.call(e.target.files, function (file) { uploadInline(file); });
            fileIn.value = '';
        });

        function uploadInline(file) {
            var row = makeProgressRow(file, true);
            progress.appendChild(row);
            var pct = row.querySelector('.nm-progress-row-pct');
            uploadFile(file, {
                onProgress: function (n) { pct.textContent = n + '%'; }
            }).then(function () {
                row.remove();
                loadMedia(search.value);
            }).catch(function (err) { progressError(row, err.message); });
        }

        btnOk.onclick = function () {
            if (selected && opts.onSelect) opts.onSelect(selected);
            close();
        };

        loadMedia();
    }

    /**
     * Declarative binding, so a picker can be wired up without writing any
     * JavaScript at all.
     *
     * Mark up a field like this and it works anywhere in the admin:
     *
     *     <div data-bh-media>
     *       <input type="hidden" data-bh-media-value value="/uploads/x.png">
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

        if (preview) {
            if (media && media.mime_type && media.mime_type.indexOf('image/') === 0) {
                preview.innerHTML = '<img src="' + escapeHtml(media.url) + '" alt="">';
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
            openPicker({
                multiple: root.hasAttribute('data-bh-media-multiple'),
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
        valueOf: valueOf
    };
})(window, document);
