import { describe, it, beforeEach } from 'node:test';
import assert from 'node:assert/strict';
import { biometric } from '../../../js/wasm-app/actions/biometric.js';
import { makeCtx, Recorder, spy } from '../helpers/ctx.js';

function makeBiometricApi({ available = true, authenticate = () => Promise.resolve() } = {}) {
    return {
        checkStatus: () => Promise.resolve({ isAvailable: available }),
        authenticate: spy(authenticate),
    };
}

describe('actions/biometric', () => {
    let rec;
    beforeEach(() => { rec = new Recorder(); });

    it('posts success=false when the API is unavailable (desktop)', async () => {
        await biometric({ id: 'login' }, makeCtx({ biometricApi: null, post: rec.fn() }));
        assert.deepEqual(rec.calls, [
            {
                type: 'nativeblade-biometric',
                data: { success: false, error: 'Biometric not available', id: 'login' },
            },
        ]);
    });

    it('posts success=false when biometric is not available', async () => {
        const api = makeBiometricApi({ available: false });
        const ctx = makeCtx({ biometricApi: api, post: rec.fn() });
        await biometric({ id: 'login' }, ctx);

        assert.deepEqual(rec.calls, [
            {
                type: 'nativeblade-biometric',
                data: { success: false, error: 'Biometric not available', id: 'login' },
            },
        ]);
        assert.equal(api.authenticate.callCount, 0);
    });

    it('posts success=true after successful authentication', async () => {
        const api = makeBiometricApi();
        const ctx = makeCtx({ biometricApi: api, post: rec.fn() });
        await biometric({ reason: 'Unlock' }, ctx);

        assert.deepEqual(api.authenticate.calls[0], [
            'Unlock',
            { allowDeviceCredential: true },
        ]);
        assert.deepEqual(rec.calls, [
            { type: 'nativeblade-biometric', data: { success: true, id: null } },
        ]);
    });

    it('defaults reason to "Authenticate" and respects allowDeviceCredential=false', async () => {
        const api = makeBiometricApi();
        const ctx = makeCtx({ biometricApi: api, post: rec.fn() });
        await biometric({ allowDeviceCredential: false }, ctx);

        assert.deepEqual(api.authenticate.calls[0], [
            'Authenticate',
            { allowDeviceCredential: false },
        ]);
    });

    it('posts success=false with error message on authentication failure', async () => {
        const api = makeBiometricApi({
            authenticate: () => Promise.reject(new Error('User cancelled')),
        });
        const ctx = makeCtx({ biometricApi: api, post: rec.fn() });
        await biometric({ id: 'checkout' }, ctx);

        assert.deepEqual(rec.calls, [
            {
                type: 'nativeblade-biometric',
                data: { success: false, error: 'User cancelled', id: 'checkout' },
            },
        ]);
    });
});
