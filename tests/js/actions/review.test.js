import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { request_review } from '../../../js/wasm-app/actions/review.js';
import { makeCtx, spy } from '../helpers/ctx.js';

describe('actions/review', () => {
    it('invokes the native plugin on mobile', async () => {
        const invokeTauri = spy(() => Promise.resolve());
        const ctx = makeCtx({ isMobile: true, invokeTauri });

        await request_review({}, ctx);

        assert.deepEqual(invokeTauri.calls[0], ['plugin:nativeblade-review|request_review', {}]);
    });

    it('is a no-op on desktop', async () => {
        const invokeTauri = spy(() => Promise.resolve());
        const ctx = makeCtx({ isMobile: false, invokeTauri });

        await request_review({}, ctx);

        assert.equal(invokeTauri.callCount, 0);
    });

    it('swallows errors from the native call', async () => {
        const invokeTauri = spy(() => Promise.reject(new Error('not installed')));
        const ctx = makeCtx({ isMobile: true, invokeTauri });

        await assert.doesNotReject(() => request_review({}, ctx));
    });
});
