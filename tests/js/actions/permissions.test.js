import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { check_permission, request_permission, open_app_settings } from '../../../js/wasm-app/actions/permissions.js';

function ctxWith(overrides = {}) {
    const posted = [];
    return {
        posted,
        ctx: {
            isTauri: false,
            isMobile: false,
            post: (type, data) => posted.push({ type, ...data }),
            ...overrides,
        },
    };
}

describe('actions/permissions', () => {
    it('posts nativeblade-permission with the name and a status', async () => {
        const { ctx, posted } = ctxWith();
        await check_permission({ permission: 'camera' }, ctx);
        assert.equal(posted.length, 1);
        assert.equal(posted[0].type, 'nativeblade-permission');
        assert.equal(posted[0].name, 'camera');
    });

    it('reports unsupported outside Tauri (no host to query)', async () => {
        const { ctx, posted } = ctxWith({ isTauri: false });
        await request_permission({ permission: 'location' }, ctx);
        assert.equal(posted[0].status, 'unsupported');
    });

    it('reads the geolocation plugin for location', async () => {
        const { ctx, posted } = ctxWith({
            isTauri: true,
            geolocationApi: { checkPermissions: async () => ({ location: 'granted' }) },
        });
        await check_permission({ permission: 'location' }, ctx);
        assert.equal(posted[0].status, 'granted');
    });

    it('maps the media plugin camera state', async () => {
        const { ctx, posted } = ctxWith({
            isTauri: true,
            invokeTauri: async (cmd) => {
                assert.equal(cmd, 'plugin:nativeblade-media|request_permissions');
                return { camera: 'denied' };
            },
        });
        await request_permission({ permission: 'camera' }, ctx);
        assert.equal(posted[0].status, 'denied');
    });

    it('silently checks notifications via the push plugin on mobile', async () => {
        const { ctx, posted } = ctxWith({
            isTauri: true,
            isMobile: true,
            invokeTauri: async (cmd) => {
                assert.equal(cmd, 'plugin:nativeblade-push|check_permission');
                return { status: 'prompt' };
            },
        });
        await check_permission({ permission: 'notifications' }, ctx);
        assert.equal(posted[0].status, 'prompt');
    });

    it('reports unsupported for an unknown permission', async () => {
        const { ctx, posted } = ctxWith({ isTauri: true });
        await check_permission({ permission: 'contacts' }, ctx);
        assert.equal(posted[0].status, 'unsupported');
    });

    it('opens iOS settings through the opener', async () => {
        let opened = null;
        const { ctx } = ctxWith({
            isTauri: true,
            isMobile: true,
            isAndroid: false,
            openerApi: { openUrl: async (u) => { opened = u; } },
        });
        await open_app_settings({}, ctx);
        assert.equal(opened, 'app-settings:');
    });

    it('is a no-op on desktop (not mobile)', async () => {
        let opened = false;
        const { ctx } = ctxWith({
            isTauri: true,
            isMobile: false,
            isAndroid: false,
            openerApi: { openUrl: async () => { opened = true; } },
        });
        await open_app_settings({}, ctx);
        assert.equal(opened, false);
    });

    it('is a no-op on Android for now (no always-on host)', async () => {
        let called = false;
        const { ctx } = ctxWith({
            isTauri: true,
            isAndroid: true,
            invokeTauri: async () => { called = true; },
            openerApi: { openUrl: async () => { called = true; } },
        });
        await open_app_settings({}, ctx);
        assert.equal(called, false);
    });

    it('open_app_settings is a no-op outside Tauri', async () => {
        const { ctx } = ctxWith({ isTauri: false });
        await assert.doesNotReject(() => Promise.resolve(open_app_settings({}, ctx)));
    });
});
