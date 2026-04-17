// OS action — os_info
// Uses: ctx.osApi, ctx.post

export function os_info(payload, ctx) {
    if (!ctx.osApi) return;
    Promise.all([
        ctx.osApi.platform(),
        ctx.osApi.version(),
        ctx.osApi.arch(),
        ctx.osApi.locale(),
    ]).then(([platform, version, arch, locale]) => {
        ctx.post('nativeblade-os-info', { info: { platform, version, arch, locale } });
    });
}
