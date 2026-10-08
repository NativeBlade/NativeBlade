import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { createReplayDetector, readPending, describeCall, formatDivergence, shortHash } from '../../../js/runtime/replay-detector.js';

const http = (index, method, url) => ({ key: `${method}|${url}|${index}`, index, method, url });
const db = (index, sql, bindings = []) => ({ key: `${sql}|${JSON.stringify(bindings)}|${index}`, index, type: 'select', sql, bindings });

describe('replay-detector', () => {
    it('identical call sequences across runs produce no findings', () => {
        const d = createReplayDetector();
        assert.deepEqual(d.track('http', [http(0, 'GET', 'https://a/x')]), []);
        // Run 2 replays call 0 from cache and asks for call 1; run 3 replays both.
        assert.deepEqual(d.track('http', [http(1, 'POST', 'https://a/y')]), []);
        assert.deepEqual(d.track('db', [db(0, 'select 1')]), []);
    });

    it('reports the exact call whose content changed between runs', () => {
        const d = createReplayDetector();
        d.track('http', [http(0, 'GET', 'https://a/x?nonce=1')]);
        const findings = d.track('http', [http(0, 'GET', 'https://a/x?nonce=2')]);
        assert.deepEqual(findings, [{
            type: 'http',
            index: 0,
            was: 'GET https://a/x?nonce=1',
            now: 'GET https://a/x?nonce=2',
        }]);
    });

    it('tracks http, db and fs indexes independently', () => {
        const d = createReplayDetector();
        d.track('http', [http(0, 'GET', 'https://a/x')]);
        d.track('db', [db(0, 'select 1')]);
        assert.deepEqual(d.track('db', [db(0, 'select 2')]).map(f => f.type), ['db']);
        assert.deepEqual(d.track('http', [http(0, 'GET', 'https://a/x')]), []);
    });

    it('handles a pool of several http calls in one run', () => {
        const d = createReplayDetector();
        d.track('http', [http(0, 'GET', 'https://a/1'), http(1, 'GET', 'https://a/2')]);
        const findings = d.track('http', [http(0, 'GET', 'https://a/1'), http(1, 'GET', 'https://a/changed')]);
        assert.equal(findings.length, 1);
        assert.equal(findings[0].index, 1);
    });

    it('reset forgets the previous request so a new request never compares against it', () => {
        const d = createReplayDetector();
        d.track('http', [http(0, 'GET', 'https://a/x')]);
        d.reset();
        assert.deepEqual(d.track('http', [http(0, 'GET', 'https://a/other')]), []);
    });

    it('ignores malformed entries and non-array input', () => {
        const d = createReplayDetector();
        assert.deepEqual(d.track('http', null), []);
        assert.deepEqual(d.track('http', [null, { key: 'k' }, { index: 0 }]), []);
        assert.deepEqual(d.track('http', [{ key: 'other', url: 'x' }]), []);
    });

    it('readPending returns [] when the file is missing or not a list', () => {
        assert.deepEqual(readPending({ readFileAsText() { throw new Error('ENOENT'); } }, '/tmp/x'), []);
        assert.deepEqual(readPending({ readFileAsText: () => '{"a":1}' }, '/tmp/x'), []);
        assert.deepEqual(readPending({ readFileAsText: () => '[{"key":"k","index":0}]' }, '/tmp/x'), [{ key: 'k', index: 0 }]);
    });

    it('describes calls per bridge type', () => {
        assert.equal(describeCall('http', { method: 'POST', url: 'https://a' }), 'POST https://a');
        assert.equal(describeCall('db', { type: 'insert', sql: 'insert into t' }), 'insert insert into t');
        assert.equal(describeCall('db', { type: 'update', sql: 'update t set a = ? where id = ?', bindings: [0, 7] }), 'update update t set a = ? where id = ? [0,7]');
        assert.equal(describeCall('db', { sql: 'x'.repeat(200) }).length <= 'query '.length + 120, true);
        assert.equal(describeCall('fs', { op: 'read', baseDir: 'app', path: 'a.txt' }), 'read app:a.txt');
    });

    it('tells two POSTs to the same URL apart by the body hash', () => {
        const a = describeCall('http', { method: 'POST', url: 'https://a/drafts', body: btoa('{"id":1}') });
        const b = describeCall('http', { method: 'POST', url: 'https://a/drafts', body: btoa('{"id":2}') });
        assert.match(a, /^POST https:\/\/a\/drafts body#[0-9a-f]{8}$/);
        assert.notEqual(a, b);
        assert.equal(shortHash('x'), shortHash('x'));
    });

    it('formats a divergence as a one-based, human readable message', () => {
        const message = formatDivergence({ type: 'db', index: 2, was: 'select a', now: 'select b' });
        assert.match(message, /query #3 changed between runs/);
        assert.match(message, /Was "select a", now "select b"/);
        assert.match(message, /not deterministic/);
    });
});
