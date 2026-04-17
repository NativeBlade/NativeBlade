import { describe, it, beforeEach } from 'node:test';
import assert from 'node:assert/strict';
import {
    hasPendingRequest,
    fulfill,
    done,
    __setInvokeForTests,
    __resetForTests,
} from '../../../js/runtime/db-bridge.js';
import { makePhp } from '../helpers/php-stub.js';
import { spy } from '../helpers/ctx.js';

const PENDING_PATH = '/tmp/__nb_db_pending.json';
const CACHE_DIR = '/tmp/__nb_db_cache';

// ---------------------------------------------------------------
// hasPendingRequest — pure string-sniff, does not touch php.
// ---------------------------------------------------------------

describe('db-bridge/hasPendingRequest', () => {
    it('returns true when the output contains the sentinel marker', async () => {
        const php = makePhp();
        const res = await hasPendingRequest(php, 'prefix __NB_DB_PENDING__ suffix');
        assert.equal(res, true);
    });

    it('returns false when the marker is absent', async () => {
        const res = await hasPendingRequest(makePhp(), 'normal stdout with no marker');
        assert.equal(res, false);
    });

    it('returns false for non-string output (null, undefined, objects)', async () => {
        const php = makePhp();
        assert.equal(await hasPendingRequest(php, null), false);
        assert.equal(await hasPendingRequest(php, undefined), false);
        assert.equal(await hasPendingRequest(php, { s: 'x' }), false);
        assert.equal(await hasPendingRequest(php, 42), false);
    });
});

// ---------------------------------------------------------------
// fulfill — happy path + every cleanup branch.
// ---------------------------------------------------------------

