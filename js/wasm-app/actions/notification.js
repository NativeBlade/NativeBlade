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

    // Desktop and browser: a scheduled notification is kept by the shell and
    // fires while the app is open. Mobile hands it to the native plugin,
    // which survives the app being closed.
    if (payload.schedule && typeof payload.schedule === 'object') {
        scheduleLocally(payload, ctx);
        return;
    }

    await deliver(payload, ctx);
}

async function deliver(payload, ctx) {
    if (ctx.isTauri && await desktopNotify(payload, ctx)) return;
    await webFallback(payload);
}

// ---------------------------------------------------------------------------
// Local schedules (desktop and browser): one timer per notification id.

const MAX_TIMEOUT = 2147483647; // setTimeout's ceiling, about 24.8 days
const EVERY_MS = { minute: 60e3, hour: 3600e3, day: 86400e3, week: 7 * 86400e3, month: 30 * 86400e3 };
const timers = new Map();
let anonymous = 0;

/** setTimeout that accepts any delay, chaining when it exceeds the ceiling. */
function after(ms, fn) {
    let handle = null;
    const step = (remaining) => {
        const wait = Math.min(remaining, MAX_TIMEOUT);
        handle = setTimeout(() => {
            if (remaining > MAX_TIMEOUT) step(remaining - wait);
            else fn();
        }, wait);
    };
    step(Math.max(0, ms));
    return { cancel: () => clearTimeout(handle) };
}

export function msUntilDailyAt(time, now = new Date()) {
    const [hours, minutes] = String(time).split(':').map(Number);
    const next = new Date(now.getTime());
    next.setHours(hours || 0, minutes || 0, 0, 0);
    if (next.getTime() <= now.getTime()) next.setDate(next.getDate() + 1);
    return next.getTime() - now.getTime();
}

function scheduleLocally(payload, ctx) {
    const schedule = payload.schedule;
    const id = payload.id || `nb-anonymous-${++anonymous}`;
    cancelLocal(id);
    const fire = () => { deliver(payload, ctx).catch(() => {}); };

    if (schedule.type === 'at') {
        const delay = Date.parse(schedule.at) - Date.now();
        timers.set(id, after(delay, () => { timers.delete(id); fire(); }));
        return;
    }
    if (schedule.type === 'every') {
        const ms = (EVERY_MS[schedule.kind] || EVERY_MS.hour) * Math.max(1, Number(schedule.count) || 1);
        const tick = () => timers.set(id, after(ms, () => { fire(); tick(); }));
        tick();
        return;
    }
    if (schedule.type === 'dailyAt') {
        const tick = () => timers.set(id, after(msUntilDailyAt(schedule.time), () => { fire(); tick(); }));
        tick();
        return;
    }
    fire();
}

function cancelLocal(id) {
    const timer = timers.get(id);
    if (timer) {
        timer.cancel();
        timers.delete(id);
    }
}

function cancelAllLocal() {
    for (const timer of timers.values()) timer.cancel();
    timers.clear();
}

export function __resetLocalSchedulesForTests() {
    cancelAllLocal();
    anonymous = 0;
}

/** Ids of the notifications the shell is holding a timer for. */
export function pendingLocalSchedules() {
    return [...timers.keys()];
}

export async function cancel_notification(payload, ctx) {
    if (!payload.id) return;
    if (!ctx.isTauri || !ctx.isMobile) {
        cancelLocal(payload.id);
        return;
    }
    const invoke = await getInvoke(ctx);
    if (!invoke) return;
    try {
        await invoke('plugin:nativeblade-push|cancel', { id: payload.id });
    } catch (e) {
        console.warn('[NB Notification] cancel failed:', e);
    }
}

export async function cancel_all_notifications(_payload, ctx) {
    if (!ctx.isTauri || !ctx.isMobile) {
        cancelAllLocal();
        return;
    }
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
