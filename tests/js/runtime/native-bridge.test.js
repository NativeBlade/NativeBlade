import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { createBridgeHandler, installNativeBridge, EXECUTORS } from '../../../js/runtime/native-bridge.js';

const parse = (s) => JSON.parse(s);

describe('native-bridge', () => {
    it('answers a NativeBlade message with the executor result', async () => {
        const handle = createBridgeHandler({ http: async (m) => ({ status: 200, body: 'for ' + m.url }) });

        const reply = parse(await handle(JSON.stringify({ nativeblade: 'http', url: 'https://a' })));

        assert.deepEqual(reply, { ok: true, result: { status: 200, body: 'for https://a' } });
    });

    it('reports an executor failure instead of throwing into PHP', async () => {
        const handle = createBridgeHandler({ db: async () => { throw new Error('no such table'); } });

        const reply = parse(await handle(JSON.stringify({ nativeblade: 'db', sql: 'x' })));

        assert.deepEqual(reply, { ok: false, error: 'no such table' });
    });

    it('reports an unknown call type', async () => {
        const handle = createBridgeHandler({});
        const reply = parse(await handle(JSON.stringify({ nativeblade: 'teleport' })));
        assert.equal(reply.ok, false);
        assert.match(reply.error, /Unknown native call 'teleport'/);
    });

    it('leaves messages that are not NativeBlade\'s unanswered', async () => {
        const handle = createBridgeHandler({ http: async () => 'x' });
        assert.equal(await handle('not json'), '');
        assert.equal(await handle(JSON.stringify({ post_id: 15 })), '');
        assert.equal(await handle(JSON.stringify(null)), '');
    });

    it('an undefined result still answers ok, as null on the PHP side', async () => {
        const handle = createBridgeHandler({ fs: async () => undefined });
        const reply = parse(await handle(JSON.stringify({ nativeblade: 'fs' })));
        assert.deepEqual(reply, { ok: true });
    });

    it('hands the context (the php instance) to the executor', async () => {
        const php = { onMessage() {} };
        let seen;
        const handle = createBridgeHandler({ http: async (_m, context) => { seen = context; return 1; } }, { php });

        await handle(JSON.stringify({ nativeblade: 'http' }));

        assert.equal(seen.php, php);
    });

    it('installs once per php instance', () => {
        const listeners = [];
        const php = { onMessage: (fn) => listeners.push(fn) };

        installNativeBridge(php);
        installNativeBridge(php);
        installNativeBridge(null);
        installNativeBridge({});

        assert.equal(listeners.length, 1);
        assert.equal(typeof listeners[0], 'function');
    });

    it('ships executors for http, http_pool, db and fs', () => {
        assert.deepEqual(Object.keys(EXECUTORS).sort(), ['db', 'fs', 'http', 'http_pool']);
    });
});
