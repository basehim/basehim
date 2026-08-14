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
                    '<h3 class="nm-title">'+BasehimIcon('photo','w-4 h-4')+'Select Media</h3>' +
                    '<button type="button" class="nm-close" aria-label="Close">'+BasehimIcon('x-mark','w-4 h-4')+'</button>' +
                '</div>' +
                '<div class="nm-toolbar">' +
                    '<input type="text" placeholder="Search media..." class="nm-search">' +
                    '<label class="nm-upload-btn">'+BasehimIcon('arrow-up-tray','w-4 h-4')+' Upload new<input type="file" class="nm-upload" multiple></label>' +
                '</div>' +
                '<div class="nm-progress"></div>' +
                '<div class="nm-grid"></div>' +
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

        function close() { if (modal) { modal.remove(); modal = null; } }
        modal.querySelector('.nm-close').onclick = close;
        modal.querySelector('.nm-btn-cancel').onclick = close;
        modal.addEventListener('click', function (e) { if (e.target === modal) close(); });
        document.addEventListener('keydown', function escH(e) {
            if (e.key === 'Escape' && modal) { close(); document.removeEventListener('keydown', escH); }
        });

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
                    card.onclick = function () {
                        grid.querySelectorAll('.nm-card[data-selected="1"]').forEach(function (e) { e.dataset.selected = ''; });
                        card.dataset.selected = '1';
                        selected = m;
                        btnOk.disabled = false;
                    };
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

    window.BasehimMedia = {
        openPicker: openPicker,
        attachDropzone: attachDropzone,
        uploadFile: uploadFile,
        url: url
    };
})(window, document);
