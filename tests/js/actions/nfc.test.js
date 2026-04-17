import { describe, it, beforeEach } from 'node:test';
import assert from 'node:assert/strict';
import { nfc_read } from '../../../js/wasm-app/actions/nfc.js';
import { makeCtx, Recorder } from '../helpers/ctx.js';

function makeNfcApi({ available = true, tag = { id: '1', records: [] } } = {}) {
    return {
        isAvailable: () => Promise.resolve(available),
        scan: () => Promise.resolve(tag),
    };
}

describe('actions/nfc', () => {
    let rec;
    beforeEach(() => { rec = new Recorder(); });

    it('is a no-op when the API is unavailable', async () => {
        await nfc_read({}, makeCtx({ nfcApi: null, post: rec.fn() }));
        assert.equal(rec.calls.length, 0);
    });

    it('does nothing when isAvailable reports false', async () => {
        const ctx = makeCtx({ nfcApi: makeNfcApi({ available: false }), post: rec.fn() });
        await nfc_read({}, ctx);
        assert.equal(rec.calls.length, 0);
    });

    it('posts the scanned tag with the id from payload', async () => {
        const tag = { id: 'abc', records: [{ payload: 'hi' }] };
        const ctx = makeCtx({ nfcApi: makeNfcApi({ tag }), post: rec.fn() });
        await nfc_read({ id: 'ticket' }, ctx);

        assert.deepEqual(rec.calls, [
            { type: 'nativeblade-nfc', data: { tag, id: 'ticket' } },
        ]);
    });

    it('uses id=null when no id is given', async () => {
        const ctx = makeCtx({ nfcApi: makeNfcApi(), post: rec.fn() });
        await nfc_read({}, ctx);
        assert.equal(rec.calls[0].data.id, null);
    });

    it('swallows errors thrown by the underlying API', async () => {
        const ctx = makeCtx({
            nfcApi: { isAvailable: () => { throw new Error('boom'); }, scan: () => Promise.resolve({}) },
            post: rec.fn(),
        });
        await assert.doesNotReject(() => nfc_read({}, ctx));
        assert.equal(rec.calls.length, 0);
    });
});
