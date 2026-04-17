// Geolocation action
// Uses: ctx.geolocationApi, ctx.post

export async function geolocation(payload, ctx) {
    if (!ctx.geolocationApi) return;
    try {
        let state = await ctx.geolocationApi.checkPermissions();
        if (state.location !== 'granted') {
            state = await ctx.geolocationApi.requestPermissions(['location']);
        }
        if (state.location !== 'granted') return;
        const pos = await ctx.geolocationApi.getCurrentPosition();
        ctx.post('nativeblade-geolocation', { position: pos, id: payload.id || null });
    } catch {}
}
