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

    const setupForm = document.getElementById('wpmig-setup');
    const setupMsg  = document.getElementById('wpmig-setup-msg');
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
            setupMsg.textContent = 'Validating source…';
            setupMsg.classList.remove('text-red-700');
            const data = new FormData(setupForm);

            try {
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
