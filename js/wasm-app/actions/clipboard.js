// Clipboard actions — read, write
// Uses: ctx.clipboardApi, ctx.post

export function clipboard_write(payload, ctx) {
    if (!ctx.clipboardApi) return;
    ctx.clipboardApi.writeText(payload.text || '');
}

export function clipboard_read(payload, ctx) {
    if (!ctx.clipboardApi) return;
    ctx.clipboardApi.readText().then(text => {
        ctx.post('nativeblade-clipboard', { text, id: payload.id || null });
    });
}