describe('db-bridge/fulfill', () => {
    beforeEach(() => {
        __resetForTests();
    });

    it('invokes db_query once per pending entry and writes {result} to cache', async () => {
        const pending = [
            {
                key: 'abc123',
                type: 'select',
                sql: 'select * from users where id = ?',
                bindings: [1],
                driver: 'mysql',
                connection: 'mysql://u:p@localhost/db',
            },
            {
                key: 'def456',
                type: 'insert',
                sql: 'insert into users (name) values (?)',
                bindings: ['Alice'],
                driver: 'sqlite',
                connection: ':memory:',
            },
        ];

        const php = makePhp({ [PENDING_PATH]: JSON.stringify(pending) });
        const invoke = spy(async () => [{ id: 1 }]);
        __setInvokeForTests(invoke);

        const ok = await fulfill(php);

        assert.equal(ok, true);
        assert.equal(invoke.callCount, 2);

        // First invocation: correct command name + payload
        assert.equal(invoke.calls[0][0], 'db_query');
        assert.deepEqual(invoke.calls[0][1], {
            driver: 'mysql',
            connection: 'mysql://u:p@localhost/db',
            queryType: 'select',
            sql: 'select * from users where id = ?',
            bindings: [1],
        });

        // Cache file per key, wrapping the invoke result in {result}
        assert.ok(`${CACHE_DIR}/abc123.json` in php.files);
        assert.ok(`${CACHE_DIR}/def456.json` in php.files);
        assert.deepEqual(
            JSON.parse(php.files[`${CACHE_DIR}/abc123.json`]),
            { result: [{ id: 1 }] },
        );

        // Pending file is removed so the next PHP boot doesn't re-run
        assert.ok(!(PENDING_PATH in php.files));
    });

    it('maps pending.type → queryType in the invoke payload', async () => {
        const php = makePhp({
            [PENDING_PATH]: JSON.stringify([{
                key: 'k', type: 'statement', sql: 'BEGIN', bindings: [],
                driver: 'mysql', connection: 'x',
            }]),
        });
        const invoke = spy(async () => ({ affected: 0 }));
        __setInvokeForTests(invoke);

        await fulfill(php);

        assert.equal(invoke.calls[0][1].queryType, 'statement',
            'the JS bridge must rename type→queryType for the Tauri command');
    });

    it('writes {result: {error: ...}} to cache when invoke throws', async () => {
        const php = makePhp({
            [PENDING_PATH]: JSON.stringify([{
                key: 'boom', type: 'select', sql: 'select 1', bindings: [],
                driver: 'mysql', connection: 'x',
            }]),
        });
        __setInvokeForTests(async () => { throw new Error('sqlx bind failed'); });

        const ok = await fulfill(php);

        assert.equal(ok, true, 'one bad entry does not abort the whole pass');
        const cached = JSON.parse(php.files[`${CACHE_DIR}/boom.json`]);
        assert.ok(cached.result, 'cache has a result envelope');
        assert.ok(
            typeof cached.result.error === 'string' && cached.result.error.includes('sqlx bind failed'),
            `expected error string in cache, got ${JSON.stringify(cached)}`,
        );
    });

    it('returns false and cleans up when the pending file is missing', async () => {
        const php = makePhp(); // no pending file
        __setInvokeForTests(spy(async () => null));

        const ok = await fulfill(php);

        assert.equal(ok, false);
    });

    it('returns false and cleans up when pending JSON is not an array', async () => {
        const php = makePhp({ [PENDING_PATH]: JSON.stringify({ notAnArray: true }) });
        const invoke = spy(async () => null);
        __setInvokeForTests(invoke);

        const ok = await fulfill(php);

        assert.equal(ok, false);
        assert.equal(invoke.callCount, 0, 'invoke must not run for malformed pending');
        assert.ok(php.state.unlinkCalls.includes(PENDING_PATH),
            'cleanup() must unlink the stale pending file');
    });

    it('returns false and cleans up when pending list is empty', async () => {
        const php = makePhp({ [PENDING_PATH]: JSON.stringify([]) });
        const invoke = spy(async () => null);
        __setInvokeForTests(invoke);

        const ok = await fulfill(php);

        assert.equal(ok, false);
        assert.equal(invoke.callCount, 0);
    });

    it('returns false and cleans up when pending JSON is syntactically invalid', async () => {
        const php = makePhp({ [PENDING_PATH]: 'not-json-at-all' });
        __setInvokeForTests(spy(async () => null));

        const ok = await fulfill(php);
        assert.equal(ok, false);
    });

    it('caps re-entry at MAX_RETRIES=20 to avoid infinite loops', async () => {
        const php = makePhp({
            [PENDING_PATH]: JSON.stringify([{
                key: 'k', type: 'select', sql: 'x', bindings: [],
                driver: 'mysql', connection: 'c',
            }]),
        });
        const invoke = spy(async () => null);
        __setInvokeForTests(invoke);

        // Simulate 20 legitimate passes (PHP keeps re-writing pending.json)
        for (let i = 0; i < 20; i++) {
            // Restore pending since fulfill() deletes it after success
            php.files[PENDING_PATH] = JSON.stringify([{
                key: `k${i}`, type: 'select', sql: 'x', bindings: [],
                driver: 'mysql', connection: 'c',
            }]);
            const ok = await fulfill(php);
            assert.equal(ok, true, `pass #${i} should succeed`);
        }

        // 21st call must short-circuit without invoking
        php.files[PENDING_PATH] = JSON.stringify([{
            key: 'overflow', type: 'select', sql: 'x', bindings: [],
            driver: 'mysql', connection: 'c',
        }]);
        const invokeCountBefore = invoke.callCount;
        const ok = await fulfill(php);

        assert.equal(ok, false);
        assert.equal(invoke.callCount, invokeCountBefore,
            'invoke must not fire on the 21st pass');
    });

    it('creates the cache dir via mkdirTree before writing', async () => {
        const php = makePhp({
            [PENDING_PATH]: JSON.stringify([{
                key: 'k', type: 'select', sql: 'x', bindings: [],
                driver: 'mysql', connection: 'c',
            }]),
        });
        __setInvokeForTests(async () => []);

        await fulfill(php);

        assert.ok(
            php.state.mkdirCalls.includes(CACHE_DIR),
            'must call mkdirTree(CACHE_DIR) so the first writeFile does not fail on a missing dir',
        );
    });
});

// ---------------------------------------------------------------
// done() — cleanup between PHP request boundaries.
// ---------------------------------------------------------------

describe('db-bridge/done', () => {
    beforeEach(() => { __resetForTests(); });

    it('resets the retry counter so a fresh request can re-use the bridge', async () => {
        // Trip retryCount to a high value
        const php = makePhp({ [PENDING_PATH]: JSON.stringify([]) });
        for (let i = 0; i < 5; i++) {
            php.files[PENDING_PATH] = JSON.stringify([]);
            await fulfill(php);
        }

        done(php);

        // After done(), a fresh fulfill() with a valid pending should succeed
        php.files[PENDING_PATH] = JSON.stringify([{
            key: 'fresh', type: 'select', sql: 'x', bindings: [],
            driver: 'mysql', connection: 'c',
        }]);
        __setInvokeForTests(async () => []);
        const ok = await fulfill(php);
        assert.equal(ok, true);
    });

    it('clears the cache directory via listFiles + unlink', async () => {
        const php = makePhp({
            [`${CACHE_DIR}/a.json`]: '{}',
            [`${CACHE_DIR}/b.json`]: '{}',
        });

        done(php);

        // Both cached files should be unlinked
        assert.ok(!( `${CACHE_DIR}/a.json` in php.files));
        assert.ok(!( `${CACHE_DIR}/b.json` in php.files));
    });
});
