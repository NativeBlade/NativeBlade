import { request } from '../runtime/wasm-server.js';

const TOKEN_ROUTE = '/_nativeblade/push-token';
const PUSH_ROUTE = '/_nativeblade/push';

async function postToPhp(route, payload) {
    try {
        await request(route, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        });
    } catch (e) {
        console.warn('[NativeBlade Push] failed to deliver to PHP:', e);
    }
}

function normalizePayload(raw) {
    if (raw == null) return null;
    if (typeof raw === 'string') {
        try {
            return JSON.parse(raw);
        } catch {
            return null;
        }
    }
    return raw;
}

export async function init() {
    if (!window.__TAURI_INTERNALS__) return;

    let listen, invoke;
    try {
        ({ listen } = await import('@tauri-apps/api/event'));
        ({ invoke } = await import('@tauri-apps/api/core'));
    } catch {
        return;
    }

    try {
        await listen('nativeblade-push-token', (event) => {
            const data = normalizePayload(event.payload);
            const token = data?.token;
            if (token) postToPhp(TOKEN_ROUTE, { token });
        });

        await listen('nativeblade-push', (event) => {
            const payload = normalizePayload(event.payload);
            if (payload) postToPhp(PUSH_ROUTE, payload);
        });
    } catch {
        return;
    }

    // Cold start drain: events that arrived before the listeners attached.
    try {
        const result = await invoke('plugin:nativeblade-push|drain_pending');
        const pending = result?.pending || [];
        for (const payload of pending) {
            await postToPhp(PUSH_ROUTE, payload);
        }
    } catch {}

    try {
        const tokenResult = await invoke('plugin:nativeblade-push|get_token');
        const token = tokenResult?.token;
        if (token) postToPhp(TOKEN_ROUTE, { token });
    } catch {}
}
