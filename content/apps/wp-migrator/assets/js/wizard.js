/* Icon helper: uses the core Heroicon set exposed as window.BasehimIcon when
   present, and falls back to Font Awesome markup otherwise. */
var ICO = function (n, c) {
  return window.BasehimIcon ? window.BasehimIcon(n, c || 'w-4 h-4')
                            : '<i class="fa-solid fa-' + String(n).replace(/^fa-/, '') + '"></i>';
};
/*
 * wp-migrator wizard JS
 *
 * - Switches between WXR and MySQL source tabs
 * - Submits the setup form via fetch (multipart)
 * - Drives the batched progress loop: POST /admin/wp-migrator/run repeatedly
 *   and update the progress bar / counts / log from the JSON response.
 */
(function () {
    'use strict';
    const root = document.getElementById('wpmig-wizard');
    if (!root) return;

    const base = root.dataset.base || '';
    const csrf = root.dataset.csrf || '';
    const isRunning = root.dataset.running === '1';

    // Mirrors Wizard::MAX_IMPORT_BYTES on the server — checked here too so
    // an oversized file is rejected instantly instead of after uploading.
    const MAX_IMPORT_BYTES = 500 * 1024 * 1024; // 500 MB

    const setupForm = document.getElementById('wpmig-setup');
    const setupMsg  = document.getElementById('wpmig-setup-msg');
    const uploadWrap  = document.getElementById('wpmig-upload-progress');
    const uploadBytes = document.getElementById('wpmig-upload-bytes');
    const uploadBar   = document.getElementById('wpmig-upload-bar');
    const progress  = document.getElementById('wpmig-progress');
    const done      = document.getElementById('wpmig-done');
    const stepLabel = document.getElementById('wpmig-step-label');
    const stepProg  = document.getElementById('wpmig-step-progress');
    const stepBar   = document.getElementById('wpmig-step-bar');
    const counts    = document.getElementById('wpmig-counts');
    const logEl     = document.getElementById('wpmig-log');
    const summary   = document.getElementById('wpmig-summary');
    const cancelBtn = document.getElementById('wpmig-cancel');

    const STEP_LABELS = {
        users:           'Importing users',
        taxonomies:      'Importing categories & tags',
        media:           'Downloading media',
        posts:           'Importing posts & pages',
        featured_media:  'Linking featured images',
        comments:        'Importing comments',
        menus:           'Building menus',
        redirects:       'Creating redirects',
        rewrite_content: 'Rewriting inline URLs',
    };

    // ----------------------------------------------------------------
    // Shared response parser
    //
    // The server should always return JSON, but if anything goes wrong
    // upstream (auth redirect, route not registered, PHP fatal, server
    // error page) the response is HTML. This helper turns either case
    // into a meaningful error message we can show the user instead of
    // a useless "Unexpected token '<'" parse error.
    // ----------------------------------------------------------------
    async function postJson(url, body) {
        let res;
        try {
            res = await fetch(url, {
                method: 'POST',
                body,
                // ErrorHandler checks Accept to decide JSON vs HTML response,
                // so make sure unhandled server errors come back as JSON.
                headers: { 'Accept': 'application/json' },
            });
        } catch (netErr) {
            throw new Error('Network unreachable: ' + netErr.message);
        }

        const text = await res.text();

        // Try to parse as JSON first — works for both ok and error responses
        // when the server actually returned JSON.
        let json = null;
        try { json = JSON.parse(text); } catch (_) {}

        if (!res.ok) {
            if (json && json.error) {
                const err = new Error(json.error);
                err.httpStatus = res.status;
                throw err;
            }
            // Non-JSON error body — typically an HTML error page or login
            // redirect. Give the user a useful diagnostic.
            let hint = '';
            const lower = text.toLowerCase();
            if (res.status === 404) {
                hint = 'The app route was not found. Make sure the app is activated and try refreshing the page.';
            } else if (res.status === 401 || res.status === 403 || lower.includes('login') || lower.includes('sign in')) {
                hint = 'Your session may have expired. Please refresh and log in again.';
            } else if (res.status === 413) {
                hint = 'The upload is larger than the server allows. Increase upload_max_filesize/post_max_size in PHP settings.';
            } else if (res.status >= 500) {
                hint = 'Server error. Check storage/logs/app.log for details.';
            }
            const stripped = text.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
            const head = stripped ? ' — ' + stripped.slice(0, 160) : '';
            const err = new Error(`HTTP ${res.status}${hint ? ': ' + hint : ''}${head}`);
            err.httpStatus = res.status;
            throw err;
        }

        if (json === null) {
            throw new Error('Server returned a non-JSON response. The app may not be installed correctly.');
        }
        return json;
    }

    function formatBytes(n) {
        const mb = n / (1024 * 1024);
        return mb >= 1000 ? (mb / 1024).toFixed(2) + ' GB' : mb.toFixed(1) + ' MB';
    }

    // ----------------------------------------------------------------
    // Chunked file upload
    //
    // Large WXR files (up to 500 MB) are uploaded in small pieces instead
    // of one big multipart POST, so the server's per-request
    // upload_max_filesize/post_max_size never has to be raised. The server
    // tells us the chunk size it can accept in the upload/init response.
    // ----------------------------------------------------------------
    async function uploadFileInChunks(file, onProgress) {
        const initFd = new FormData();
        initFd.append('_csrf', csrf);
        initFd.append('filename', file.name);
        initFd.append('total_size', String(file.size));
        const initRes = await postJson(base + '/admin/wp-migrator/upload/init', initFd);
        if (!initRes.ok) throw new Error(initRes.error || 'Could not start upload.');

        const uploadId = initRes.upload_id;
        const chunkSize = initRes.chunk_size;
        const totalChunks = Math.max(1, Math.ceil(file.size / chunkSize));

        onProgress(0, file.size);

        for (let i = 0; i < totalChunks; i++) {
            const start = i * chunkSize;
            const end = Math.min(start + chunkSize, file.size);
            const blob = file.slice(start, end);

            let attempt = 0;
            // Retry a few times with backoff before giving up — a single
            // flaky chunk shouldn't force re-uploading the whole file.
            for (;;) {
                try {
                    const fd = new FormData();
                    fd.append('_csrf', csrf);
                    fd.append('upload_id', uploadId);
                    fd.append('chunk_index', String(i));
                    fd.append('chunk', blob, file.name);
                    const res = await postJson(base + '/admin/wp-migrator/upload/chunk', fd);
                    if (!res.ok) throw new Error(res.error || 'Chunk upload failed.');
                    break;
                } catch (err) {
                    attempt++;
                    if (attempt >= 3) throw err;
                    await sleep(500 * attempt);
                }
            }

            onProgress(end, file.size);
        }

        return uploadId;
    }

    // ---- Source tabs ----
    document.querySelectorAll('.wpmig-tab').forEach(btn => {
        btn.addEventListener('click', () => {
            const tab = btn.dataset.tab;
            document.querySelectorAll('.wpmig-tab').forEach(b => {
                b.classList.toggle('bg-blue-50', b === btn);
                b.classList.toggle('text-blue-700', b === btn);
                b.classList.toggle('border-blue-200', b === btn);
            });
            document.querySelectorAll('.wpmig-pane').forEach(p => {
                p.classList.toggle('hidden', p.dataset.pane !== tab);
            });
            document.getElementById('wpmig-source').value = tab;
        });
    });
    // Set initial active style on the WXR tab.
    const firstTab = document.querySelector('.wpmig-tab');
    if (firstTab) firstTab.classList.add('bg-blue-50', 'text-blue-700', 'border-blue-200');

    // ---- Start migration ----
    if (setupForm) {
        setupForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            setupMsg.classList.remove('text-red-700');

            const sourceType = document.getElementById('wpmig-source').value;
            const fileInput = setupForm.querySelector('input[name="wxr_file"]');
            const file = (sourceType === 'wxr' && fileInput) ? fileInput.files[0] : null;

            if (file && file.size > MAX_IMPORT_BYTES) {
                setupMsg.textContent = `File is too large — max import size is 500 MB (this file is ${Math.round(file.size / 1048576)} MB).`;
                setupMsg.classList.add('text-red-700');
                return;
            }

            const data = new FormData(setupForm);

            try {
                if (file) {
                    // Upload the file in chunks first; drop it from the
                    // form data so /start doesn't also receive it inline.
                    data.delete('wxr_file');
                    uploadBar.style.width = '0%';
                    uploadBytes.textContent = `0 MB / ${formatBytes(file.size)} (0%)`;
                    uploadWrap.classList.remove('hidden');
                    setupMsg.textContent = 'Uploading file…';

                    const uploadId = await uploadFileInChunks(file, (uploaded, total) => {
                        const pct = total > 0 ? Math.round((uploaded / total) * 100) : 0;
                        uploadBar.style.width = pct + '%';
                        uploadBytes.textContent = `${formatBytes(uploaded)} / ${formatBytes(total)} (${pct}%)`;
                    });

                    uploadWrap.classList.add('hidden');
                    data.append('upload_id', uploadId);
                }

                setupMsg.textContent = 'Validating source…';
                const json = await postJson(base + '/admin/wp-migrator/start', data);
                if (!json.ok) {
                    setupMsg.textContent = '';
                    alert(json.error || 'Could not start migration.');
                    return;
                }
                // Hide form, show progress, kick off loop.
                setupForm.classList.add('hidden');
                progress.classList.remove('hidden');
                setupMsg.textContent = '';
                loop();
            } catch (err) {
                uploadWrap.classList.add('hidden');
                setupMsg.textContent = err.message;
                setupMsg.classList.add('text-red-700');
                console.error('wp-migrator start failed:', err);
            }
        });
    }

    // ---- Cancel ----
    if (cancelBtn) {
        cancelBtn.addEventListener('click', async () => {
            if (!confirm('Cancel the migration? Any work done so far will remain in Basehim but the job will stop.')) return;

            // 1. Stop the local tick loop right now so we don't fire more requests.
            cancelLoop = true;
            cancelBtn.disabled = true;
            cancelBtn.innerHTML = ''+ICO('arrow-path','w-4 h-4 animate-spin mr-1')+' Cancelling…';

            // 2. Tell the server. Don't block the reload on this.
            try {
                const fd = new FormData(); fd.append('_csrf', csrf);
                await postJson(base + '/admin/wp-migrator/cancel', fd);
            } catch (err) {
                console.warn('cancel request failed:', err);
                /* ignore — we're reloading anyway */
            }

            // 3. Reload so the page reflects the cancelled job state.
            window.location.reload();
        });
    }

    // ---- Progress loop ----
    let cancelLoop = false;
    let lastTotal = 0;
    const MAX_CONSECUTIVE_ERRORS = 3;

    async function tick() {
        const fd = new FormData(); fd.append('_csrf', csrf);
        return await postJson(base + '/admin/wp-migrator/run', fd);
    }

    async function loop() {
        let consecutiveErrors = 0;

        while (!cancelLoop) {
            let resp;
            try {
                resp = await tick();
                consecutiveErrors = 0;
            } catch (e) {
                consecutiveErrors++;
                logAppend(`Error (attempt ${consecutiveErrors}/${MAX_CONSECUTIVE_ERRORS}): ${e.message}`);

                if (consecutiveErrors >= MAX_CONSECUTIVE_ERRORS) {
                    logAppend('Stopped after repeated errors.');
                    showError(e.message);
                    break;
                }

                // Retry with a small backoff, but bail early if the user cancelled.
                for (let i = 0; i < 30 && !cancelLoop; i++) await sleep(100);
                continue;
            }

            if (!resp.ok) {
                logAppend('Error: ' + (resp.error || 'unknown'));
                showError(resp.error || 'Unknown error');
                break;
            }
            if (resp.finished) {
                showFinished(resp.counts || {});
                break;
            }
            updateUi(resp);
        }
    }

    function showError(msg) {
        if (!stepLabel) return;
        stepLabel.textContent = 'Migration stopped';
        stepLabel.classList.add('text-red-700');
        if (stepProg) stepProg.textContent = msg.length > 200 ? msg.slice(0, 197) + '…' : msg;
    }

    function updateUi(resp) {
        const step = resp.step || 'unknown';
        stepLabel.textContent = STEP_LABELS[step] || step;
        stepLabel.classList.remove('text-red-700');
        if (resp.advanced) {
            stepBar.style.width = '0%';
            stepProg.textContent = '';
            lastTotal = 0;
            return;
        }
        const total = resp.total || 0;
        const cursor = Math.min(resp.cursor || 0, total);
        lastTotal = total;
        const pct = total > 0 ? Math.round((cursor / total) * 100) : 0;
        stepBar.style.width = pct + '%';
        stepProg.textContent = `${cursor} / ${total} (${pct}%)`;
        renderCounts(resp.counts || {});
    }

    function renderCounts(c) {
        counts.innerHTML = '';
        const order = ['users','taxonomies','media','posts','pages','featured_media','comments','menus','redirects','rewrite_content'];
        for (const key of order) {
            if (c[key] === undefined) continue;
            const cell = document.createElement('div');
            cell.className = 'bg-slate-50 rounded-lg px-3 py-2';
            cell.innerHTML = `<div class="text-xs text-slate-500">${label(key)}</div>
                              <div class="font-semibold text-slate-900">${c[key]}</div>`;
            counts.appendChild(cell);
        }
    }
    function label(k) {
        return ({
            users:'Users', taxonomies:'Cats/Tags', media:'Media', posts:'Posts', pages:'Pages',
            featured_media:'Featured', comments:'Comments', menus:'Menu items',
            redirects:'Redirects', rewrite_content:'Rewrites'
        }[k]) || k;
    }

    function showFinished(c) {
        progress.classList.add('hidden');
        done.classList.remove('hidden');
        const items = Object.entries(c).map(([k,v]) => `<li><strong>${v}</strong> ${label(k)}</li>`).join('');
        summary.innerHTML = `<ul class="list-disc list-inside text-slate-700">${items}</ul>`;
    }

    function logAppend(line) {
        if (!logEl) return;
        logEl.textContent += line + '\n';
        logEl.scrollTop = logEl.scrollHeight;
    }

    function sleep(ms) { return new Promise(r => setTimeout(r, ms)); }

    // If we landed on the page while a job is already running, resume the loop.
    if (isRunning) {
        loop();
    }
})();

