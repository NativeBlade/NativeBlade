import { describe, it, beforeEach } from 'node:test';
import assert from 'node:assert/strict';
import { execute, executePool, abort, beginRequest, __setFetchForTests, __resetForTests } from '../../../js/runtime/http-bridge.js';

function fakeResponse(status, body, headers = {}) {
    return {
        status,
        headers: { entries: () => Object.entries(headers) },
        text: async () => body,
    };
}

describe('http-bridge', () => {
    beforeEach(() => __resetForTests());

    it('fetches with method, headers and decoded body and returns status, headers and body', async () => {
        const calls = [];
        __setFetchForTests(async (url, options) => {
            calls.push([url, options]);
            return fakeResponse(201, '{"ok":true}', { 'content-type': 'application/json' });
        });

        const result = await execute({
            nativeblade: 'http',
            method: 'POST',
            url: 'https://api.test/items',
            headers: { 'Content-Type': 'application/json' },
            body: btoa('{"name":"x"}'),
        });

        assert.equal(calls[0][0], 'https://api.test/items');
        assert.equal(calls[0][1].method, 'POST');
        assert.deepEqual(calls[0][1].headers, { 'Content-Type': 'application/json' });
        assert.equal(new TextDecoder().decode(calls[0][1].body), '{"name":"x"}');
        assert.ok(calls[0][1].signal, 'an abort signal rides along');
        assert.deepEqual(result, { status: 201, headers: { 'content-type': 'application/json' }, body: '{"ok":true}' });
    });

    it('omits headers and body when the request has none', async () => {
        let options;
        __setFetchForTests(async (_url, o) => { options = o; return fakeResponse(200, ''); });

        await execute({ method: 'GET', url: 'https://a.test/x', headers: {}, body: null });

        assert.equal(options.headers, undefined);
        assert.equal(options.body, undefined);
    });

    it('a failed fetch is a status 0 result with the error, never a rejection', async () => {
        __setFetchForTests(async () => { throw new TypeError('Failed to fetch'); });

        const result = await execute({ method: 'GET', url: 'https://down.test/' });

        assert.deepEqual(result, { status: 0, headers: {}, body: '', error: 'Failed to fetch' });
    });

    it('abort() cancels the fetch in flight and reads as aborted', async () => {
        __setFetchForTests((_url, options) => new Promise((_resolve, reject) => {
            options.signal.addEventListener('abort', () => {
                const err = new Error('aborted'); err.name = 'AbortError'; reject(err);
            });
        }));

        const pending = execute({ method: 'GET', url: 'https://slow.test/' });
        abort();
        const result = await pending;

        assert.equal(result.status, 0);
        assert.equal(result.error, 'aborted');
    });

    it('after abort() every later call of the same request fails at once, until the next request begins', async () => {
        let fetches = 0;
        __setFetchForTests(async () => { fetches++; return fakeResponse(200, 'ok'); });

        abort();
        const result = await execute({ method: 'GET', url: 'https://a.test/after-abort' });
        assert.deepEqual(result, { status: 0, headers: {}, body: '', error: 'aborted' });
        assert.equal(fetches, 0, 'no fetch is started for a request the user left');

        beginRequest();
        const next = await execute({ method: 'GET', url: 'https://a.test/next' });
        assert.equal(next.status, 200);
        assert.equal(fetches, 1);
    });

    it('reads a large body from the file PHP wrote and removes it', async () => {
        let options;
        __setFetchForTests(async (_url, o) => { options = o; return fakeResponse(200, ''); });
        const files = { '/tmp/__nb_http_body/1-1': new Uint8Array([1, 2, 3]) };
        const php = {
            readFileAsBuffer: (path) => { if (!(path in files)) throw new Error('ENOENT'); return files[path]; },
            unlink: (path) => { delete files[path]; },
        };

        const result = await execute({ method: 'POST', url: 'https://up.test/', bodyFile: '/tmp/__nb_http_body/1-1' }, { php });

        assert.equal(result.status, 200);
        assert.deepEqual([...options.body], [1, 2, 3]);
        assert.deepEqual(files, {}, 'the body file is removed after the fetch');
    });

    it('a body file without a php instance is a failed response, not a crash', async () => {
        __setFetchForTests(async () => fakeResponse(200, ''));
        const result = await execute({ method: 'POST', url: 'https://up.test/', bodyFile: '/tmp/x' });
        assert.equal(result.status, 0);
        assert.match(result.error, /no php instance/);
    });

    it('a pool runs its requests together and keeps their order', async () => {
        const started = [];
        __setFetchForTests(async (url) => {
            started.push(url);
            await new Promise((r) => setTimeout(r, url.endsWith('1') ? 20 : 1));
            return fakeResponse(200, 'for ' + url);
        });

        const results = await executePool({ requests: [
            { method: 'GET', url: 'https://a.test/1' },
            { method: 'GET', url: 'https://a.test/2' },
        ] });

        assert.deepEqual(started, ['https://a.test/1', 'https://a.test/2'], 'both started before either finished');
        assert.deepEqual(results.map((r) => r.body), ['for https://a.test/1', 'for https://a.test/2']);
    });

    it('an empty pool resolves to an empty list', async () => {
        assert.deepEqual(await executePool({}), []);
    });
});
