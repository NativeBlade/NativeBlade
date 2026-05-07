// Haptics actions — vibrate, impact, selection
// Uses: ctx.hapticsApi

// The Tauri haptics plugin only accepts these lowercase strings; anything
// else throws. Devs commonly pass 'Medium' / 'Heavy' so we normalize.
const IMPACT_STYLES = new Set(['light', 'medium', 'heavy', 'soft', 'rigid']);

function normalizeImpactStyle(style) {
    const s = (style || 'medium').toString().toLowerCase();
    return IMPACT_STYLES.has(s) ? s : 'medium';
}

export function vibrate(payload, ctx) {
    if (!ctx.hapticsApi) return;
    ctx.hapticsApi.vibrate(payload.duration || 100);
}

export function impact(payload, ctx) {
    if (!ctx.hapticsApi) return;
    ctx.hapticsApi.impactFeedback(normalizeImpactStyle(payload.style));
}

export function selection(payload, ctx) {
    if (!ctx.hapticsApi) return;
    ctx.hapticsApi.selectionFeedback();
}
