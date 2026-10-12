/*
 * Runs the real admin/assets/js/ajax.js against the running test server.
 * Started by tests/Integration/JsClientTest.php, which passes:
 *
 *   BASE_URL      the test server
 *   ADMIN_COOKIE  "BASEHIMSESS=…" of a signed-in admin
 *   ADMIN_TOKEN   that session's CSRF token
 *
 * Each "page" is a fresh VM context with its own window, document and cookie
 * jar, like a browser tab. Prints one JSON line: [{name, ok, error?}, …].
 */
'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');
const http = require('http');

const BASE = process.env.BASE_URL;
const SRC = fs.readFileSync(path.join(__dirname, '../../admin/assets/js/ajax.js'), 'utf8');

/** A browser tab: the client loaded with the given config and cookies. */
function page({ config, cookie = '', bodyAttrs = {} } = {}) {
    const jar = new Map();
    cookie.split(';').map(s => s.trim()).filter(Boolean).forEach(c => {
        const i = c.indexOf('=');
        jar.set(c.slice(0, i), c.slice(i + 1));
    });
    const sent = [];
    const cookieFetch = async (url, init = {}) => {
        const headers = Object.assign({}, init.headers || {});
        if (init.credentials === 'same-origin' && jar.size && String(url).startsWith(BASE)) {
            headers['Cookie'] = [...jar].map(([k, v]) => k + '=' + v).join('; ');
        }
        // What a browser adds by itself on a same-origin fetch.
        headers['Sec-Fetch-Site'] = 'same-origin';
        sent.push({ url: String(url), headers: Object.assign({}, init.headers || {}), body: init.body });
        const res = await fetch(url, Object.assign({}, init, { headers }));
        for (const sc of res.headers.getSetCookie ? res.headers.getSetCookie() : []) {
            const [pair] = sc.split(';');
            const i = pair.indexOf('=');
            jar.set(pair.slice(0, i).trim(), pair.slice(i + 1).trim());
        }
        return res;
    };
    const window = {
        BASEHIM_AJAX: config,
        crypto: globalThis.crypto,
    };
    const document = { body: { getAttribute: (k) => (k in bodyAttrs ? bodyAttrs[k] : null) } };
    const ctx = vm.createContext({
        window, document, fetch: cookieFetch, AbortController, setTimeout, clearTimeout,
        Promise, JSON, Object, Error, Array, String, Uint8Array, encodeURIComponent,
    });
    vm.runInContext(SRC, ctx, { filename: 'ajax.js' });
    return { ajax: window.Basehim.ajax, sent, jar };
}

async function expectError(promise, code) {
    try {
        await promise;
    } catch (e) {
        if (e.code !== code) throw new Error(`expected ${code}, got ${e.code}: ${e.message}`);
        return e;
    }
    throw new Error(`expected ${code}, the request succeeded`);
}

function assert(cond, msg) { if (!cond) throw new Error(msg); }

