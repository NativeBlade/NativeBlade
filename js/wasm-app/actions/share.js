// Sharing action — share
//
// Mobile only: opens the native share sheet via the nativeblade-sharing
// plugin (UIActivityViewController on iOS, Intent.ACTION_SEND on Android).
// No-op on desktop. v1 shares text and/or a URL; files come later.
//
// Uses: ctx.isMobile, ctx.invokeTauri

export async function share(payload, ctx) {
    if (!ctx.isMobile || !ctx.invokeTauri) return;
    try {
        await ctx.invokeTauri('plugin:nativeblade-sharing|share', {
            text: payload.text ?? '',
            url: payload.url ?? '',
        });
    } catch (e) {
        console.warn('[NB Share] failed:', e);
    }
}
