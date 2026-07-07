// Haptics actions — vibrate, impact, selection
// Uses: ctx.hapticsApi

// The Tauri haptics plugin only accepts these lowercase strings; anything
// else throws. Devs commonly pass 'Medium' / 'Heavy' so we normalize.
const IMPACT_STYLES = new Set(['light', 'medium', 'heavy', 'soft', 'rigid']);

function normalizeImpactStyle(style) {
    const s = (style || 'medium').toString().toLowerCase();
    return IMPACT_STYLES.has(s) ? s : 'medium';
}

// Native haptics need the Tauri host. Outside it (browser preview) the plugin
// module still loads, but calling it hits window.__TAURI_INTERNALS__.invoke —
// undefined — and throws an uncaught rejection on every tap. Fall back to the
// Web Vibration API where it exists, otherwise no-op. Returning the promise
// lets the dispatcher swallow any late rejection.
export function vibrate(payload, ctx) {
    if (!ctx.isTauri || !ctx.hapticsApi) {
        if (typeof navigator !== 'undefined' && navigator.vibrate) navigator.vibrate(payload.duration || 100);
        return;
    }
    return ctx.hapticsApi.vibrate(payload.duration || 100);
}

export function impact(payload, ctx) {
    if (!ctx.isTauri || !ctx.hapticsApi) {
        if (typeof navigator !== 'undefined' && navigator.vibrate) navigator.vibrate(10);
        return;
    }
    return ctx.hapticsApi.impactFeedback(normalizeImpactStyle(payload.style));
}

export function selection(payload, ctx) {
    if (!ctx.isTauri || !ctx.hapticsApi) {
        if (typeof navigator !== 'undefined' && navigator.vibrate) navigator.vibrate(5);
        return;
    }
    return ctx.hapticsApi.selectionFeedback();
}
