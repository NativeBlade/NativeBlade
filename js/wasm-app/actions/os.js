// OS action — os_info
// Uses: ctx.osApi, ctx.post
//
// Outside Tauri (browser preview) report what the browser knows instead of
// hitting an undefined invoke, so the app still gets a usable response.

export function os_info(payload, ctx) {
    if (!ctx.isTauri || !ctx.osApi) {
        const nav = typeof navigator !== 'undefined' ? navigator : {};
        ctx.post('nativeblade-os-info', {
            info: { platform: nav.platform || 'browser', version: '', arch: '', locale: nav.language || '' },
        });
        return;
    }
    return Promise.all([
        ctx.osApi.platform(),
        ctx.osApi.version(),
        ctx.osApi.arch(),
        ctx.osApi.locale(),
    ]).then(([platform, version, arch, locale]) => {
        ctx.post('nativeblade-os-info', { info: { platform, version, arch, locale } });
    }).catch(() => {});
}
