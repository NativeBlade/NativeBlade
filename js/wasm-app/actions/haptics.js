// Haptics actions — vibrate, impact, selection
// Uses: ctx.hapticsApi

export function vibrate(payload, ctx) {
    if (!ctx.hapticsApi) return;
    ctx.hapticsApi.vibrate(payload.duration || 100);
}

export function impact(payload, ctx) {
    if (!ctx.hapticsApi) return;
    ctx.hapticsApi.impactFeedback(payload.style || 'medium');
}

export function selection(payload, ctx) {
    if (!ctx.hapticsApi) return;
    ctx.hapticsApi.selectionFeedback();
}
