import { request } from '../runtime/wasm-server.js';

const TOKEN_ROUTE = '/_nativeblade/push-token';
const PUSH_ROUTE = '/_nativeblade/push';

let appFrameRef = null;
let handleAction = null;

function dispatchReturnedActions(result) {
    if (!result?.nativeblade || !handleAction) return;
    for (const item of result.nativeblade) {
        try {
            handleAction(item.action, item.data || {}, appFrameRef);
        } catch (e) {
            console.warn('[NativeBlade Push] failed to dispatch action:', e);
        }
    }
}

async function postToPhp(route, payload) {
    try {
        const result = await request(route, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        });
        dispatchReturnedActions(result);
    } catch (e) {
        console.warn('[NB Push] failed to deliver to PHP:', e);
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

export function setFrame(appFrame) {
    appFrameRef = appFrame;
}

export async function init(appFrame, handleNativeAction) {
    appFrameRef = appFrame;
    handleAction = handleNativeAction;

    if (!window.__TAURI_INTERNALS__) return;

    let listen, invoke;
    try {
        ({ listen } = await import('@tauri-apps/api/event'));
        ({ invoke } = await import('@tauri-apps/api/core'));
    } catch (e) {
        console.warn('[NB Push] tauri api import failed:', e);
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
    } catch (e) {
        console.warn('[NB Push] listen() failed:', e);
        return;
    }

    const drainAfterReady = async () => {
        // Ask for notification permission here, not in the native load(): this
        // runs after the splash is gone, so the dialog no longer pops over a
        // black screen mid-boot.
        try {
            await invoke('plugin:nativeblade-push|request_permission');
        } catch (e) {
            console.warn('[NB Push] request_permission failed:', e);
        }

        try {
            const result = await invoke('plugin:nativeblade-push|drain_pending');
            const pending = result?.pending || [];
            for (const payload of pending) {
                await postToPhp(PUSH_ROUTE, payload);
            }
        } catch (e) {
            console.warn('[NB Push] drain_pending failed:', e);
        }

        for (let i = 0; i < 10; i++) {
            try {
                const tokenResult = await invoke('plugin:nativeblade-push|get_token');
                const token = tokenResult?.token;
                if (token) {
                    postToPhp(TOKEN_ROUTE, { token });
                    break;
                }
            } catch (e) {
                console.warn('[NB Push] get_token failed:', e);
                break;
            }
            await new Promise(r => setTimeout(r, 500));
        }
    };

    if (appFrame.contentDocument?.body?.firstChild) {
        // Iframe already has content — first load already happened.
        drainAfterReady();
    } else {
        const onLoad = () => {
            appFrame.removeEventListener('load', onLoad);
            drainAfterReady();
        };
        appFrame.addEventListener('load', onLoad);
    }
}
