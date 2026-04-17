import { describe, it, beforeEach } from 'node:test';
import assert from 'node:assert/strict';
import { geolocation } from '../../../js/wasm-app/actions/geolocation.js';
import { makeCtx, Recorder, flush } from '../helpers/ctx.js';

function makeGeoApi({ state = 'granted', position = {}, requestState = null } = {}) {
    return {
        checkPermissions: () => Promise.resolve({ location: state }),
        requestPermissions: () => Promise.resolve({ location: requestState ?? state }),
        getCurrentPosition: () => Promise.resolve(position),
    };
}

describe('actions/geolocation', () => {
    let rec;
    beforeEach(() => { rec = new Recorder(); });

    it('is a no-op when the API is unavailable', async () => {
        await geolocation({}, makeCtx({ geolocationApi: null, post: rec.fn() }));
        assert.equal(rec.calls.length, 0);
    });

    it('posts the position when permission is already granted', async () => {
        const pos = { coords: { latitude: 1, longitude: 2 } };
        const ctx = makeCtx({ geolocationApi: makeGeoApi({ position: pos }), post: rec.fn() });
        await geolocation({}, ctx);
        await flush();

        assert.deepEqual(rec.calls, [
            { type: 'nativeblade-geolocation', data: { position: pos, id: null } },
        ]);
    });

    it('requests permission when not yet granted', async () => {
        const ctx = makeCtx({
            geolocationApi: makeGeoApi({ state: 'prompt', requestState: 'granted', position: { ok: 1 } }),
            post: rec.fn(),
        });
        await geolocation({ id: 'dest' }, ctx);
        await flush();

        assert.equal(rec.calls.length, 1);
        assert.equal(rec.calls[0].data.id, 'dest');
    });

    it('does not post when permission is denied', async () => {
        const ctx = makeCtx({
            geolocationApi: makeGeoApi({ state: 'prompt', requestState: 'denied' }),
            post: rec.fn(),
        });
        await geolocation({}, ctx);
        await flush();

        assert.equal(rec.calls.length, 0);
    });

    it('swallows errors thrown by the underlying API', async () => {
        const ctx = makeCtx({
            geolocationApi: {
                checkPermissions: () => { throw new Error('boom'); },
                requestPermissions: () => Promise.resolve({ location: 'denied' }),
                getCurrentPosition: () => Promise.resolve({}),
            },
            post: rec.fn(),
        });

        await assert.doesNotReject(() => geolocation({}, ctx));
        assert.equal(rec.calls.length, 0);
    });
});
