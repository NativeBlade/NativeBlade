// In-App Review action — request_review
//
// Mobile only: triggers the native review prompt via the nativeblade-review
// plugin (StoreKit on iOS, Play In-App Review on Android). The OS decides
// whether to actually show it and returns nothing.
//
// No-op on desktop: there is no native in-place review there, so an app that
// wants a "rate us" link should call openUrl() with its store listing itself.
//
// Uses: ctx.isMobile, ctx.invokeTauri

export async function request_review(_payload, ctx) {
    if (!ctx.isMobile || !ctx.invokeTauri) return;
    try {
        await ctx.invokeTauri('plugin:nativeblade-review|request_review', {});
    } catch (e) {
        console.warn('[NB Review] native request failed:', e);
    }
}
