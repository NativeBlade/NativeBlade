import { describe, it, beforeEach } from 'node:test';
import assert from 'node:assert/strict';
import {
    hasPendingRequest,
    fulfill,
    abort,
    done,
    __setFetchForTests,
    __resetForTests,
} from '../../../js/runtime/http-bridge.js';
import { makePhp } from '../helpers/php-stub.js';
import { spy } from '../helpers/ctx.js';

const PENDING_PATH = '/tmp/__nb_http_pending.json';
const CACHE_DIR = '/tmp/__nb_http_cache';

// Minimal Response-like object — good enough for the bridge which only
// reads status, headers.entries(), and awaits .text().
function makeResponse({ status = 200, headers = {}, body = '' } = {}) {
    return {
        status,
        headers: {
            entries() {
                return Object.entries(headers);
            },
        },
        text: async () => body,
    };
}

// ---------------------------------------------------------------
// hasPendingRequest
// ---------------------------------------------------------------

describe('http-bridge/hasPendingRequest', () => {
    it('detects the __NB_HTTP_PENDING__ marker in stdout', async () => {
        assert.equal(await hasPendingRequest(makePhp(), 'foo __NB_HTTP_PENDING__ bar'), true);
    });

    it('returns false for outputs without the marker', async () => {
        assert.equal(await hasPendingRequest(makePhp(), 'nothing to see'), false);
    });

    it('returns false for non-string output', async () => {
        assert.equal(await hasPendingRequest(makePhp(), null), false);
        assert.equal(await hasPendingRequest(makePhp(), { stdout: 'x' }), false);
    });
});

// ---------------------------------------------------------------
// fulfill — fetch orchestration + cache writes
// ---------------------------------------------------------------

describe('http-bridge/fulfill', () => {
    beforeEach(() => {
        __resetForTests();
    });

    it('fetches every pending request and caches {status, headers, body}', async () => {
        const pending = [
            { key: 'k1', url: 'https://api.a/1', method: 'GET', headers: {}, body: null },
            { key: 'k2', url: 'https://api.b/2', method: 'POST', headers: { 'X-Auth': 'token' }, body: 'payload' },
        ];
        const php = makePhp({ [PENDING_PATH]: JSON.stringify(pending) });

        const fetchStub = spy(async (url) => {
            if (url === 'https://api.a/1') {
                return makeResponse({ status: 200, headers: { 'Content-Type': 'text/plain' }, body: 'hello' });
            }
            return makeResponse({ status: 201, headers: { 'Content-Type': 'application/json' }, body: '{"ok":true}' });
        });
        __setFetchForTests(fetchStub);

        const ok = await fulfill(php);

        assert.equal(ok, true);
        assert.equal(fetchStub.callCount, 2);

        // First call: URL passthrough + no body (GET)
        assert.equal(fetchStub.calls[0][0], 'https://api.a/1');
        const opts1 = fetchStub.calls[0][1];
        assert.equal(opts1.method, 'GET');
        assert.ok(!('body' in opts1), 'GET without body must not attach an empty body');
        // Headers omitted when map is empty
        assert.ok(!('headers' in opts1));

        // Second call: method + headers + body forwarded
        assert.equal(fetchStub.calls[1][1].method, 'POST');
        assert.deepEqual(fetchStub.calls[1][1].headers, { 'X-Auth': 'token' });
        assert.equal(fetchStub.calls[1][1].body, 'payload');

        // Cache payloads
        assert.deepEqual(JSON.parse(php.files[`${CACHE_DIR}/k1.json`]), {
            status: 200,
            headers: { 'Content-Type': 'text/plain' },
            body: 'hello',
        });
        assert.deepEqual(JSON.parse(php.files[`${CACHE_DIR}/k2.json`]), {
            status: 201,
            headers: { 'Content-Type': 'application/json' },
            body: '{"ok":true}',
        });

        assert.ok(!(PENDING_PATH in php.files), 'pending file should be removed on success');
    });

    it('attaches an AbortSignal that threads into every fetch call', async () => {
        const php = makePhp({
            [PENDING_PATH]: JSON.stringify([
                { key: 'k', url: 'https://x.test/', method: 'GET', headers: {}, body: null },
            ]),
        });
        __setFetchForTests(async (_url, options) => {
            assert.ok(options.signal instanceof AbortSignal, 'fulfill must pass an AbortSignal');
            assert.equal(options.signal.aborted, false);
            return makeResponse({ body: 'ok' });
        });

        await fulfill(php);
    });

    it('writes {status:0, error} to cache when fetch rejects with a non-abort error', async () => {
        const php = makePhp({
            [PENDING_PATH]: JSON.stringify([
                { key: 'fail', url: 'https://x.test/', method: 'GET', headers: {}, body: null },
            ]),
        });
        __setFetchForTests(async () => { throw new Error('network down'); });

        const ok = await fulfill(php);

        assert.equal(ok, true);
        const cached = JSON.parse(php.files[`${CACHE_DIR}/fail.json`]);
        assert.equal(cached.status, 0);
        assert.deepEqual(cached.headers, {});
        assert.equal(cached.body, '');
        assert.match(cached.error, /network down/);
    });

    it('returns false without writing cache when ALL fetches are aborted', async () => {
        const php = makePhp({
            [PENDING_PATH]: JSON.stringify([
                { key: 'a', url: 'https://x/', method: 'GET', headers: {}, body: null },
                { key: 'b', url: 'https://y/', method: 'GET', headers: {}, body: null },
            ]),
        });

        const abortErr = new Error('The operation was aborted');
        abortErr.name = 'AbortError';
        __setFetchForTests(async () => { throw abortErr; });

        const ok = await fulfill(php);

        assert.equal(ok, false);
        // Bridge must NOT write a cache entry for fully-aborted passes — next
        // flush replays the whole batch. The pending file is also left alone.
        assert.ok(!(`${CACHE_DIR}/a.json` in php.files));
        assert.ok(!(`${CACHE_DIR}/b.json` in php.files));
    });

    it('skips cache writes only for aborted entries, not the fulfilled ones', async () => {
        const php = makePhp({
            [PENDING_PATH]: JSON.stringify([
                { key: 'done', url: 'https://a/', method: 'GET', headers: {}, body: null },
                { key: 'cancelled', url: 'https://b/', method: 'GET', headers: {}, body: null },
            ]),
        });

        const abortErr = new Error('aborted');
        abortErr.name = 'AbortError';

        __setFetchForTests(async (url) => {
            if (url === 'https://a/') return makeResponse({ body: 'ok-a' });
            throw abortErr;
        });

        await fulfill(php);

        assert.ok(`${CACHE_DIR}/done.json` in php.files, 'fulfilled entry gets cached');
        assert.ok(!(`${CACHE_DIR}/cancelled.json` in php.files),
            'AbortError entries must be skipped, not written with error');
    });

    it('caps re-entry at MAX_RETRIES=10', async () => {
        const php = makePhp({});
        const fetchStub = spy(async () => makeResponse({ body: 'x' }));
        __setFetchForTests(fetchStub);

        for (let i = 0; i < 10; i++) {
            php.files[PENDING_PATH] = JSON.stringify([{
                key: `k${i}`, url: 'https://x/', method: 'GET', headers: {}, body: null,
            }]);
            const ok = await fulfill(php);
            assert.equal(ok, true, `pass #${i} should fire fetch`);
        }

        // 11th pass must short-circuit
        const callsBefore = fetchStub.callCount;
        php.files[PENDING_PATH] = JSON.stringify([{
            key: 'overflow', url: 'https://x/', method: 'GET', headers: {}, body: null,
        }]);
        const ok = await fulfill(php);

        assert.equal(ok, false);
        assert.equal(fetchStub.callCount, callsBefore,
            'no fetch should fire once MAX_RETRIES is reached');
    });

    it('returns false and cleans up when pending is empty / malformed', async () => {
        const fetchStub = spy(async () => makeResponse());
        __setFetchForTests(fetchStub);

        // Empty array
        let php = makePhp({ [PENDING_PATH]: JSON.stringify([]) });
        assert.equal(await fulfill(php), false);
        assert.equal(fetchStub.callCount, 0);

        __resetForTests();
        __setFetchForTests(fetchStub);

        // Non-array payload
        php = makePhp({ [PENDING_PATH]: JSON.stringify({ bogus: true }) });
        assert.equal(await fulfill(php), false);
        assert.equal(fetchStub.callCount, 0);

        __resetForTests();
        __setFetchForTests(fetchStub);

        // Unparseable JSON
        php = makePhp({ [PENDING_PATH]: '{garbage' });
        assert.equal(await fulfill(php), false);
        assert.equal(fetchStub.callCount, 0);
    });
});

