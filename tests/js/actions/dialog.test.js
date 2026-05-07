import { describe, it, beforeEach } from 'node:test';
import assert from 'node:assert/strict';
import { alert, confirm } from '../../../js/wasm-app/actions/dialog.js';
import { makeCtx, Recorder, spy, flush } from '../helpers/ctx.js';

describe('actions/dialog', () => {
    let rec;

    beforeEach(() => {
        rec = new Recorder();
    });

    describe('alert', () => {
        it('falls back to postMessage when not in Tauri', () => {
            const ctx = makeCtx({ post: rec.fn(), isTauri: false });
            alert({ message: 'hi' }, ctx);

            assert.deepEqual(rec.calls, [{ type: 'nativeblade-alert', data: { message: 'hi' } }]);
        });

        it('uses dialogApi.message when in Tauri with the supplied title and kind', () => {
            const msg = spy(() => Promise.resolve());
            const ctx = makeCtx({ isTauri: true, dialogApi: { message: msg } });
            alert({ message: 'hello', title: 'T', kind: 'warning' }, ctx);

            assert.equal(msg.callCount, 1);
            assert.equal(msg.calls[0][0], 'hello');
            assert.deepEqual(msg.calls[0][1], { title: 'T', kind: 'warning' });
        });

        it('defaults title to "NativeBlade" and kind to "info"', () => {
            const msg = spy(() => Promise.resolve());
            const ctx = makeCtx({ isTauri: true, dialogApi: { message: msg } });
            alert({ message: 'x' }, ctx);

            assert.deepEqual(msg.calls[0][1], { title: 'NativeBlade', kind: 'info' });
        });
    });

    describe('confirm', () => {
        it('uses dialogApi.confirm and posts the result with id', async () => {
            const confirmFn = spy(() => Promise.resolve(true));
            const ctx = makeCtx({
                isTauri: true,
                dialogApi: { confirm: confirmFn },
                post: rec.fn(),
            });

            confirm({ message: 'Are you sure?', id: 'del' }, ctx);
            await flush();

            assert.equal(confirmFn.callCount, 1);
            assert.equal(confirmFn.calls[0][0], 'Are you sure?');
            assert.equal(confirmFn.calls[0][1].title, 'NativeBlade');
            assert.equal(confirmFn.calls[0][1].kind, 'warning');

            assert.deepEqual(rec.calls, [
                { type: 'nativeblade-confirm-result', data: { confirmed: true, id: 'del' } },
            ]);
        });

        it('passes confirmed=false when dialogApi resolves false', async () => {
            const ctx = makeCtx({
                isTauri: true,
                dialogApi: { confirm: () => Promise.resolve(false) },
                post: rec.fn(),
            });

            confirm({ message: 'x' }, ctx);
            await flush();

            assert.equal(rec.calls[0].data.confirmed, false);
            assert.equal(rec.calls[0].data.id, null);
        });

        it('falls back to window.confirm outside Tauri', () => {
            globalThis.window = { confirm: () => true };
            try {
                const ctx = makeCtx({ isTauri: false, post: rec.fn() });
                confirm({ message: 'x' }, ctx);
                assert.deepEqual(rec.calls, [
                    { type: 'nativeblade-confirm-result', data: { confirmed: true, id: null } },
                ]);
            } finally {
                delete globalThis.window;
            }
        });
    });
});
