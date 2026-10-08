import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { set_status_bar_style } from '../../../js/wasm-app/actions/system.js';
import { makeCtx, spy } from '../helpers/ctx.js';

describe('actions/set_status_bar_style', () => {
    it('invokes the system plugin with the style on mobile', async () => {
        const invoke = spy(async () => {});
        const ctx = makeCtx({ isTauri: true, isMobile: true, invokeTauri: invoke });

        await set_status_bar_style({ style: 'light' }, ctx);

        assert.equal(invoke.callCount, 1);
        assert.deepEqual(invoke.calls[0], ['plugin:nativeblade-system|set_status_bar_style', { style: 'light' }]);
    });

    it('is a no-op on desktop and outside Tauri', async () => {
        const invoke = spy(async () => {});
        await set_status_bar_style({ style: 'dark' }, makeCtx({ isTauri: true, isMobile: false, invokeTauri: invoke }));
        await set_status_bar_style({ style: 'dark' }, makeCtx({ isTauri: false, invokeTauri: invoke }));
        assert.equal(invoke.callCount, 0);
    });

    it('ignores anything but dark or light', async () => {
        const invoke = spy(async () => {});
        const ctx = makeCtx({ isTauri: true, isMobile: true, invokeTauri: invoke });
        await set_status_bar_style({ style: 'blue' }, ctx);
        await set_status_bar_style({}, ctx);
        await set_status_bar_style(null, ctx);
        assert.equal(invoke.callCount, 0);
    });

    it('a rejected invoke is logged, not thrown', async () => {
        const warned = [];
        const original = console.warn;
        console.warn = (...args) => warned.push(args);
        try {
            const ctx = makeCtx({ isTauri: true, isMobile: true, invokeTauri: spy(async () => { throw new Error('nope'); }) });
            await set_status_bar_style({ style: 'dark' }, ctx);
        } finally {
            console.warn = original;
        }
        assert.equal(warned.length, 1);
        assert.match(warned[0][0], /set_status_bar_style failed/);
    });
});
