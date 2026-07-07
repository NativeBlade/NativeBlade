// Clipboard actions — read, write
// Uses: ctx.clipboardApi, ctx.post
//
// Outside Tauri (browser preview) the plugin call would hit an undefined
// invoke; fall back to the Web Clipboard API, which works on localhost.

export function clipboard_write(payload, ctx) {
    const text = payload.text || '';
    if (!ctx.isTauri || !ctx.clipboardApi) {
        if (typeof navigator !== 'undefined' && navigator.clipboard) {
            return navigator.clipboard.writeText(text).catch(() => {});
        }
        return;
    }
    return ctx.clipboardApi.writeText(text);
}

export function clipboard_read(payload, ctx) {
    const reply = (text) => ctx.post('nativeblade-clipboard', { text, id: payload.id || null });
    if (!ctx.isTauri || !ctx.clipboardApi) {
        if (typeof navigator !== 'undefined' && navigator.clipboard?.readText) {
            return navigator.clipboard.readText().then(reply).catch(() => reply(''));
        }
        return reply('');
    }
    return ctx.clipboardApi.readText().then(reply).catch(() => reply(''));
}
