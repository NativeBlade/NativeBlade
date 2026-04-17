// Opener actions — open_url, open_file
// Uses: ctx.openerApi

export function open_url(payload, ctx) {
    if (!ctx.openerApi) return;
    ctx.openerApi.openUrl(payload.url || '');
}

export function open_file(payload, ctx) {
    if (!ctx.openerApi) return;
    ctx.openerApi.openPath(payload.path || '');
}
