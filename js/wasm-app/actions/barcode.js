// Barcode action — scan
// Uses: ctx.barcodeApi, ctx.post

const FORMAT_ALIASES = {
    QRCode: 'QR_CODE',
    QRCODE: 'QR_CODE',
    qrcode: 'QR_CODE',
    QR: 'QR_CODE',
    qr: 'QR_CODE',
    EAN13: 'EAN_13',
    EAN8: 'EAN_8',
    UPCA: 'UPC_A',
    UPCE: 'UPC_E',
    Code39: 'CODE_39',
    Code93: 'CODE_93',
    Code128: 'CODE_128',
    Codabar: 'CODABAR',
    DataMatrix: 'DATA_MATRIX',
    PDF417: 'PDF_417',
    Aztec: 'AZTEC',
    ITF: 'ITF',
};

function normalizeFormats(formats) {
    if (!Array.isArray(formats)) return [];
    return formats.map(f => FORMAT_ALIASES[f] || f);
}

export async function scan(payload, ctx) {
    if (!ctx.barcodeApi) return;
    try {
        let state = await ctx.barcodeApi.checkPermissions();
        if (state !== 'granted') {
            state = await ctx.barcodeApi.requestPermissions();
        }
        if (state !== 'granted') return;
        const result = await ctx.barcodeApi.scan({ formats: normalizeFormats(payload.formats) });
        ctx.post('nativeblade-scan', { result, id: payload.id || null });
    } catch (e) {
        console.warn('[NB Scan] failed:', e);
    }
}
