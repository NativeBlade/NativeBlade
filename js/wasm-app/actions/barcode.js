// Barcode action — scan
// Uses: ctx.barcodeApi, ctx.post

export async function scan(payload, ctx) {
    if (!ctx.barcodeApi) return;
    try {
        let state = await ctx.barcodeApi.checkPermissions();
        if (state !== 'granted') {
            state = await ctx.barcodeApi.requestPermissions();
        }
        if (state !== 'granted') return;
        const result = await ctx.barcodeApi.scan({ formats: payload.formats || [] });
        ctx.post('nativeblade-scan', { result, id: payload.id || null });
    } catch {}
}