// ---------------------------------------------------------------
// abort() — cancel outstanding fetches
// ---------------------------------------------------------------

describe('http-bridge/abort', () => {
    beforeEach(() => { __resetForTests(); });

    it('aborts the in-flight AbortController so pending fetches reject', async () => {
        const php = makePhp({
            [PENDING_PATH]: JSON.stringify([
                { key: 'k', url: 'https://slow/', method: 'GET', headers: {}, body: null },
            ]),
        });

        let capturedSignal = null;
        __setFetchForTests((_url, options) => new Promise((_, reject) => {
            capturedSignal = options.signal;
            options.signal.addEventListener('abort', () => {
                const e = new Error('aborted');
                e.name = 'AbortError';
                reject(e);
            });
        }));

        const fulfillPromise = fulfill(php);

        // Give fulfill() a tick to wire up the fetch promise
        await new Promise((r) => setImmediate(r));
        abort();

        const ok = await fulfillPromise;
        assert.equal(ok, false);
        assert.ok(capturedSignal && capturedSignal.aborted,
            'abort() must flip the signal that fulfill() wired into fetch');
    });

    it('resets retryCount so a subsequent fulfill can retry fresh', async () => {
        const php = makePhp({});
        __setFetchForTests(async () => makeResponse({ body: 'x' }));

        // Bump retries
        for (let i = 0; i < 5; i++) {
            php.files[PENDING_PATH] = JSON.stringify([{
                key: `k${i}`, url: 'https://x/', method: 'GET', headers: {}, body: null,
            }]);
            await fulfill(php);
        }

        abort();

        // After abort() we should have room for 10 more successful passes
        for (let i = 0; i < 10; i++) {
            php.files[PENDING_PATH] = JSON.stringify([{
                key: `post-${i}`, url: 'https://x/', method: 'GET', headers: {}, body: null,
            }]);
            const ok = await fulfill(php);
            assert.equal(ok, true, `post-abort pass #${i} should succeed`);
        }
    });
});

// ---------------------------------------------------------------
// done() — cleanup between PHP request boundaries
// ---------------------------------------------------------------

describe('http-bridge/done', () => {
    beforeEach(() => { __resetForTests(); });

    it('clears the cache dir contents', () => {
        const php = makePhp({
            [`${CACHE_DIR}/old1.json`]: '{}',
            [`${CACHE_DIR}/old2.json`]: '{}',
        });

        done(php);

        assert.ok(!(`${CACHE_DIR}/old1.json` in php.files));
        assert.ok(!(`${CACHE_DIR}/old2.json` in php.files));
    });
});
