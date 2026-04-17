import { describe, it, beforeEach } from 'node:test';
import assert from 'node:assert/strict';
import { notification } from '../../../js/wasm-app/actions/notification.js';
import { makeCtx, Recorder, spy, flush } from '../helpers/ctx.js';

function makeNotificationApi({ granted = true, permValue = 'granted' } = {}) {
    return {
        isPermissionGranted: () => Promise.resolve(granted),
        requestPermission: () => Promise.resolve(permValue),
        sendNotification: spy(),
    };
}

describe('actions/notification', () => {
    let rec;
    beforeEach(() => { rec = new Recorder(); });

    it('falls back to postMessage when not in Tauri', async () => {
        const ctx = makeCtx({ isTauri: false, post: rec.fn() });
        await notification({ title: 'T', body: 'B' }, ctx);

        assert.deepEqual(rec.calls, [
            { type: 'nativeblade-alert', data: { message: 'B' } },
        ]);
    });

    it('sends with title/body when permission is already granted', async () => {
        const api = makeNotificationApi({ granted: true });
        const ctx = makeCtx({ isTauri: true, notificationApi: api });
        await notification({ title: 'Hi', body: 'There' }, ctx);

        assert.equal(api.sendNotification.callCount, 1);
        assert.equal(api.sendNotification.calls[0][0].title, 'Hi');
        assert.equal(api.sendNotification.calls[0][0].body, 'There');
    });

    it('defaults title to "NativeBlade" when missing', async () => {
        const api = makeNotificationApi();
        const ctx = makeCtx({ isTauri: true, notificationApi: api });
        await notification({ body: 'msg' }, ctx);

        assert.equal(api.sendNotification.calls[0][0].title, 'NativeBlade');
    });

    it('requests permission when not yet granted and proceeds if granted', async () => {
        const api = makeNotificationApi({ granted: false, permValue: 'granted' });
        const ctx = makeCtx({ isTauri: true, notificationApi: api });
        await notification({ body: 'x' }, ctx);

        assert.equal(api.sendNotification.callCount, 1);
    });

    it('does not send when permission is denied', async () => {
        const api = makeNotificationApi({ granted: false, permValue: 'denied' });
        const ctx = makeCtx({ isTauri: true, notificationApi: api });
        await notification({ body: 'x' }, ctx);

        assert.equal(api.sendNotification.callCount, 0);
    });

    it('forwards sound and icon when provided', async () => {
        const api = makeNotificationApi();
        const ctx = makeCtx({ isTauri: true, notificationApi: api });
        await notification({ body: 'x', sound: 'bell', icon: 'ic_chat' }, ctx);

        assert.equal(api.sendNotification.calls[0][0].sound, 'bell');
        assert.equal(api.sendNotification.calls[0][0].icon, 'ic_chat');
    });

    it('injects ic_notification as default Android icon when none is supplied', async () => {
        const api = makeNotificationApi();
        const ctx = makeCtx({ isTauri: true, isAndroid: true, notificationApi: api });
        await notification({ body: 'x' }, ctx);

        assert.equal(api.sendNotification.calls[0][0].icon, 'ic_notification');
    });

    it('uses channelId and calls ensureChannel when a channel is provided', async () => {
        const api = makeNotificationApi();
        const ensureChannel = spy(async () => {});
        const ctx = makeCtx({ isTauri: true, notificationApi: api, ensureChannel });
        await notification({ body: 'x', channel: 'messages' }, ctx);

        assert.equal(ensureChannel.callCount, 1);
        assert.deepEqual(ensureChannel.calls[0], ['messages']);
        assert.equal(api.sendNotification.calls[0][0].channelId, 'messages');
    });
});
