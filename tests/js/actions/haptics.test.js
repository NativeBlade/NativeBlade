import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { vibrate, impact, selection } from '../../../js/wasm-app/actions/haptics.js';
import { makeCtx, spy } from '../helpers/ctx.js';

// The native path only runs inside Tauri; makeCtx defaults isTauri to false,
// so native-API assertions must opt in with isTauri: true. Outside Tauri the
// handlers fall back to the Web Vibration API (absent in Node) → no-op.
describe('actions/haptics', () => {
    describe('vibrate', () => {
        it('calls hapticsApi.vibrate with the given duration', () => {
            const vib = spy();
            vibrate({ duration: 250 }, makeCtx({ isTauri: true, hapticsApi: { vibrate: vib } }));

            assert.deepEqual(vib.calls[0], [250]);
        });

        it('defaults the duration to 100 when missing', () => {
            const vib = spy();
            vibrate({}, makeCtx({ isTauri: true, hapticsApi: { vibrate: vib } }));

            assert.deepEqual(vib.calls[0], [100]);
        });

        it('does not call the native API outside Tauri', () => {
            const vib = spy();
            vibrate({ duration: 50 }, makeCtx({ isTauri: false, hapticsApi: { vibrate: vib } }));

            assert.equal(vib.called, false);
        });

        it('is a no-op when hapticsApi is unavailable', () => {
            assert.doesNotThrow(() => vibrate({ duration: 50 }, makeCtx({ isTauri: true, hapticsApi: null })));
        });
    });

    describe('impact', () => {
        it('calls impactFeedback with the given style', () => {
            const imp = spy();
            impact({ style: 'heavy' }, makeCtx({ isTauri: true, hapticsApi: { impactFeedback: imp } }));

            assert.deepEqual(imp.calls[0], ['heavy']);
        });

        it('defaults style to "medium"', () => {
            const imp = spy();
            impact({}, makeCtx({ isTauri: true, hapticsApi: { impactFeedback: imp } }));

            assert.deepEqual(imp.calls[0], ['medium']);
        });

        it('does not call the native API outside Tauri', () => {
            const imp = spy();
            impact({ style: 'light' }, makeCtx({ isTauri: false, hapticsApi: { impactFeedback: imp } }));

            assert.equal(imp.called, false);
        });

        it('is a no-op when hapticsApi is unavailable', () => {
            assert.doesNotThrow(() => impact({ style: 'light' }, makeCtx({ isTauri: true, hapticsApi: null })));
        });
    });

    describe('selection', () => {
        it('calls selectionFeedback', () => {
            const sel = spy();
            selection({}, makeCtx({ isTauri: true, hapticsApi: { selectionFeedback: sel } }));

            assert.equal(sel.callCount, 1);
        });

        it('does not call the native API outside Tauri', () => {
            const sel = spy();
            selection({}, makeCtx({ isTauri: false, hapticsApi: { selectionFeedback: sel } }));

            assert.equal(sel.called, false);
        });

        it('is a no-op when hapticsApi is unavailable', () => {
            assert.doesNotThrow(() => selection({}, makeCtx({ isTauri: true, hapticsApi: null })));
        });
    });
});
