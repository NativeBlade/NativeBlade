// Network action — network_status
//
// Mobile: reads connectivity from the native plugin (ConnectivityManager /
// NWPathMonitor); `connected` means validated internet, not just an interface
// up. Desktop/web: answers from navigator.onLine with type 'unknown', so the
// same handler code runs everywhere. The result is forwarded as
// nb:network-status; live changes arrive on nb:network-changed with the same
// payload (see network-boot.js).
//
// Uses: ctx.isMobile, ctx.invokeTauri, ctx.post

export async function network_status(payload, ctx) {
    const id = payload.id || null;
    if (!ctx.isMobile || !ctx.invokeTauri) {
        ctx.post('nativeblade-network-status', {
            connected: typeof navigator !== 'undefined' ? !!navigator.onLine : true,
            type: 'unknown',
            metered: false,
            id,
        });
        return;
    }
    try {
        const res = await ctx.invokeTauri('plugin:nativeblade-network|get_status');
        ctx.post('nativeblade-network-status', {
            connected: !!res.connected,
            type: res.type ?? 'unknown',
            metered: !!res.metered,
            id,
        });
    } catch (e) {
        ctx.post('nativeblade-network-status', {
            connected: false,
            type: 'unknown',
            metered: false,
            error: String(e),
            id,
        });
    }
}