const tests = {
    async 'success resolves with the handler data'() {
        const p = page({ config: { url: BASE + '/ajax', csrf: process.env.ADMIN_TOKEN }, cookie: process.env.ADMIN_COOKIE });
        const data = await p.ajax.request('ajax-fixture.echo', { msg: 'from js' });
        assert(data.msg === 'from js', 'echoed message');
        const req = p.sent[0];
        assert(req.headers['X-CSRF-Token'] === process.env.ADMIN_TOKEN, 'token sent in the header');
        assert(req.headers['Content-Type'] === 'application/json', 'JSON content type');
        assert(!req.url.includes(process.env.ADMIN_TOKEN), 'token never in the URL');
        assert(JSON.parse(req.body).action === 'ajax-fixture.echo', 'action in the body');
    },

    async 'server errors become AjaxError with code, status, message and request ID'() {
        const p = page({ config: { url: BASE + '/ajax', csrf: process.env.ADMIN_TOKEN }, cookie: process.env.ADMIN_COOKIE });
        const e = await expectError(p.ajax.request('ajax-fixture.nope'), 'ACTION_NOT_FOUND');
        assert(e.status === 404, 'status 404');
        assert(/^[0-9a-f]{16}$/.test(e.requestId), 'request id');
        assert(e instanceof p.ajax.AjaxError && e instanceof Error, 'an AjaxError and an Error');
        assert(e.message.length > 0, 'message');
    },

    async 'validation errors carry per-field messages'() {
        const p = page({ config: { url: BASE + '/ajax', csrf: process.env.ADMIN_TOKEN }, cookie: process.env.ADMIN_COOKIE });
        const e = await expectError(p.ajax.request('ajax-fixture.echo', { msg: 'x', n: 'many' }), 'VALIDATION_FAILED');
        assert(e.status === 422 && Array.isArray(e.fields.n), 'field error for n');
    },

    async 'a stale token is refreshed once and the request retried'() {
        const p = page({ config: { url: BASE + '/ajax', csrf: 'stale-token' }, cookie: process.env.ADMIN_COOKIE });
        const data = await p.ajax.request('ajax-fixture.echo', { msg: 'retry' });
        assert(data.msg === 'retry', 'succeeded after refresh');
        const actions = p.sent.map(s => s.body ? JSON.parse(s.body).action : new URL(s.url).searchParams.get('action'));
        assert(JSON.stringify(actions) === JSON.stringify(['ajax-fixture.echo', 'core.csrf', 'ajax-fixture.echo']),
            'echo → core.csrf → echo, got ' + JSON.stringify(actions));
    },

    async 'a visitor page with no token fetches one before its first POST'() {
        const p = page({ config: { url: BASE + '/ajax' } });   // what bh_ajax_client() prints
        const data = await p.ajax.request('ajax-fixture.public-form', { email: 'visitor@example.com' });
        assert(data.received === 'visitor@example.com', 'public form accepted');
        assert(new URL(p.sent[0].url).searchParams.get('action') === 'core.csrf', 'token fetched first');
        assert(p.sent.length === 2, 'no failed first attempt');
    },

    async 'an expired session is reported, not retried forever'() {
        const p = page({ config: { url: BASE + '/ajax', csrf: 'x' } });   // no cookie at all
        const e = await expectError(p.ajax.request('ajax-fixture.echo', { msg: 'x' }), 'AUTH_REQUIRED');
        assert(e.status === 401, '401');
        assert(p.sent.length <= 3, 'at most one refresh and one retry');
    },

    async 'GET builds a query string and refuses nested values without a request'() {
        const p = page({ config: { url: BASE + '/ajax' }, cookie: process.env.ADMIN_COOKIE });
        const data = await p.ajax.get('ajax-fixture.read', { page: 2, flag: false });
        assert(data.page === 2 && data.flag === false, 'typed values back');
        await expectError(p.ajax.get('ajax-fixture.read', { page: { a: 1 } }), 'BAD_REQUEST');
        assert(p.sent.length === 1, 'nested GET never sent');
        assert(!('X-CSRF-Token' in p.sent[0].headers), 'no token on GET');
    },

    async 'idempotency keys: generated, sent, and a reused key is a duplicate'() {
        const p = page({ config: { url: BASE + '/ajax', csrf: process.env.ADMIN_TOKEN }, cookie: process.env.ADMIN_COOKIE });
        await p.ajax.request('ajax-fixture.once', {}, { idempotencyKey: true });
        assert(/^[0-9a-f]{32}$/.test(p.sent[0].headers['Idempotency-Key']), 'generated key');
        const key = p.ajax.newIdempotencyKey();
        await p.ajax.request('ajax-fixture.once', {}, { idempotencyKey: key });
        await expectError(p.ajax.request('ajax-fixture.once', {}, { idempotencyKey: key }), 'DUPLICATE_REQUEST');
    },

    async 'a non-JSON answer is BAD_RESPONSE'() {
        const p = page({ config: { url: BASE + '/admin/assets/js/ajax.js' } });
        await expectError(p.ajax.request('x.y', {}, { method: 'GET' }), 'BAD_RESPONSE');
    },

    async 'an unreachable server is NETWORK_ERROR'() {
        const p = page({ config: { url: 'http://127.0.0.1:1/ajax' } });
        await expectError(p.ajax.request('x.y', {}, { method: 'GET' }), 'NETWORK_ERROR');
    },

    async 'a hanging server is TIMEOUT, and a caller abort is ABORTED'() {
        const server = http.createServer(() => { /* never answers */ });
        await new Promise(r => server.listen(0, '127.0.0.1', r));
        const url = `http://127.0.0.1:${server.address().port}/ajax`;
        try {
            const p = page({ config: { url } });
            await expectError(p.ajax.request('x.y', {}, { method: 'GET', timeout: 200 }), 'TIMEOUT');
            const ac = new AbortController();
            const pending = p.ajax.request('x.y', {}, { method: 'GET', signal: ac.signal });
            setTimeout(() => ac.abort(), 50);
            await expectError(pending, 'ABORTED');
        } finally {
            server.closeAllConnections && server.closeAllConnections();
            server.close();
        }
    },

    async 'an empty action name is refused locally'() {
        const p = page({ config: { url: BASE + '/ajax' } });
        await expectError(p.ajax.request(''), 'BAD_REQUEST');
        assert(p.sent.length === 0, 'nothing sent');
    },

    async 'the admin layout config (body data attributes) works without BASEHIM_AJAX'() {
        const p = page({ config: undefined, cookie: process.env.ADMIN_COOKIE,
            bodyAttrs: { 'data-base': BASE, 'data-csrf': process.env.ADMIN_TOKEN } });
        const data = await p.ajax.request('ajax-fixture.echo', { msg: 'body' });
        assert(data.msg === 'body', 'used body attributes');
        assert(p.sent[0].url === BASE + '/ajax', 'endpoint from data-base');
    },
};

(async () => {
    const results = [];
    for (const [name, fn] of Object.entries(tests)) {
        try {
            await fn();
            results.push({ name, ok: true });
        } catch (e) {
            results.push({ name, ok: false, error: String(e && e.message || e) });
        }
    }
    process.stdout.write(JSON.stringify(results));
})();
