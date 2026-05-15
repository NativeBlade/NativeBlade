import { describe, it, beforeEach } from 'node:test';
import assert from 'node:assert/strict';
import {
    notification,
    cancel_notification,
    cancel_all_notifications,
} from '../../../js/wasm-app/actions/notification.js';
import { makeCtx, Recorder, spy } from '../helpers/ctx.js';

// The new notification action invokes the nativeblade-push Tauri plugin
// directly via `invoke()`. To keep tests hermetic without dynamic ESM
// module mocking, the action looks for `ctx.invokeTauri` first and only
// falls back to importing @tauri-apps/api/core if it's missing — so
// tests just inject a stub onto ctx.

function makeInvoke(impl = () => Promise.resolve()) {
    return spy(impl);
}

describe('actions/notification', () => {
    let rec;
    beforeEach(() => { rec = new Recorder(); });

    it('does not call invoke when not in Tauri', async () => {
        const invoke = makeInvoke();
        const ctx = makeCtx({ isTauri: false, invokeTauri: invoke, post: rec.fn() });
        await notification({ title: 'T', body: 'B' }, ctx);

        assert.equal(invoke.callCount, 0);
        assert.equal(rec.calls.length, 0);
    });

    it('invokes nativeblade-push|notify when in Tauri', async () => {
        const invoke = makeInvoke();
        const ctx = makeCtx({ isTauri: true, invokeTauri: invoke, post: rec.fn() });
        await notification({ title: 'Hi', body: 'There' }, ctx);

        assert.equal(invoke.callCount, 1);
        assert.equal(invoke.calls[0][0], 'plugin:nativeblade-push|notify');
        assert.deepEqual(invoke.calls[0][1], { title: 'Hi', body: 'There' });
    });

    it('forwards id and schedule untouched to the native side', async () => {
        const invoke = makeInvoke();
        const ctx = makeCtx({ isTauri: true, invokeTauri: invoke });
        await notification({
            id: 'reminder-1',
            title: 'T',
            body: 'B',
            schedule: { type: 'at', at: '2026-12-25T09:00:00Z' },
        }, ctx);

        const payload = invoke.calls[0][1];
        assert.equal(payload.id, 'reminder-1');
        assert.deepEqual(payload.schedule, { type: 'at', at: '2026-12-25T09:00:00Z' });
    });

    it('forwards channel, sound, icon when set', async () => {
        const invoke = makeInvoke();
        const ctx = makeCtx({ isTauri: true, invokeTauri: invoke });
        await notification({
            body: 'x',
            channel: 'messages',
            sound: 'bell',
            icon: 'ic_chat',
        }, ctx);

        const payload = invoke.calls[0][1];
        assert.equal(payload.channel, 'messages');
        assert.equal(payload.sound, 'bell');
        assert.equal(payload.icon, 'ic_chat');
    });

    it('strips undefined and null fields before invoking', async () => {
        const invoke = makeInvoke();
        const ctx = makeCtx({ isTauri: true, invokeTauri: invoke });
        await notification({
            title: 'T',
            body: 'B',
            sound: null,
            icon: undefined,
            schedule: null,
        }, ctx);

        const payload = invoke.calls[0][1];
        assert.deepEqual(Object.keys(payload).sort(), ['body', 'title']);
    });

    it('does not post alert when invoke rejects', async () => {
        const invoke = makeInvoke(() => Promise.reject(new Error('plugin not loaded')));
        const ctx = makeCtx({ isTauri: true, invokeTauri: invoke, post: rec.fn() });
        await notification({ body: 'B' }, ctx);

        assert.equal(rec.calls.length, 0);
    });
});

describe('actions/cancel_notification', () => {
    it('invokes nativeblade-push|cancel with the id', async () => {
        const invoke = makeInvoke();
        const ctx = makeCtx({ isTauri: true, invokeTauri: invoke });
        await cancel_notification({ id: 'reminder-1' }, ctx);

        assert.equal(invoke.callCount, 1);
        assert.equal(invoke.calls[0][0], 'plugin:nativeblade-push|cancel');
        assert.deepEqual(invoke.calls[0][1], { id: 'reminder-1' });
    });

    it('is a no-op when no id is given', async () => {
        const invoke = makeInvoke();
        const ctx = makeCtx({ isTauri: true, invokeTauri: invoke });
        await cancel_notification({}, ctx);

        assert.equal(invoke.callCount, 0);
    });

    it('is a no-op outside Tauri', async () => {
        const invoke = makeInvoke();
        const ctx = makeCtx({ isTauri: false, invokeTauri: invoke });
        await cancel_notification({ id: 'x' }, ctx);

        assert.equal(invoke.callCount, 0);
    });
});

describe('actions/cancel_all_notifications', () => {
    it('invokes nativeblade-push|cancelAll', async () => {
        const invoke = makeInvoke();
        const ctx = makeCtx({ isTauri: true, invokeTauri: invoke });
        await cancel_all_notifications({}, ctx);

        assert.equal(invoke.callCount, 1);
        assert.equal(invoke.calls[0][0], 'plugin:nativeblade-push|cancelAll');
    });

    it('is a no-op outside Tauri', async () => {
        const invoke = makeInvoke();
        const ctx = makeCtx({ isTauri: false, invokeTauri: invoke });
        await cancel_all_notifications({}, ctx);

        assert.equal(invoke.callCount, 0);
    });
});
