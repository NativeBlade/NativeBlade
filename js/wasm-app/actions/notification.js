async function getInvoke(ctx) {
    if (typeof ctx.invokeTauri === 'function') return ctx.invokeTauri;
    try {
        const mod = await import('@tauri-apps/api/core');
        return mod.invoke;
    } catch {
        return null;
    }
}

async function getNotificationApi(ctx) {
    if (ctx.notificationApi) return ctx.notificationApi;
    try {
        return await import('@tauri-apps/plugin-notification');
    } catch {
        return null;
    }
}

async function resolveDesktopIcon(icon) {
    if (!icon) return undefined;
    if (/^(file:|https?:|ms-appx:|\/|[A-Za-z]:[\\/])/.test(icon)) return icon;
    try {
        const path = await import('@tauri-apps/api/path');
        try {
            return await path.resolveResource(icon);
        } catch {}
        const base = await path.resourceDir();
        const sep = await path.sep();
        return (base.endsWith(sep) ? base : base + sep) + icon.replace(/[\\/]/g, sep);
    } catch {
        return undefined;
    }
}

async function desktopNotify(payload, ctx) {
    const api = await getNotificationApi(ctx);
    if (!api) return false;
    try {
        let granted = await api.isPermissionGranted();
        if (!granted) {
            const perm = await api.requestPermission();
            granted = perm === 'granted';
        }
        if (!granted) return false;
        const icon = await resolveDesktopIcon(payload.icon);
        api.sendNotification({
            title: payload.title || 'NativeBlade',
            body: payload.body || '',
            icon,
            sound: payload.sound,
        });
        return true;
    } catch (e) {
        console.warn('[NB Notification] desktop send failed:', e);
        return false;
    }
}

async function webFallback(payload) {
    if (typeof window === 'undefined' || !('Notification' in window)) return false;
    try {
        if (Notification.permission === 'default') {
            const result = await Notification.requestPermission();
            if (result !== 'granted') return false;
        }
        if (Notification.permission !== 'granted') return false;
        new Notification(payload.title || 'NativeBlade', {
            body: payload.body || '',
            icon: payload.icon,
        });
        return true;
    } catch {
        return false;
    }
}

export async function notification(payload, ctx) {
    if (ctx.isTauri && ctx.isMobile) {
        const invoke = await getInvoke(ctx);
        if (invoke) {
            try {
                await invoke('plugin:nativeblade-push|notify', sanitize(payload));
                return;
            } catch (e) {
                console.warn('[NB Notification] notify failed:', e);
            }
        }
        return;
    }

    if (ctx.isTauri && await desktopNotify(payload, ctx)) return;

    await webFallback(payload);
}

export async function cancel_notification(payload, ctx) {
    if (!ctx.isTauri || !ctx.isMobile || !payload.id) return;
    const invoke = await getInvoke(ctx);
    if (!invoke) return;
    try {
        await invoke('plugin:nativeblade-push|cancel', { id: payload.id });
    } catch (e) {
        console.warn('[NB Notification] cancel failed:', e);
    }
}

export async function cancel_all_notifications(_payload, ctx) {
    if (!ctx.isTauri || !ctx.isMobile) return;
    const invoke = await getInvoke(ctx);
    if (!invoke) return;
    try {
        await invoke('plugin:nativeblade-push|cancelAll', {});
    } catch (e) {
        console.warn('[NB Notification] cancelAll failed:', e);
    }
}

function sanitize(payload) {
    const out = {};
    for (const [key, value] of Object.entries(payload)) {
        if (value !== undefined && value !== null) {
            out[key] = value;
        }
    }
    return out;
}
