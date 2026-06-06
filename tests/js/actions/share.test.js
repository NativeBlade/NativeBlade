import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { share } from '../../../js/wasm-app/actions/share.js';
import { makeCtx, spy } from '../helpers/ctx.js';

describe('actions/share', () => {
    it('invokes the native plugin on mobile with text and url', async () => {
        const invokeTauri = spy(() => Promise.resolve());
        const ctx = makeCtx({ isMobile: true, invokeTauri });

        await share({ text: 'hi', url: 'https://x/y' }, ctx);

        assert.deepEqual(invokeTauri.calls[0], [
            'plugin:nativeblade-sharing|share',
            { text: 'hi', url: 'https://x/y' },
        ]);
    });

    it('defaults missing fields to empty strings', async () => {
        const invokeTauri = spy(() => Promise.resolve());
        const ctx = makeCtx({ isMobile: true, invokeTauri });

        await share({ text: 'hi' }, ctx);

        assert.deepEqual(invokeTauri.calls[0], [
            'plugin:nativeblade-sharing|share',
            { text: 'hi', url: '' },
        ]);
    });

    it('is a no-op on desktop', async () => {
        const invokeTauri = spy(() => Promise.resolve());
        const ctx = makeCtx({ isMobile: false, invokeTauri });

        await share({ text: 'hi' }, ctx);

        assert.equal(invokeTauri.callCount, 0);
    });

    it('swallows native errors', async () => {
        const invokeTauri = spy(() => Promise.reject(new Error('boom')));
        const ctx = makeCtx({ isMobile: true, invokeTauri });

        await assert.doesNotReject(() => share({ text: 'hi' }, ctx));
    });
});
