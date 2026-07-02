// Late purchase outcomes — pending payments (slow card, cash voucher, Ask to
// Buy approval) that completed while the app was closed, or purchases
// interrupted by a crash before acknowledgement. The native side settles them
// at boot (acknowledge/consume on Android, finish on iOS) and queues the
// outcomes; this drains the queue once the first page is up and delivers each
// as a late `nb:purchase-result` with `late: true`. The payload carries the
// same receipt/token as the original attempt, so grants can dedupe on it.

import { postToApp } from './bridge.js';

export async function init(appFrame) {
    if (!window.__TAURI_INTERNALS__) return;

    let invoke;
    try {
        ({ invoke } = await import('@tauri-apps/api/core'));
    } catch {
        return;
    }

    const drain = async () => {
        let results = [];
        try {
            const res = await invoke('plugin:nativeblade-payments|drain_pending');
            results = res?.results || [];
        } catch {
            return; // payments plugin not declared, or desktop
        }
        for (const r of results) {
            // postToApp follows router frame swaps, in case the user
            // navigated within the drain delay.
            postToApp('nativeblade-purchase-result', {
                success: !!r.success,
                status: r.status ?? 'purchased',
                receipt: r.receipt ?? null,
                token: r.token ?? null,
                signature: r.signature ?? null,
                productId: r.productId ?? null,
                error: null,
                late: true,
                id: null,
            });
        }
    };

    // Livewire inside the frame must be booted before the event can be heard;
    // give it a beat after the first page load.
    const drainSoon = () => setTimeout(drain, 800);
    if (appFrame.contentDocument?.body?.firstChild) {
        drainSoon();
    } else {
        const onLoad = () => {
            appFrame.removeEventListener('load', onLoad);
            drainSoon();
        };
        appFrame.addEventListener('load', onLoad);
    }
}
