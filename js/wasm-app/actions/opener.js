// Opener actions — open_url, open_file
// Uses: ctx.openerApi
//
// Outside Tauri (browser preview) opening a URL falls back to window.open;
// opening a local file path has no browser equivalent, so it's a no-op.

export function open_url(payload, ctx) {
    const url = payload.url || '';
    if (!ctx.isTauri || !ctx.openerApi) {
        if (url && typeof window !== 'undefined') window.open(url, '_blank', 'noopener');
        return;
    }
    return ctx.openerApi.openUrl(url);
}

export function open_file(payload, ctx) {
    if (!ctx.isTauri || !ctx.openerApi) return; // no browser equivalent
    return ctx.openerApi.openPath(payload.path || '');
}
