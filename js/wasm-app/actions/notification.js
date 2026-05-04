// Notification action
// Uses: ctx.notificationApi, ctx.isTauri, ctx.isAndroid, ctx.ensureChannel, ctx.post

export async function notification(payload, ctx) {
    const title = payload.title || 'NativeBlade';
    if (ctx.isTauri && ctx.notificationApi) {
        let granted = false;
        try {
            granted = await ctx.notificationApi.isPermissionGranted();
        } catch {}

        if (!granted) {
            try {
                const perm = await ctx.notificationApi.requestPermission();
                granted = perm === 'granted';
            } catch {
                granted = false;
            }
        }

        if (!granted) {
            ctx.post('nativeblade-alert', { message: payload.body });
            return;
        }

        const opts = { title, body: payload.body || '' };
        if (payload.sound) opts.sound = payload.sound;
        if (payload.icon) {
            opts.icon = payload.icon;
        } else if (ctx.isAndroid) {
            opts.icon = 'ic_notification';
        }
        if (payload.channel) {
            opts.channelId = payload.channel;
            await ctx.ensureChannel(payload.channel);
        }

        try {
            ctx.notificationApi.sendNotification(opts);
        } catch {
            ctx.post('nativeblade-alert', { message: payload.body });
        }
    } else {
        ctx.post('nativeblade-alert', { message: payload.body });
    }
}
