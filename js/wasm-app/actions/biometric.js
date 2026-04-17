// Biometric action
// Uses: ctx.biometricApi, ctx.post

export async function biometric(payload, ctx) {
    if (!ctx.biometricApi) return;
    try {
        const status = await ctx.biometricApi.checkStatus();
        if (!status.isAvailable) {
            ctx.post('nativeblade-biometric', {
                success: false,
                error: 'Biometric not available',
                id: payload.id || null,
            });
            return;
        }
        await ctx.biometricApi.authenticate(payload.reason || 'Authenticate', {
            allowDeviceCredential: payload.allowDeviceCredential ?? true,
        });
        ctx.post('nativeblade-biometric', { success: true, id: payload.id || null });
    } catch (err) {
        ctx.post('nativeblade-biometric', {
            success: false,
            error: err?.message || String(err),
            id: payload.id || null,
        });
    }
}
