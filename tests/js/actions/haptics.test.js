import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { vibrate, impact, selection } from '../../../js/wasm-app/actions/haptics.js';
import { makeCtx, spy } from '../helpers/ctx.js';

describe('actions/haptics', () => {
    describe('vibrate', () => {
        it('calls hapticsApi.vibrate with the given duration', () => {
            const vib = spy();
            vibrate({ duration: 250 }, makeCtx({ hapticsApi: { vibrate: vib } }));

            assert.deepEqual(vib.calls[0], [250]);
        });

        it('defaults the duration to 100 when missing', () => {
            const vib = spy();
            vibrate({}, makeCtx({ hapticsApi: { vibrate: vib } }));

            assert.deepEqual(vib.calls[0], [100]);
        });

        it('is a no-op when hapticsApi is unavailable', () => {
            assert.doesNotThrow(() => vibrate({ duration: 50 }, makeCtx({ hapticsApi: null })));
        });
    });

    describe('impact', () => {
        it('calls impactFeedback with the given style', () => {
            const imp = spy();
            impact({ style: 'heavy' }, makeCtx({ hapticsApi: { impactFeedback: imp } }));

            assert.deepEqual(imp.calls[0], ['heavy']);
        });

        it('defaults style to "medium"', () => {
            const imp = spy();
            impact({}, makeCtx({ hapticsApi: { impactFeedback: imp } }));

            assert.deepEqual(imp.calls[0], ['medium']);
        });

        it('is a no-op when hapticsApi is unavailable', () => {
            assert.doesNotThrow(() => impact({ style: 'light' }, makeCtx({ hapticsApi: null })));
        });
    });

    describe('selection', () => {
        it('calls selectionFeedback', () => {
            const sel = spy();
            selection({}, makeCtx({ hapticsApi: { selectionFeedback: sel } }));

            assert.equal(sel.callCount, 1);
        });

        it('is a no-op when hapticsApi is unavailable', () => {
            assert.doesNotThrow(() => selection({}, makeCtx({ hapticsApi: null })));
        });
    });
});
