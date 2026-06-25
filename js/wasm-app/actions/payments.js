// Payments actions — query_products, purchase, restore_purchases, subscription_status
//
// Mobile only: drives StoreKit 2 (iOS) and Play Billing (Android) through the
// nativeblade-payments native plugin. On desktop there is no store billing, so
// a purchase with an external(...) checkout URL is opened in the system browser
// and every other call reports an empty/failure event so handler code runs
// unchanged. Outcomes are forwarded as nb:products, nb:purchase-result,
// nb:purchases-restored and nb:subscription-status.
//
// Uses: ctx.isMobile, ctx.invokeTauri, ctx.openerApi, ctx.post

export async function query_products(payload, ctx) {
    const id = payload.id || null;
    if (!ctx.isMobile || !ctx.invokeTauri) {
        ctx.post('nativeblade-products', { products: [], error: 'unsupported', id });
        return;
    }
    try {
        const res = await ctx.invokeTauri('plugin:nativeblade-payments|query_products', {
            products: Array.isArray(payload.products) ? payload.products : [],
            id,
        });
        ctx.post('nativeblade-products', { products: res.products ?? [], error: res.error ?? null, id });
    } catch (e) {
        ctx.post('nativeblade-products', { products: [], error: String(e), id });
    }
}

export async function purchase(payload, ctx) {
    const id = payload.id || null;
    if (!ctx.isMobile || !ctx.invokeTauri) {
        if (payload.external && ctx.openerApi) {
            ctx.openerApi.openUrl(payload.external);
            ctx.post('nativeblade-purchase-result', { success: false, status: 'external', id });
        } else {
            ctx.post('nativeblade-purchase-result', { success: false, status: 'failed', error: 'unsupported', id });
        }
        return;
    }
    try {
        const res = await ctx.invokeTauri('plugin:nativeblade-payments|purchase', {
            product: payload.product,
            id,
            consumable: !!payload.consumable,
            external: payload.external ?? null,
        });
        ctx.post('nativeblade-purchase-result', {
            success: !!res.success,
            status: res.status ?? null,
            receipt: res.receipt ?? null,
            productId: res.productId ?? null,
            error: res.error ?? null,
            id,
        });
    } catch (e) {
        ctx.post('nativeblade-purchase-result', { success: false, status: 'failed', error: String(e), id });
    }
}

export async function restore_purchases(payload, ctx) {
    const id = payload.id || null;
    if (!ctx.isMobile || !ctx.invokeTauri) {
        ctx.post('nativeblade-purchases-restored', { purchases: [], error: 'unsupported', id });
        return;
    }
    try {
        const res = await ctx.invokeTauri('plugin:nativeblade-payments|restore_purchases', { id });
        ctx.post('nativeblade-purchases-restored', { purchases: res.purchases ?? [], error: res.error ?? null, id });
    } catch (e) {
        ctx.post('nativeblade-purchases-restored', { purchases: [], error: String(e), id });
    }
}

export async function subscription_status(payload, ctx) {
    const id = payload.id || null;
    if (!ctx.isMobile || !ctx.invokeTauri) {
        ctx.post('nativeblade-subscription-status', { entitlements: [], error: 'unsupported', id });
        return;
    }
    try {
        const res = await ctx.invokeTauri('plugin:nativeblade-payments|subscription_status', {
            products: Array.isArray(payload.products) ? payload.products : [],
            id,
        });
        ctx.post('nativeblade-subscription-status', { entitlements: res.entitlements ?? [], error: res.error ?? null, id });
    } catch (e) {
        ctx.post('nativeblade-subscription-status', { entitlements: [], error: String(e), id });
    }
}
