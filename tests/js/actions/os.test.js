import { describe, it, beforeEach } from 'node:test';
import assert from 'node:assert/strict';
import { os_info } from '../../../js/wasm-app/actions/os.js';
import { makeCtx, Recorder, flush } from '../helpers/ctx.js';

describe('actions/os', () => {
    let rec;
    beforeEach(() => { rec = new Recorder(); });

    it('posts the aggregated platform info inside Tauri', async () => {
        const osApi = {
            platform: () => Promise.resolve('linux'),
            version: () => Promise.resolve('6.1'),
            arch: () => Promise.resolve('x86_64'),
            locale: () => Promise.resolve('en-US'),
        };
        const ctx = makeCtx({ isTauri: true, osApi, post: rec.fn() });

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

    it('replies with browser info when osApi is unavailable', async () => {
        os_info({}, makeCtx({ isTauri: false, osApi: null, post: rec.fn() }));
        await flush();

        assert.equal(rec.calls.length, 1);
        assert.equal(rec.calls[0].type, 'nativeblade-os-info');
        // Empty version/arch, and a platform string (navigator.platform or 'browser').
        const { info } = rec.calls[0].data;
        assert.equal(typeof info.platform, 'string');
        assert.ok(info.platform.length > 0);
        assert.equal(info.version, '');
        assert.equal(info.arch, '');
    });
});
