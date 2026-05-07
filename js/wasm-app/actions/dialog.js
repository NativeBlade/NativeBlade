// Dialog actions — alert, confirm
// Uses: ctx.dialogApi, ctx.isTauri, ctx.post

// The Tauri dialog plugin only accepts these lowercase kinds.
const DIALOG_KINDS = new Set(['info', 'warning', 'error']);

function normalizeKind(kind, fallback) {
    const k = (kind || fallback).toString().toLowerCase();
    return DIALOG_KINDS.has(k) ? k : fallback;
}

export function alert(payload, ctx) {
    const title = payload.title || 'NativeBlade';
    if (ctx.isTauri && ctx.dialogApi) {
        ctx.dialogApi.message(payload.message, { title, kind: normalizeKind(payload.kind, 'info') })
            .catch(e => console.warn('[NB Dialog] alert failed:', e));
    } else {
        ctx.post('nativeblade-alert', { message: payload.message });
    }
}

export function confirm(payload, ctx) {
    const title = payload.title || 'NativeBlade';
    if (ctx.isTauri && ctx.dialogApi) {
        ctx.dialogApi.confirm(payload.message, { title, kind: normalizeKind(payload.kind, 'warning') })
            .then(confirmed => {
                ctx.post('nativeblade-confirm-result', { confirmed, id: payload.id || null });
            })
            .catch(e => {
                console.warn('[NB Dialog] confirm failed:', e);
                ctx.post('nativeblade-confirm-result', { confirmed: false, id: payload.id || null });
            });
    } else {
        const confirmed = window.confirm(payload.message);
        ctx.post('nativeblade-confirm-result', { confirmed, id: payload.id || null });
    }
}
