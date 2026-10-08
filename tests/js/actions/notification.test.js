import { describe, it, beforeEach } from 'node:test';
import assert from 'node:assert/strict';
import {
    notification,
    cancel_notification,
    cancel_all_notifications,
    __resetLocalSchedulesForTests,
    pendingLocalSchedules,
    msUntilDailyAt,
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

    it('invokes nativeblade-push|notify when in Tauri mobile', async () => {
        const invoke = makeInvoke();
        const ctx = makeCtx({ isTauri: true, isMobile: true, invokeTauri: invoke, post: rec.fn() });
        await notification({ title: 'Hi', body: 'There' }, ctx);

        assert.equal(invoke.callCount, 1);
        assert.equal(invoke.calls[0][0], 'plugin:nativeblade-push|notify');
        assert.deepEqual(invoke.calls[0][1], { title: 'Hi', body: 'There' });
    });

    it('does not invoke the plugin on Tauri desktop — uses Web Notification API instead', async () => {
        const invoke = makeInvoke();
        const ctx = makeCtx({ isTauri: true, isMobile: false, invokeTauri: invoke, post: rec.fn() });
        await notification({ title: 'Hi', body: 'There' }, ctx);

        assert.equal(invoke.callCount, 0);
    });

    it('forwards id and schedule untouched on mobile', async () => {
        const invoke = makeInvoke();
        const ctx = makeCtx({ isTauri: true, isMobile: true, invokeTauri: invoke });
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

    it('forwards channel, sound, icon when set (mobile)', async () => {
        const invoke = makeInvoke();
        const ctx = makeCtx({ isTauri: true, isMobile: true, invokeTauri: invoke });
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

    it('strips undefined and null fields before invoking (mobile)', async () => {
        const invoke = makeInvoke();
        const ctx = makeCtx({ isTauri: true, isMobile: true, invokeTauri: invoke });
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
        const ctx = makeCtx({ isTauri: true, isMobile: true, invokeTauri: invoke, post: rec.fn() });
        await notification({ body: 'B' }, ctx);

        assert.equal(rec.calls.length, 0);
    });
});

describe('actions/cancel_notification', () => {
    it('invokes nativeblade-push|cancel with the id (mobile)', async () => {
        const invoke = makeInvoke();
        const ctx = makeCtx({ isTauri: true, isMobile: true, invokeTauri: invoke });
        await cancel_notification({ id: 'reminder-1' }, ctx);

        assert.equal(invoke.callCount, 1);
        assert.equal(invoke.calls[0][0], 'plugin:nativeblade-push|cancel');
        assert.deepEqual(invoke.calls[0][1], { id: 'reminder-1' });
    });

    it('is a no-op when no id is given', async () => {
        const invoke = makeInvoke();
        const ctx = makeCtx({ isTauri: true, isMobile: true, invokeTauri: invoke });
        await cancel_notification({}, ctx);

        assert.equal(invoke.callCount, 0);
    });

    it('is a no-op outside Tauri', async () => {
        const invoke = makeInvoke();
        const ctx = makeCtx({ isTauri: false, invokeTauri: invoke });
        await cancel_notification({ id: 'x' }, ctx);

        assert.equal(invoke.callCount, 0);
    });

    it('is a no-op on Tauri desktop', async () => {
        const invoke = makeInvoke();
        const ctx = makeCtx({ isTauri: true, isMobile: false, invokeTauri: invoke });
        await cancel_notification({ id: 'x' }, ctx);

        assert.equal(invoke.callCount, 0);
    });
});

describe('actions/cancel_all_notifications', () => {
    it('invokes nativeblade-push|cancelAll (mobile)', async () => {
        const invoke = makeInvoke();
        const ctx = makeCtx({ isTauri: true, isMobile: true, invokeTauri: invoke });
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

    it('is a no-op on Tauri desktop', async () => {
        const invoke = makeInvoke();
        const ctx = makeCtx({ isTauri: true, isMobile: false, invokeTauri: invoke });
        await cancel_all_notifications({}, ctx);

        assert.equal(invoke.callCount, 0);
    });
});

describe('actions/notification desktop schedules', () => {
    let rec;
    beforeEach(() => { rec = new Recorder(); __resetLocalSchedulesForTests(); });

    function desktopCtx(sent) {
        return makeCtx({
            isTauri: true,
            isMobile: false,
            post: rec.fn(),
            notificationApi: {
                isPermissionGranted: async () => true,
                requestPermission: async () => 'granted',
                sendNotification: (n) => { sent.push(n); },
            },
        });
    }

    const flush = () => new Promise((resolve) => setImmediate(resolve));

    it('keeps an at() schedule in the shell and fires it when the time comes', async (t) => {
        t.mock.timers.enable({ apis: ['setTimeout', 'Date'], now: new Date('2026-10-08T10:00:00Z') });
        const sent = [];
        await notification({
            id: 'r1', title: 'Later', body: 'B',
            schedule: { type: 'at', at: '2026-10-08T10:05:00Z' },
        }, desktopCtx(sent));

        assert.deepEqual(sent, [], 'nothing fires on dispatch');
        assert.deepEqual(pendingLocalSchedules(), ['r1']);

        t.mock.timers.tick(4 * 60e3);
        await flush();
        assert.deepEqual(sent, []);

        t.mock.timers.tick(60e3);
        await flush();
        assert.equal(sent.length, 1);
        assert.equal(sent[0].title, 'Later');
        assert.deepEqual(pendingLocalSchedules(), []);
    });

    it('cancel_notification drops a pending desktop schedule by id', async (t) => {
        t.mock.timers.enable({ apis: ['setTimeout', 'Date'], now: 0 });
        const sent = [];
        const ctx = desktopCtx(sent);
        await notification({ id: 'r1', title: 'T', schedule: { type: 'at', at: new Date(60e3).toISOString() } }, ctx);
        await notification({ id: 'r2', title: 'T', schedule: { type: 'at', at: new Date(60e3).toISOString() } }, ctx);

        await cancel_notification({ id: 'r1' }, ctx);
        assert.deepEqual(pendingLocalSchedules(), ['r2']);

        t.mock.timers.tick(60e3);
        await flush();
        assert.equal(sent.length, 1);
    });

    it('cancel_all_notifications clears every desktop schedule', async (t) => {
        t.mock.timers.enable({ apis: ['setTimeout', 'Date'], now: 0 });
        const sent = [];
        const ctx = desktopCtx(sent);
        await notification({ id: 'a', title: 'T', schedule: { type: 'every', kind: 'minute' } }, ctx);
        await notification({ title: 'T', schedule: { type: 'at', at: new Date(1000).toISOString() } }, ctx);
        assert.equal(pendingLocalSchedules().length, 2);

        await cancel_all_notifications({}, ctx);
        t.mock.timers.tick(120e3);
        await flush();
        assert.deepEqual(sent, []);
    });

    it('every() repeats while the app is open and re-arms itself', async (t) => {
        t.mock.timers.enable({ apis: ['setTimeout', 'Date'], now: 0 });
        const sent = [];
        await notification({ id: 'h', title: 'Water', schedule: { type: 'every', kind: 'minute', count: 2 } }, desktopCtx(sent));

        t.mock.timers.tick(2 * 60e3);
        await flush();
        t.mock.timers.tick(2 * 60e3);
        await flush();
        assert.equal(sent.length, 2);
        assert.deepEqual(pendingLocalSchedules(), ['h']);
    });

    it('a schedule for a time already past fires right away', async (t) => {
        t.mock.timers.enable({ apis: ['setTimeout', 'Date'], now: new Date('2026-10-08T10:00:00Z') });
        const sent = [];
        await notification({ id: 'p', title: 'T', schedule: { type: 'at', at: '2026-10-08T09:00:00Z' } }, desktopCtx(sent));
        t.mock.timers.tick(0);
        await flush();
        assert.equal(sent.length, 1);
    });

    it('msUntilDailyAt picks the next occurrence of the local time', () => {
        const now = new Date(2026, 9, 8, 10, 0, 0);
        assert.equal(msUntilDailyAt('10:30', now), 30 * 60e3);
        assert.equal(msUntilDailyAt('09:00', now), 23 * 60 * 60e3);
    });
});
