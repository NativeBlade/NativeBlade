import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { set_secure, get_secure, forget_secure } from '../../../js/wasm-app/actions/secure.js';
import { makeCtx, Recorder, spy } from '../helpers/ctx.js';

describe('actions/secure', () => {
    it('set_secure invokes the native plugin on mobile', async () => {
        const invokeTauri = spy(() => Promise.resolve());
        const ctx = makeCtx({ isMobile: true, invokeTauri });

        await set_secure({ key: 'auth.token', value: 'abc' }, ctx);

        assert.deepEqual(invokeTauri.calls[0], [
            'plugin:nativeblade-secure-storage|set_item',
            { key: 'auth.token', value: 'abc' },
        ]);
    });

    it('set_secure is a no-op on desktop', async () => {
        const invokeTauri = spy(() => Promise.resolve());
        const ctx = makeCtx({ isMobile: false, invokeTauri });

        await set_secure({ key: 'auth.token', value: 'abc' }, ctx);

        assert.equal(invokeTauri.callCount, 0);
    });

    it('get_secure posts the native value on mobile', async () => {
        const rec = new Recorder();
        const invokeTauri = spy(() => Promise.resolve({ value: 'abc' }));
        const ctx = makeCtx({ isMobile: true, invokeTauri, post: rec.fn() });

        await get_secure({ key: 'auth.token', id: 'auth' }, ctx);

        assert.deepEqual(invokeTauri.calls[0], [
            'plugin:nativeblade-secure-storage|get_item',
            { key: 'auth.token' },
        ]);
        assert.deepEqual(rec.calls, [
            { type: 'nativeblade-secure', data: { value: 'abc', id: 'auth' } },
        ]);
    });

    it('get_secure posts a null value when the key is absent', async () => {
        const rec = new Recorder();
        const invokeTauri = spy(() => Promise.resolve({}));
        const ctx = makeCtx({ isMobile: true, invokeTauri, post: rec.fn() });

        await get_secure({ key: 'missing' }, ctx);

        assert.deepEqual(rec.calls, [
            { type: 'nativeblade-secure', data: { value: null, id: null } },
        ]);
    });

    it('get_secure posts a null value on desktop without invoking native', async () => {
        const rec = new Recorder();
        const invokeTauri = spy(() => Promise.resolve({ value: 'abc' }));
        const ctx = makeCtx({ isMobile: false, invokeTauri, post: rec.fn() });

        await get_secure({ key: 'auth.token', id: 'auth' }, ctx);

        assert.equal(invokeTauri.callCount, 0);
        assert.deepEqual(rec.calls, [
            { type: 'nativeblade-secure', data: { value: null, id: 'auth' } },
        ]);
    });

    it('forget_secure invokes the native plugin on mobile', async () => {
        const invokeTauri = spy(() => Promise.resolve());
        const ctx = makeCtx({ isMobile: true, invokeTauri });

        await forget_secure({ key: 'auth.token' }, ctx);

        assert.deepEqual(invokeTauri.calls[0], [
            'plugin:nativeblade-secure-storage|remove_item',
            { key: 'auth.token' },
        ]);
    });

    it('get_secure swallows native errors and still posts null', async () => {
        const rec = new Recorder();
        const invokeTauri = spy(() => Promise.reject(new Error('keystore error')));
        const ctx = makeCtx({ isMobile: true, invokeTauri, post: rec.fn() });

        await assert.doesNotReject(() => get_secure({ key: 'auth.token' }, ctx));
        assert.deepEqual(rec.calls, [
            { type: 'nativeblade-secure', data: { value: null, id: null } },
        ]);
    });
});
