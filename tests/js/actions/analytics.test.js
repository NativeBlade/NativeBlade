import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { analytics } from '../../../js/wasm-app/actions/analytics.js';
import { makeCtx, spy } from '../helpers/ctx.js';

describe('actions/analytics', () => {
    it('invokes apply on mobile with the ops', async () => {
        const invokeTauri = spy(() => Promise.resolve());
        const ctx = makeCtx({ isMobile: true, invokeTauri });

        const ops = [{ op: 'event', name: 'add_to_cart', params: { value: 9.99 } }];
        await analytics({ ops }, ctx);

        assert.deepEqual(invokeTauri.calls[0], ['plugin:nativeblade-analytics|apply', { ops }]);
    });

    it('is a no-op when ops is empty', async () => {
        const invokeTauri = spy(() => Promise.resolve());
        const ctx = makeCtx({ isMobile: true, invokeTauri });

        await analytics({ ops: [] }, ctx);

        assert.equal(invokeTauri.callCount, 0);
    });

    it('is a no-op on desktop', async () => {
        const invokeTauri = spy(() => Promise.resolve());
        const ctx = makeCtx({ isMobile: false, invokeTauri });

        await analytics({ ops: [{ op: 'event', name: 'x' }] }, ctx);

        assert.equal(invokeTauri.callCount, 0);
    });

    it('swallows native errors', async () => {
        const invokeTauri = spy(() => Promise.reject(new Error('boom')));
        const ctx = makeCtx({ isMobile: true, invokeTauri });

        await assert.doesNotReject(() => analytics({ ops: [{ op: 'event', name: 'x' }] }, ctx));
    });
});