/*
 * Repair image links in posts already on the site. Batched: the server
 * answers one batch at a time until `done`. Preview changes nothing.
 */
(function () {
    'use strict';
    const root = document.getElementById('wpmig-wizard');
    const box = document.getElementById('wpmig-repair');
    if (!root || !box) return;
    const base = root.dataset.base || '';
    const csrf = root.dataset.csrf || '';
    const out = document.getElementById('wpmig-repair-out');
    const site = document.getElementById('wpmig-repair-site');
    const btns = [document.getElementById('wpmig-repair-preview'), document.getElementById('wpmig-repair-apply')];

    const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

    async function run(apply) {
        if (apply && !confirm('Repair image links in all posts and pages now? This edits post content.')) return;
        btns.forEach((b) => (b.disabled = true));
        out.classList.remove('hidden');
        const sum = { changed: 0, urls: 0, unmapped: 0, galleries: 0, captions: 0, fetched: 0, samples: [], unmappedSamples: [] };
        let cursor = 0, done = false, last = null;
        try {
            while (!done) {
                const fd = new FormData();
                fd.append('_csrf', csrf); fd.append('apply', apply ? '1' : '0');
                fd.append('cursor', String(cursor)); fd.append('old_site', site.value.trim());
                const res = await fetch(base + '/admin/wp-migrator/repair', { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'Accept': 'application/json' } });
                const d = await res.json().catch(() => null);
                if (!d || !d.ok) throw new Error((d && (d.error || d.message)) || 'The request failed (HTTP ' + res.status + ').');
                ['changed', 'urls', 'unmapped', 'galleries', 'captions', 'fetched'].forEach((k) => (sum[k] += d[k] || 0));
                (d.samples || []).forEach((s) => sum.samples.length < 3 && sum.samples.push(s));
                (d.unmapped_samples || []).forEach((u) => sum.unmappedSamples.length < 3 && sum.unmappedSamples.push(u));
                cursor = d.cursor; done = d.done; last = d;
                out.innerHTML = '<p class="text-slate-600">' + (apply ? 'Repairing' : 'Checking') + '… ' + d.scanned + ' of ' + d.total + ' posts and pages.</p>';
            }
            const verb = apply ? 'Repaired' : 'Would repair';
            let html = '<p class="font-medium text-slate-900">' + verb + ' ' + sum.changed + (sum.changed === 1 ? ' post' : ' posts') + ': ' +
                sum.urls + ' image ' + (sum.urls === 1 ? 'link' : 'links') +
                (sum.galleries ? ', ' + sum.galleries + ' ' + (sum.galleries === 1 ? 'gallery' : 'galleries') : '') +
                (sum.captions ? ', ' + sum.captions + ' ' + (sum.captions === 1 ? 'caption' : 'captions') : '') +
                (sum.fetched ? ', ' + sum.fetched + ' resized copies fetched' : '') + '.</p>';
            if (sum.unmapped) {
                html += '<p class="mt-2 text-amber-700">' + sum.unmapped + ' image ' + (sum.unmapped === 1 ? 'link has' : 'links have') +
                    ' no imported file and ' + (apply ? 'were' : 'will be') + ' left as they are' +
                    (sum.unmappedSamples.length ? ', for example <code class="break-all">' + esc(sum.unmappedSamples[0]) + '</code>' : '') + '.</p>';
            }
            if (sum.samples.length) {
                html += '<ul class="mt-2 space-y-1 text-xs text-slate-500">' + sum.samples.map((s) =>
                    '<li><span class="text-slate-700">' + esc(s.post) + ':</span> <code class="break-all">' + esc(s.before) + '</code> → <code class="break-all">' + esc(s.after) + '</code></li>').join('') + '</ul>';
            }
            if (!apply && sum.changed) html += '<p class="mt-2 text-slate-600">Nothing has been changed yet. Choose Repair to apply this.</p>';
            if (!sum.changed) html = '<p class="text-slate-700">No image links need repairing.</p>';
            out.innerHTML = html;
        } catch (e) {
            out.innerHTML = '<p class="text-red-700">' + esc(e.message) + '</p>';
        } finally {
            btns.forEach((b) => (b.disabled = false));
        }
    }
    btns[0].addEventListener('click', () => run(false));
    btns[1].addEventListener('click', () => run(true));
})();

