function sanitize(payload) {
    const out = {};
    for (const [key, value] of Object.entries(payload || {})) {
        if (value !== undefined && value !== null) {
            out[key] = value;
        }
    }
    return out;
}

function postFailure(ctx, payload, error = 'admob is not supported on this platform') {
    ctx.post('nativeblade-ad-result', {
        status: 'failed',
        error,
        id: payload?.id ?? null,
    });
}

function emitReward(ctx, payload, result) {
    const reward = result?.reward;
    if (!reward || reward.earned !== true) return;

    ctx.post('nativeblade-ad-reward', {
        earned: true,
        amount: reward.amount ?? null,
        type: reward.type ?? null,
        id: result?.id ?? payload?.id ?? null,
    });
}

function emitResult(ctx, payload, result, fallbackStatus = 'dismissed') {
    ctx.post('nativeblade-ad-result', {
        status: result?.status ?? fallbackStatus,
        error: result?.error ?? null,
        id: result?.id ?? payload?.id ?? null,
    });
}

export async function request_ad_consent(_payload, ctx) {
    if (!ctx.isMobile || !ctx.invokeTauri) return;
    try {
        await ctx.invokeTauri('plugin:nativeblade-admob|request_ad_consent', {});
    } catch (e) {
        console.warn('[NB AdMob] consent failed:', e);
    }
}

export async function rewarded_ad(payload, ctx) {
    if (!ctx.isMobile || !ctx.invokeTauri) {
        postFailure(ctx, payload);
        return;
    }

    try {
        const result = await ctx.invokeTauri('plugin:nativeblade-admob|show_rewarded', sanitize(payload));
        emitReward(ctx, payload, result);
        emitResult(ctx, payload, result);
    } catch (e) {
        console.warn('[NB AdMob] rewarded failed:', e);
        postFailure(ctx, payload, e?.message || String(e));
    }
}

export async function interstitial_ad(payload, ctx) {
    if (!ctx.isMobile || !ctx.invokeTauri) {
        postFailure(ctx, payload);
        return;
    }

    try {
        const result = await ctx.invokeTauri('plugin:nativeblade-admob|show_interstitial', sanitize(payload));
        emitResult(ctx, payload, result);
    } catch (e) {
        console.warn('[NB AdMob] interstitial failed:', e);
        postFailure(ctx, payload, e?.message || String(e));
    }
}
