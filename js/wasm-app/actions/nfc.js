// NFC action — nfc_read
// Uses: ctx.nfcApi, ctx.post

export async function nfc_read(payload, ctx) {
    if (!ctx.nfcApi) return;
    try {
        const available = await ctx.nfcApi.isAvailable();
        if (!available) return;
        const tag = await ctx.nfcApi.scan({ type: 'ndef' });
        ctx.post('nativeblade-nfc', { tag, id: payload.id || null });
    } catch (e) {
        console.warn('[NB Nfc] read failed:', e);
    }
}
