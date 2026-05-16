async function getInvoke(ctx) {
    if (typeof ctx.invokeTauri === 'function') return ctx.invokeTauri;
    try {
        const mod = await import('@tauri-apps/api/core');
        return mod.invoke;
    } catch {
        return null;
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
    }

    await webFallback(payload);
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

function sanitize(payload) {
    const out = {};
    for (const [key, value] of Object.entries(payload)) {
        if (value !== undefined && value !== null) {
            out[key] = value;
        }
    }
    return out;
}
