import { describe, it, beforeEach } from 'node:test';
import assert from 'node:assert/strict';
import { runBridgeCycle, BRIDGE_ABORTED } from '../../../js/runtime/bridge-cycle.js';
import { createSerialQueue } from '../../../js/wasm-app/frame-request.js';
import * as httpBridge from '../../../js/runtime/http-bridge.js';
import * as dbBridge from '../../../js/runtime/db-bridge.js';
import * as fsBridge from '../../../js/runtime/fs-bridge.js';
import { makePhp } from '../helpers/php-stub.js';

const PENDING_PATH = '/tmp/__nb_http_pending.json';
const DB_PENDING_PATH = '/tmp/__nb_db_pending.json';
const FS_PENDING_PATH = '/tmp/__nb_fs_pending.json';

const delay = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

// A fetch that takes `ms` and rejects with AbortError when its signal fires,
// like the browser's.
function slowFetch(ms) {
    return (url, { signal }) => new Promise((resolve, reject) => {
        const timer = setTimeout(() => resolve({
            status: 200,
            headers: { entries: () => [] },
            text: async () => 'ok',
        }), ms);
        signal.addEventListener('abort', () => {
            clearTimeout(timer);
            const err = new Error('aborted');
            err.name = 'AbortError';
            reject(err);
        });
    });
}

/**
 * The shell's request pipeline with a fake PHP. `handleRequest` mirrors
 * request-handler.js: a request whose first run prints the HTTP sentinel starts
 * a bridge cycle and returns the `bridgePending` stub; `requestFull` mirrors
 * router.js and resolves only with the final result.
 */
function makePipeline(php, { needsHttp = () => false, bridge = httpBridge } = {}) {
    const enqueueRequest = createSerialQueue();
    const runs = [];

    async function handleRequest(path, options, onBridge) {
        runs.push(path);
        if (needsHttp(path, runs)) {
            runBridgeCycle({
                php,
                bridge,
                rerun: () => handleRequest(path, options, onBridge),
                getCallback: () => onBridge,
            });
            return { text: '', httpStatusCode: 200, bridgePending: true };
        }
        return { text: `<html>${path}</html>`, httpStatusCode: 200 };
    }

    function requestFull(path, options = {}) {
        return enqueueRequest(() => new Promise((resolve) => {
            let settled = false;
            const done = (r) => { if (settled) return; settled = true; resolve(r); };
            handleRequest(path, options, done).then(
                (result) => { if (!result || !result.bridgePending) done(result); },
                (err) => done({ text: String(err && err.message || err), httpStatusCode: 500 })
            );
        }));
    }

    return { requestFull, runs };
}

