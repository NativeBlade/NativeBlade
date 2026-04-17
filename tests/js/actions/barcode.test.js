import { describe, it, beforeEach } from 'node:test';
import assert from 'node:assert/strict';
import { scan } from '../../../js/wasm-app/actions/barcode.js';
import { makeCtx, Recorder, spy } from '../helpers/ctx.js';

function makeBarcodeApi({ state = 'granted', requestState = null, result = {} } = {}) {
    return {
        checkPermissions: () => Promise.resolve(state),
        requestPermissions: () => Promise.resolve(requestState ?? state),
        scan: spy(() => Promise.resolve(result)),
    };
}

describe('actions/barcode', () => {
    let rec;
    beforeEach(() => { rec = new Recorder(); });

    it('is a no-op when the API is unavailable', async () => {
        await scan({}, makeCtx({ barcodeApi: null, post: rec.fn() }));
        assert.equal(rec.calls.length, 0);
    });

    it('posts the scan result with the formats from payload', async () => {
        const api = makeBarcodeApi({ result: { content: 'abc', format: 'QR_CODE' } });
        const ctx = makeCtx({ barcodeApi: api, post: rec.fn() });
        await scan({ formats: ['QR_CODE'], id: 'product' }, ctx);

        assert.deepEqual(api.scan.calls[0], [{ formats: ['QR_CODE'] }]);
        assert.deepEqual(rec.calls, [
            {
                type: 'nativeblade-scan',
                data: { result: { content: 'abc', format: 'QR_CODE' }, id: 'product' },
            },
        ]);
    });

    it('defaults formats to an empty array when not provided', async () => {
        const api = makeBarcodeApi();
        const ctx = makeCtx({ barcodeApi: api, post: rec.fn() });
        await scan({}, ctx);

        assert.deepEqual(api.scan.calls[0], [{ formats: [] }]);
    });

    it('requests permission and proceeds when granted', async () => {
        const api = makeBarcodeApi({ state: 'prompt', requestState: 'granted' });
        const ctx = makeCtx({ barcodeApi: api, post: rec.fn() });
        await scan({}, ctx);

        assert.equal(api.scan.callCount, 1);
    });

    it('does not scan when permission is denied', async () => {
        const api = makeBarcodeApi({ state: 'prompt', requestState: 'denied' });
        const ctx = makeCtx({ barcodeApi: api, post: rec.fn() });
        await scan({}, ctx);

        assert.equal(api.scan.callCount, 0);
        assert.equal(rec.calls.length, 0);
    });

    it('swallows errors thrown by the underlying API', async () => {
        const ctx = makeCtx({
            barcodeApi: {
                checkPermissions: () => { throw new Error('boom'); },
                requestPermissions: () => Promise.resolve('denied'),
                scan: () => Promise.resolve({}),
            },
            post: rec.fn(),
        });

        await assert.doesNotReject(() => scan({}, ctx));
        assert.equal(rec.calls.length, 0);
    });
});
