// Notification actions — notification, cancel_notification, cancel_all_notifications
//
// These dispatch to the `nativeblade-push` Tauri plugin (the same one that
// handles FCM/APNS) so local and remote notifications share one code path.
// On the JS side we use `invoke()` directly because the plugin exposes
// `notify` / `cancel` / `cancelAll` as native commands.
//
// Payload shape (matches PHP NativeBlade\Plugins\Notification builder):
//   { id?, title?, body?, sound?, icon?, channel?, schedule? }
//
// schedule:
//   { type: 'at', at: 'YYYY-MM-DDTHH:MM:SSZ' }       — one-shot
//   { type: 'every', kind, count }                    — recurring
//   { type: 'dailyAt', time: 'HH:MM' }                — daily at time
//
// Uses: ctx.isTauri, ctx.invokeTauri (resolved by bridge.js), ctx.post

async function getInvoke(ctx) {
    if (typeof ctx.invokeTauri === 'function') return ctx.invokeTauri;
    try {
        const mod = await import('@tauri-apps/api/core');
        return mod.invoke;
    } catch {
        return null;
    }
}

export async function notification(payload, ctx) {
    if (!ctx.isTauri) {
        ctx.post('nativeblade-alert', { message: payload.body });
        return;
    }
    const invoke = await getInvoke(ctx);
    if (!invoke) {
        ctx.post('nativeblade-alert', { message: payload.body });
        return;
    }
    try {
        await invoke('plugin:nativeblade-push|notify', sanitize(payload));
    } catch (e) {
        console.warn('[NB Notification] notify failed:', e);
        ctx.post('nativeblade-alert', { message: payload.body });
    }
}

export async function cancel_notification(payload, ctx) {
    if (!ctx.isTauri || !payload.id) return;
    const invoke = await getInvoke(ctx);
    if (!invoke) return;
    try {
        await invoke('plugin:nativeblade-push|cancel', { id: payload.id });
    } catch (e) {
        console.warn('[NB Notification] cancel failed:', e);
    }
}

export async function cancel_all_notifications(_payload, ctx) {
    if (!ctx.isTauri) return;
    const invoke = await getInvoke(ctx);
    if (!invoke) return;
    try {
        await invoke('plugin:nativeblade-push|cancelAll', {});
    } catch (e) {
        console.warn('[NB Notification] cancelAll failed:', e);
    }
}

// Strip fields that are undefined/null so the native side only sees what
// the dev actually set — keeps the Kotlin/Swift JSObject parsing clean.
function sanitize(payload) {
    const out = {};
    for (const [key, value] of Object.entries(payload)) {
        if (value !== undefined && value !== null) {
            out[key] = value;
        }
    }
    return out;
}
