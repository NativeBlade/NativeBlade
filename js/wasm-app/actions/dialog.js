// Dialog actions — alert, confirm
// Uses: ctx.dialogApi, ctx.isTauri, ctx.post

export function alert(payload, ctx) {
    const title = payload.title || 'NativeBlade';
    if (ctx.isTauri && ctx.dialogApi) {
        ctx.dialogApi.message(payload.message, { title, kind: payload.kind || 'info' });
    } else {
        ctx.post('nativeblade-alert', { message: payload.message });
    }
}

export function confirm(payload, ctx) {
    const title = payload.title || 'NativeBlade';
    if (ctx.isTauri && ctx.dialogApi) {
        ctx.dialogApi.confirm(payload.message, { title, kind: payload.kind || 'warning' })
            .then(confirmed => {
                ctx.post('nativeblade-confirm-result', { confirmed, id: payload.id || null });
            });
    } else {
        const confirmed = window.confirm(payload.message);
        ctx.post('nativeblade-confirm-result', { confirmed, id: payload.id || null });
    }
}
