// Secure storage actions — set_secure, get_secure, forget_secure
//
// Mobile only: backed by the nativeblade-secure-storage native plugin
// (Keychain on iOS, Tink AEAD sealed by the Android Keystore). On desktop the
// writes are no-ops and get_secure reports a null value, so a single handler
// works on every platform.
//
// Uses: ctx.isMobile, ctx.invokeTauri, ctx.post

export async function set_secure(payload, ctx) {
    if (!ctx.isMobile || !ctx.invokeTauri) return;
    try {
        await ctx.invokeTauri('plugin:nativeblade-secure-storage|set_item', {
            key: payload.key,
            value: payload.value ?? '',
        });
    } catch (e) {
        console.warn('[NB Secure] set failed:', e);
    }
}

export async function get_secure(payload, ctx) {
    let value = null;
    if (ctx.isMobile && ctx.invokeTauri) {
        try {
            const result = await ctx.invokeTauri('plugin:nativeblade-secure-storage|get_item', {
                key: payload.key,
            });
            value = result?.value ?? null;
        } catch (e) {
            console.warn('[NB Secure] get failed:', e);
        }
    }
    ctx.post('nativeblade-secure', { value, id: payload.id ?? null });
}

export async function forget_secure(payload, ctx) {
    if (!ctx.isMobile || !ctx.invokeTauri) return;
    try {
        await ctx.invokeTauri('plugin:nativeblade-secure-storage|remove_item', {
            key: payload.key,
        });
    } catch (e) {
        console.warn('[NB Secure] remove failed:', e);
    }
}
