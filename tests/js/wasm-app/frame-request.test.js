import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { serveFrameRequest, createSerialQueue } from '../../../js/wasm-app/frame-request.js';
import { spy, flush } from '../helpers/ctx.js';

const noop = () => {};

// A fake frame: records the response payloads posted back to it.
function makeSource() {
    const messages = [];
    return {
        messages,
        postMessage(msg) {
            messages.push({ id: msg.id, type: msg.type, result: msg.result });
        },
    };
}

describe('frame-request/createSerialQueue', () => {
    it('runs tasks in FIFO order', async () => {
        const enqueue = createSerialQueue();
        const order = [];
        const mk = (n) => () => Promise.resolve().then(() => { order.push(n); });

        await Promise.all([enqueue(mk(1)), enqueue(mk(2)), enqueue(mk(3))]);

        assert.deepEqual(order, [1, 2, 3]);
    });

    it('does not start a task until the previous one has settled', async () => {
        const enqueue = createSerialQueue();
        const events = [];
        let releaseA;

        const a = () => new Promise((resolve) => {
            events.push('start:a');
            releaseA = () => { events.push('end:a'); resolve('A'); };
        });
        const b = () => { events.push('start:b'); return Promise.resolve('B'); };

        const pa = enqueue(a);
        const pb = enqueue(b);

        await flush();
        assert.deepEqual(events, ['start:a'], 'b must not start while a is running');

        releaseA();
        assert.equal(await pa, 'A');
        await flush();
        assert.deepEqual(events, ['start:a', 'end:a', 'start:b']);
        assert.equal(await pb, 'B');
    });

    it('keeps running after a task rejects', async () => {
        const enqueue = createSerialQueue();
        const order = [];

        const p1 = enqueue(() => Promise.reject(new Error('boom')));
        const p2 = enqueue(() => { order.push('after'); return Promise.resolve('ok'); });

        await assert.rejects(p1, /boom/);
        assert.equal(await p2, 'ok');
        assert.deepEqual(order, ['after']);
    });
});

describe('frame-request/serveFrameRequest', () => {
    it('delivers requestFull result to its own source', async () => {
        const src = makeSource();
        const requestFull = async () => ({ text: 'HTML', httpStatusCode: 200 });

        await serveFrameRequest({
            id: 'x', path: '/x', options: {}, source: src,
            requestFull, schedulePersist: noop, getGeneration: () => 0,
        });

        assert.equal(src.messages.length, 1);
        assert.equal(src.messages[0].id, 'x');
        assert.equal(src.messages[0].result.text, 'HTML');
        assert.equal(src.messages[0].result.httpStatusCode, 200);
    });

    it('drops a delivery whose navigation generation is stale', async () => {
        const src = makeSource();
        let generation = 1;
        // Simulate the user navigating while the request is in flight.
        const requestFull = async () => { generation = 2; return { text: 'stale', httpStatusCode: 200 }; };

        await serveFrameRequest({
            id: 'x', path: '/x', options: {}, source: src,
            requestFull, schedulePersist: noop, getGeneration: () => generation,
        });

        assert.equal(src.messages.length, 0, 'a post-navigation delivery must be dropped');
    });

    it('persists after a non-GET success, but not a GET nor a 500', async () => {
        const persist = spy();
        const src = makeSource();
        const ok = async () => ({ text: 'ok', httpStatusCode: 200 });
        const fail = async () => ({ text: 'no', httpStatusCode: 500 });
        const base = { source: src, schedulePersist: persist, getGeneration: () => 0 };

        await serveFrameRequest({ ...base, id: '1', path: '/a', options: { method: 'POST' }, requestFull: ok });
        assert.equal(persist.callCount, 1, 'POST 200 persists');

        await serveFrameRequest({ ...base, id: '2', path: '/b', options: { method: 'GET' }, requestFull: ok });
        assert.equal(persist.callCount, 1, 'GET does not persist');

        await serveFrameRequest({ ...base, id: '3', path: '/c', options: { method: 'POST' }, requestFull: fail });
        assert.equal(persist.callCount, 1, 'a 500 does not persist');
    });

    it('delivers a 500 when requestFull rejects', async () => {
        const src = makeSource();
        const requestFull = async () => { throw new Error('boom'); };

        await serveFrameRequest({
            id: 'e', path: '/e', options: {}, source: src,
            requestFull, schedulePersist: noop, getGeneration: () => 0,
        });

        assert.equal(src.messages.length, 1);
        assert.equal(src.messages[0].result.httpStatusCode, 500);
        assert.match(src.messages[0].result.text, /boom/);
    });

    it('serializes overlapping frame requests and delivers each to its own source', async () => {
        // Build a real serialized requestFull over a controllable fake request()
        // that stays bridge-pending until released — the same shape as the router.
        const enqueue = createSerialQueue();
        const events = [];
        const releases = {};
        const request = (path, _options, done) => {
            events.push(`start:${path}`);
            releases[path] = () => { events.push(`end:${path}`); done({ text: `R:${path}`, httpStatusCode: 200 }); };
            return Promise.resolve({ bridgePending: true });
        };
        const requestFull = (path, options) => enqueue(() => new Promise((resolve) => {
            let settled = false;
            const done = (r) => { if (!settled) { settled = true; resolve(r); } };
            request(path, options, done);
        }));

        const src1 = makeSource();
        const src2 = makeSource();
        const gen = () => 0;

        serveFrameRequest({ id: 'a', path: '/a', options: {}, source: src1, requestFull, schedulePersist: noop, getGeneration: gen });
        serveFrameRequest({ id: 'b', path: '/b', options: {}, source: src2, requestFull, schedulePersist: noop, getGeneration: gen });

        await flush();
        // Only A has started; B is queued behind it (no interleaving).
        assert.deepEqual(events, ['start:/a']);
        assert.equal(src1.messages.length, 0);

        releases['/a']();
        await flush();
        // A delivered to src1; only now does B start.
        assert.deepEqual(events, ['start:/a', 'end:/a', 'start:/b']);
        assert.equal(src1.messages[0].result.text, 'R:/a');
        assert.equal(src2.messages.length, 0);

        releases['/b']();
        await flush();
        assert.equal(src2.messages[0].result.text, 'R:/b');
        assert.deepEqual(events, ['start:/a', 'end:/a', 'start:/b', 'end:/b']);
    });
});
