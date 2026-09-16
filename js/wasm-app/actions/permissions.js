// Permission actions — check_permission, request_permission.
//
// A unified layer over the per-plugin permission APIs, normalized to
// 'granted' | 'denied' | 'prompt' | 'unsupported' and delivered on the
// nb:permission Livewire event. No new native code: it maps each name to a
// plugin that already exposes the permission (location -> geolocation,
// camera -> media, notifications -> push on mobile / notification on desktop).
//
// Uses: ctx.isTauri, ctx.isMobile, ctx.geolocationApi, ctx.notificationApi,
//       ctx.invokeTauri, ctx.post

// Tauri PermissionState values ('granted'|'denied'|'prompt'|'prompt-with-rationale')
// and the booleans some plugins return, folded to our four-value status.
function normalize(state) {
    if (state === true || state === 'granted') return 'granted';
    if (state === false || state === 'denied') return 'denied';
    if (state === undefined || state === null) return 'unsupported';
    return 'prompt';
}

async function resolveStatus(name, ctx, request) {
    if (!ctx.isTauri) return 'unsupported';

    try {
        if (name === 'location') {
            if (!ctx.geolocationApi) return 'unsupported';
            const r = request
                ? await ctx.geolocationApi.requestPermissions(['location'])
                : await ctx.geolocationApi.checkPermissions();
            return normalize(r && (r.location ?? r.coarseLocation));
        }

        if (name === 'camera') {
            const cmd = request ? 'request_permissions' : 'check_permissions';
            const r = await ctx.invokeTauri(`plugin:nativeblade-media|${cmd}`);
            return normalize(r && r.camera);
        }

        if (name === 'notifications') {
            if (ctx.isMobile) {
                // Notifications live in the push plugin on mobile: request_permission
                // prompts, check_permission reports the state silently.
                if (request) {
                    const r = await ctx.invokeTauri('plugin:nativeblade-push|request_permission');
                    return normalize(r && r.granted);
                }
                const r = await ctx.invokeTauri('plugin:nativeblade-push|check_permission');
                return normalize(r && r.status);
            }
            if (!ctx.notificationApi) return 'unsupported';
            return request
                ? normalize(await ctx.notificationApi.requestPermission())
                : normalize(await ctx.notificationApi.isPermissionGranted());
        }

        return 'unsupported';
    } catch {
        return 'unsupported';
    }
}

// Open the OS "app settings" page, the natural next step after a 'denied' where
// the app can no longer re-prompt. iOS routes the app-settings URL through the
// opener (no native needed). Android needs a native intent
// (ACTION_APPLICATION_DETAILS_SETTINGS) hosted by an always-on plugin, which
// does not exist yet, so it is a no-op there for now. Desktop has no equivalent.
export async function open_app_settings(payload, ctx) {
    if (ctx.isTauri && !ctx.isAndroid && ctx.openerApi) {
        try { await ctx.openerApi.openUrl('app-settings:'); } catch {}
    }
}

export async function check_permission(payload, ctx) {
    const name = payload && payload.permission;
    ctx.post('nativeblade-permission', { name, status: await resolveStatus(name, ctx, false) });
}

export async function request_permission(payload, ctx) {
    const name = payload && payload.permission;
    ctx.post('nativeblade-permission', { name, status: await resolveStatus(name, ctx, true) });
}
