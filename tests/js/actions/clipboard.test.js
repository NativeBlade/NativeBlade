import { describe, it, beforeEach } from 'node:test';
import assert from 'node:assert/strict';
import { clipboard_read, clipboard_write } from '../../../js/wasm-app/actions/clipboard.js';
import { makeCtx, Recorder, spy, flush } from '../helpers/ctx.js';

describe('actions/clipboard', () => {
    describe('clipboard_write', () => {
        it('forwards the text to writeText', () => {
            const wt = spy();
            clipboard_write({ text: 'hello' }, makeCtx({ clipboardApi: { writeText: wt } }));

            assert.deepEqual(wt.calls[0], ['hello']);
        });

        it('defaults missing text to an empty string', () => {
            const wt = spy();
            clipboard_write({}, makeCtx({ clipboardApi: { writeText: wt } }));

            assert.deepEqual(wt.calls[0], ['']);
        });

        it('is a no-op when clipboardApi is unavailable', () => {
            assert.doesNotThrow(() => clipboard_write({ text: 'x' }, makeCtx({ clipboardApi: null })));
        });
    });

    describe('clipboard_read', () => {
        let rec;
        beforeEach(() => { rec = new Recorder(); });

        it('reads text and posts it with id=null when no id is given', async () => {
            const ctx = makeCtx({
                clipboardApi: { readText: () => Promise.resolve('pasted') },
                post: rec.fn(),
            });

            clipboard_read({}, ctx);
            await flush();

            assert.deepEqual(rec.calls, [
                { type: 'nativeblade-clipboard', data: { text: 'pasted', id: null } },
            ]);
        });

        it('forwards the id when provided', async () => {
            const ctx = makeCtx({
                clipboardApi: { readText: () => Promise.resolve('x') },
                post: rec.fn(),
            });

            clipboard_read({ id: 'target' }, ctx);
            await flush();

            assert.equal(rec.calls[0].data.id, 'target');
        });

        it('is a no-op when clipboardApi is unavailable', () => {
            assert.doesNotThrow(() => clipboard_read({}, makeCtx({ clipboardApi: null })));
        });
    });
});
