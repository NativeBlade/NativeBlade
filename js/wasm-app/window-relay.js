// IPC relay between a satellite window and the main window's php-wasm (WINDOWS.md
// slice 2). The satellite has no php-wasm; its app iframe's requests are relayed
// over Tauri events to the main window, serviced there, and the result relayed
// back. Correlated by reqId. The interceptor and livewire.js never know the
// response crossed a window boundary.

let reqSeq = 1;
const pending = new Map();
let responseListener = null;

// --- satellite side ------------------------------------------------------

async function ensureResponseListener() {
    if (responseListener) return responseListener;
    responseListener = (async () => {
        const { listen } = await import('@tauri-apps/api/event');
        await listen('nb-window-response', (event) => {
            const { reqId, result } = event.payload || {};
            const resolve = pending.get(reqId);
            if (resolve) { pending.delete(reqId); resolve(result); }
        });
    })();
    return responseListener;
}

/** Relay one request to the main runtime and await its result. */
export async function relayRequest(path, options) {
    await ensureResponseListener();
    const { emit } = await import('@tauri-apps/api/event');
    // Prefix with THIS window's id: nb-window-response is broadcast to every
    // satellite, so reqIds must be globally unique or window A resolves window
    // B's response (both would otherwise start at s1).
    const prefix = (typeof window !== 'undefined' && window.__NB_SATELLITE__) || 'w';
    const reqId = prefix + '-s' + (reqSeq++);
    const p = new Promise((resolve) => pending.set(reqId, resolve));
    await emit('nb-window-request', { reqId, path, options: options || {} });
    return p;
}

// --- main side -----------------------------------------------------------

/** Service satellite requests on this window's runtime. `request` should await
 *  bridge (Http/DB/FS) fulfillment (pass router's `requestFull`), so a satellite
 *  component can use the database, filesystem, and HTTP — the native work runs
 *  here, on the main window's runtime. */
export async function serveWindowRequests(request) {
    const { listen, emit } = await import('@tauri-apps/api/event');
    await listen('nb-window-request', async (event) => {
        const { reqId, path, options } = event.payload || {};
        try {
            const result = await request(path, options || {});
            await emit('nb-window-response', { reqId, result });
        } catch (e) {
            await emit('nb-window-response', { reqId, result: { text: String(e && e.message || e), httpStatusCode: 500 } });
        }
    });
}
