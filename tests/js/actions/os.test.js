import { describe, it, beforeEach } from 'node:test';
import assert from 'node:assert/strict';
import { os_info } from '../../../js/wasm-app/actions/os.js';
import { makeCtx, Recorder, flush } from '../helpers/ctx.js';

describe('actions/os', () => {
    let rec;
    beforeEach(() => { rec = new Recorder(); });

    it('is a no-op when osApi is unavailable', async () => {
        os_info({}, makeCtx({ osApi: null, post: rec.fn() }));
        await flush();
        assert.equal(rec.calls.length, 0);
    });

    it('posts the aggregated platform info', async () => {
        const osApi = {
            platform: () => Promise.resolve('linux'),
            version: () => Promise.resolve('6.1'),
            arch: () => Promise.resolve('x86_64'),
            locale: () => Promise.resolve('en-US'),
        };
        const ctx = makeCtx({ osApi, post: rec.fn() });

        os_info({}, ctx);
        await flush();
        await flush();

        assert.deepEqual(rec.calls, [
            {
                type: 'nativeblade-os-info',
                data: { info: { platform: 'linux', version: '6.1', arch: 'x86_64', locale: 'en-US' } },
            },
        ]);
    });
});
