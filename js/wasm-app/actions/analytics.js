// Analytics action — analytics
//
// Mobile only: applies a batch of Firebase Analytics ops (event, screen,
// userId, userProperty, setEnabled) via the nativeblade-analytics native
// plugin. No-op on desktop. Auto screen tracking is handled separately by
// the router (see runtime/analytics-screen.js).
//
// Uses: ctx.isMobile, ctx.invokeTauri

export async function analytics(payload, ctx) {
    if (!ctx.isMobile || !ctx.invokeTauri) return;
    const ops = Array.isArray(payload.ops) ? payload.ops : [];
    if (ops.length === 0) return;
    try {
        await ctx.invokeTauri('plugin:nativeblade-analytics|apply', { ops });
    } catch (e) {
        console.warn('[NB Analytics] failed:', e);
    }
}
