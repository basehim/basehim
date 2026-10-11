/*
 * Basehim AJAX client — Basehim.ajax.request(action, data, options).
 * See docs/APP-AJAX.md.
 *
 *   Basehim.ajax.request('media.update', { id: 42, alt_text: 'A heron' })
 *     .then(function (data) { … })                  // the handler's return value
 *     .catch(function (err) {                        // a Basehim.ajax.AjaxError
 *       bhToast(err.message, 'error');               // safe to show as it is
 *       // err.code ('FORBIDDEN', 'VALIDATION_FAILED', …), err.status,
 *       // err.fields ({ alt_text: ['…'] }), err.requestId
 *     });
 *
 * Options: method ('POST' default, or 'GET' for read actions), timeout (ms,
 * default 30000), signal (an AbortSignal), idempotencyKey (true to generate
 * one, or a string you keep across retries).
 *
 * The server decides every policy; this only sends what it asks for. The
 * CSRF token goes in a header, never in a URL, and nothing is logged. On
 * CSRF_INVALID (the session signed in or out in another tab) the client
 * fetches the current token once and retries; if that fails too, the error
 * is passed on.
 *
 * Configuration comes from window.BASEHIM_AJAX = { url, csrf } when a page
 * prints it (bh_ajax_client() does, for themes), else from the admin
 * layout's <body data-base data-csrf>.
 */
(function (window, document) {
    'use strict';

    var Basehim = window.Basehim = window.Basehim || {};
    if (Basehim.ajax) return;

    var DEFAULT_TIMEOUT = 30000;
    var tokenOverride = null;

    function AjaxError(code, message, status, requestId, fields) {
        var e = new Error(message);
        Object.setPrototypeOf(e, AjaxError.prototype);
        e.name = 'AjaxError';
        e.code = code;
        e.status = status || 0;
        e.requestId = requestId || null;
        e.fields = fields || {};
        return e;
    }
    AjaxError.prototype = Object.create(Error.prototype);
    AjaxError.prototype.constructor = AjaxError;

    function settings() {
        var c = window.BASEHIM_AJAX || {};
        var body = document.body;
        var base = body ? (body.getAttribute('data-base') || '') : '';
        return {
            url: c.url || (base + '/ajax'),
            token: tokenOverride !== null ? tokenOverride
                 : (typeof c.csrf === 'string' ? c.csrf : (body ? body.getAttribute('data-csrf') || '' : ''))
        };
    }

    function newKey() {
        var c = window.crypto;
        if (c && typeof c.randomUUID === 'function') return c.randomUUID().replace(/-/g, '');
        var bytes = new Uint8Array(16);
        c.getRandomValues(bytes);
        return Array.prototype.map.call(bytes, function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
    }

    function queryString(action, data) {
        var parts = ['action=' + encodeURIComponent(action)];
        Object.keys(data || {}).forEach(function (k) {
            var v = data[k];
            if (v === undefined || v === null) return;
            if (typeof v === 'object') throw AjaxError('BAD_REQUEST', 'GET requests take flat values only.', 0);
            parts.push(encodeURIComponent(k) + '=' + encodeURIComponent(String(v)));
        });
        return parts.join('&');
    }

    function send(action, data, opts, key) {
        var s = settings();
        var method = (opts.method || 'POST').toUpperCase();
        var headers = { 'Accept': 'application/json' };
        var init = { method: method, headers: headers, credentials: 'same-origin', cache: 'no-store', redirect: 'error' };
        var url = s.url;

        if (method === 'GET') {
            url += (url.indexOf('?') === -1 ? '?' : '&') + queryString(action, data);
        } else {
            headers['Content-Type'] = 'application/json';
            if (s.token) headers['X-CSRF-Token'] = s.token;
            if (key) headers['Idempotency-Key'] = key;
            init.body = JSON.stringify({ action: action, data: data || {} });
        }

        var controller = typeof AbortController === 'function' ? new AbortController() : null;
        var timer = null;
        var timedOut = false;
        if (controller) {
            init.signal = controller.signal;
            timer = setTimeout(function () { timedOut = true; controller.abort(); }, opts.timeout || DEFAULT_TIMEOUT);
            if (opts.signal) {
                if (opts.signal.aborted) controller.abort();
                else opts.signal.addEventListener('abort', function () { controller.abort(); });
            }
        }

        return fetch(url, init).then(function (res) {
            return res.text().then(function (text) {
                var json = null;
                try { json = text ? JSON.parse(text) : null; } catch (e) { json = null; }
                if (!json || typeof json !== 'object' || typeof json.success !== 'boolean') {
                    throw AjaxError('BAD_RESPONSE', 'The server sent an unexpected response.', res.status);
                }
                if (json.success && res.ok) return json.data;
                var err = json.error || {};
                throw AjaxError(
                    typeof err.code === 'string' ? err.code : 'ERROR',
                    typeof err.message === 'string' ? err.message : 'The request could not be completed.',
                    res.status, json.request_id, err.fields
                );
            });
        }, function () {
            if (timedOut) throw AjaxError('TIMEOUT', 'The request took too long. Try again.', 0);
            if (opts.signal && opts.signal.aborted) throw AjaxError('ABORTED', 'The request was cancelled.', 0);
            throw AjaxError('NETWORK_ERROR', 'Could not reach the server. Check your connection and try again.', 0);
        }).then(function (data) {
            if (timer) clearTimeout(timer);
            return data;
        }, function (err) {
            if (timer) clearTimeout(timer);
            throw err;
        });
    }

    function request(action, data, options) {
        var opts = options || {};
        if (typeof action !== 'string' || action === '') {
            return Promise.reject(AjaxError('BAD_REQUEST', 'Name an action.', 0));
        }
        var key = null;
        if (opts.idempotencyKey === true) key = newKey();
        else if (typeof opts.idempotencyKey === 'string') key = opts.idempotencyKey;

        var isPost = (opts.method || 'POST').toUpperCase() === 'POST';
        // Public pages carry no token (see bh_ajax_client()): get one first.
        var ready = isPost && !settings().token ? refreshToken().catch(function () {}) : Promise.resolve();

        return ready.then(function () {
            return send(action, data, opts, key);
        }).catch(function (err) {
            if (!(err && err.code === 'CSRF_INVALID' && isPost)) throw err;
            // The token went stale (signed in or out elsewhere). Fetch the
            // current one and try once more, with the same idempotency key.
            return refreshToken().then(function () {
                return send(action, data, opts, key);
            }, function () { throw err; });
        });
    }

    function refreshToken() {
        return send('core.csrf', {}, { method: 'GET' }, null).then(function (d) {
            tokenOverride = d && typeof d.token === 'string' ? d.token : '';
        });
    }

    Basehim.ajax = {
        request: request,
        get: function (action, data, options) {
            var o = {}; Object.keys(options || {}).forEach(function (k) { o[k] = options[k]; });
            o.method = 'GET';
            return request(action, data, o);
        },
        post: function (action, data, options) { return request(action, data, options); },
        newIdempotencyKey: newKey,
        AjaxError: AjaxError
    };
})(window, document);
