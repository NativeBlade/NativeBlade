// AdMob actions — request_ad_consent, rewarded_ad, interstitial_ad
//
// Mobile only: drives the Google Mobile Ads SDK via the nativeblade-admob
// native plugin. On desktop every call posts a failure result so handler code
// runs unchanged. Outcomes are forwarded as nb:ad-reward and nb:ad-result.
//
// Uses: ctx.isMobile, ctx.invokeTauri, ctx.post

export async function request_ad_consent(payload, ctx) {
    if (!ctx.isMobile || !ctx.invokeTauri) return;
    try {
        await ctx.invokeTauri('plugin:nativeblade-admob|request_consent', {
            testDeviceIds: Array.isArray(payload.testDeviceIds) ? payload.testDeviceIds : [],
        });
    } catch (e) {
        console.warn('[NB AdMob] consent failed:', e);
    }
}

export async function rewarded_ad(payload, ctx) {
    const id = payload.id || null;
    if (!ctx.isMobile || !ctx.invokeTauri) {
        ctx.post('nativeblade-ad-result', { status: 'failed', error: 'unsupported', id });
        return;
    }
    try {
        const res = await ctx.invokeTauri('plugin:nativeblade-admob|show_rewarded', {
            unit: payload.unit,
            id,
        });
        ctx.post('nativeblade-ad-reward', {
            earned: !!res.earned,
            amount: res.amount ?? null,
            type: res.type ?? null,
            id,
        });
        ctx.post('nativeblade-ad-result', { status: res.status, error: res.error ?? null, id });
    } catch (e) {
        ctx.post('nativeblade-ad-result', { status: 'failed', error: String(e), id });
    }
}

export async function interstitial_ad(payload, ctx) {
    const id = payload.id || null;
    if (!ctx.isMobile || !ctx.invokeTauri) {
        ctx.post('nativeblade-ad-result', { status: 'failed', error: 'unsupported', id });
        return;
    }
    try {
        const res = await ctx.invokeTauri('plugin:nativeblade-admob|show_interstitial', {
            unit: payload.unit,
            id,
            minInterval: payload.minInterval ?? null,
        });
        ctx.post('nativeblade-ad-result', { status: res.status, error: res.error ?? null, id });
    } catch (e) {
        ctx.post('nativeblade-ad-result', { status: 'failed', error: String(e), id });
    }
}