describe('runtime/bridge-cycle', () => {
    beforeEach(() => {
        httpBridge.__resetForTests();
    });

    it('delivers the re-run result when the bridge is fulfilled', async () => {
        const php = makePhp({
            [PENDING_PATH]: JSON.stringify([{ key: 'k', url: 'https://api.test/', method: 'GET' }]),
        });
        httpBridge.__setFetchForTests(slowFetch(5));
        const delivered = [];

        await runBridgeCycle({
            php,
            bridge: httpBridge,
            rerun: async () => ({ text: '<html>done</html>', httpStatusCode: 200 }),
            getCallback: () => (r) => delivered.push(r),
        });

        assert.deepEqual(delivered, [{ text: '<html>done</html>', httpStatusCode: 200 }]);
        assert.ok(`/tmp/__nb_http_cache/k.json` in php.files, 'the response was cached for the re-run');
    });

    it('delivers an aborted result instead of leaving the request unresolved', async () => {
        const php = makePhp({
            [PENDING_PATH]: JSON.stringify([{ key: 'k', url: 'https://api.test/slow', method: 'GET' }]),
        });
        httpBridge.__setFetchForTests(slowFetch(300));
        const delivered = [];
        let reran = false;

        const cycle = runBridgeCycle({
            php,
            bridge: httpBridge,
            rerun: async () => { reran = true; return {}; },
            getCallback: () => (r) => delivered.push(r),
        });
        await delay(20);
        httpBridge.abort();
        await cycle;

        assert.deepEqual(delivered, [BRIDGE_ABORTED]);
        assert.equal(reran, false, 'PHP is not re-run for a request nobody wants any more');
    });

    it('reads the completion callback after the cycle, so a callback swapped meanwhile is honoured', async () => {
        const php = makePhp({
            [PENDING_PATH]: JSON.stringify([{ key: 'k', url: 'https://api.test/', method: 'GET' }]),
        });
        httpBridge.__setFetchForTests(slowFetch(20));
        const first = [];
        const second = [];
        let current = (r) => first.push(r);

        const cycle = runBridgeCycle({
            php,
            bridge: httpBridge,
            rerun: async () => ({ text: 'x', httpStatusCode: 200 }),
            getCallback: () => current,
        });
        current = (r) => second.push(r);
        await cycle;

        assert.equal(first.length, 0);
        assert.equal(second.length, 1);
    });

    // The reported scenario: a page is waiting on a 300 ms HTTP call, the user
    // navigates away after 100 ms (which aborts the bridge), and the next page
    // never rendered because the serial request queue was parked behind the
    // abandoned request.
    it('lets the next request run after a navigation aborts an in-flight bridge', async () => {
        const php = makePhp({
            [PENDING_PATH]: JSON.stringify([{ key: 'k', url: 'https://api.test/slow', method: 'GET' }]),
        });
        httpBridge.__setFetchForTests(slowFetch(300));
        const { requestFull, runs } = makePipeline(php, {
            needsHttp: (path, all) => path === '/question' && all.filter((p) => p === '/question').length === 1,
        });

        const question = requestFull('/question');
        await delay(100);
        httpBridge.abort();                     // what navigateInternal() does
        const home = requestFull('/home');

        const results = await Promise.race([
            Promise.all([question, home]),
            delay(1500).then(() => 'timed out'),
        ]);

        assert.notEqual(results, 'timed out', 'the queue must advance after the abort');
        const [questionResult, homeResult] = results;
        assert.equal(questionResult.aborted, true);
        assert.equal(homeResult.text, '<html>/home</html>');
        assert.deepEqual(runs, ['/question', '/home'], 'the aborted request is not re-run');
    });

    it('also unblocks the queue when the bridge gives up for another reason', async () => {
        // Unreadable pending file: fulfill() returns false without an abort.
        const php = makePhp({ [PENDING_PATH]: 'not json' });
        const { requestFull } = makePipeline(php, {
            needsHttp: (path, all) => path === '/broken' && all.length === 1,
        });

        const broken = requestFull('/broken');
        const next = requestFull('/next');

        const results = await Promise.race([
            Promise.all([broken, next]),
            delay(1500).then(() => 'timed out'),
        ]);

        assert.notEqual(results, 'timed out');
        assert.equal(results[0].aborted, true);
        assert.equal(results[1].text, '<html>/next</html>');
    });

    // The DB and FS bridges have no abort(), so a navigation never cancels
    // them, but every path where their fulfill() gives up (retry budget, an
    // unreadable pending file, no Tauri host) went through the same
    // fulfillInBackground and parked the queue just the same.
    for (const [name, bridge, pendingPath, reset] of [
        ['db', dbBridge, DB_PENDING_PATH, dbBridge.__resetForTests],
        ['fs', fsBridge, FS_PENDING_PATH, fsBridge.__resetForTests],
    ]) {
        it(`unblocks the queue when the ${name} bridge gives up`, async () => {
            reset?.();
            // Outside Tauri (as in the browser preview, and in this test runner)
            // the native driver is unavailable and fulfill() returns false.
            const php = makePhp({
                [pendingPath]: JSON.stringify([{ key: 'k', op: 'read', path: 'x', type: 'select', sql: 'select 1', bindings: [] }]),
            });
            const { requestFull } = makePipeline(php, {
                bridge,
                needsHttp: (path, all) => path === '/data' && all.length === 1,
            });

            const data = requestFull('/data');
            const next = requestFull('/next');

            const results = await Promise.race([
                Promise.all([data, next]),
                delay(1500).then(() => 'timed out'),
            ]);

            assert.notEqual(results, 'timed out', `the ${name} bridge giving up must not park the queue`);
            assert.equal(results[0].aborted, true);
            assert.equal(results[1].text, '<html>/next</html>');
        });
    }
});
