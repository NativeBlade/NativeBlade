// Universal / app links: forwards incoming verified https links to PHP.
//
// Mirrors push.js: the native deep-link plugin hands us the URL (on cold
// start via getCurrent, while running via onOpenUrl). We POST it to the
// `/_nativeblade/deep-link` Laravel route, then dispatch whatever actions the
// PHP handler returns (typically a navigate). Screen-independent, so it works
// no matter which page is loaded — or none yet, on a cold launch.

import { request } from '../runtime/wasm-server.js';

const ROUTE = '/_nativeblade/deep-link';

let appFrameRef = null;
let handleAction = null;

function dispatchReturnedActions(result) {
    if (!result?.nativeblade || !handleAction) return;
    for (const item of result.nativeblade) {
        try {
            handleAction(item.action, item.data || {}, appFrameRef);
        } catch (e) {
            console.warn('[NB DeepLink] failed to dispatch action:', e);
        }
    }
}

async function deliver(url) {
    if (!url) return;
    try {
        const result = await request(ROUTE, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ url }),
        });
        dispatchReturnedActions(result);
    } catch (e) {
        console.warn('[NB DeepLink] failed to deliver to PHP:', e);
    }
}

function firstUrl(value) {
    return Array.isArray(value) ? value[0] : value;
}

export function setFrame(appFrame) {
    appFrameRef = appFrame;
}

export async function init(appFrame, handleNativeAction) {
    appFrameRef = appFrame;
    handleAction = handleNativeAction;

    if (!window.__TAURI_INTERNALS__) return;

    let onOpenUrl, getCurrent;
    try {
        ({ onOpenUrl, getCurrent } = await import('@tauri-apps/plugin-deep-link'));
    } catch (e) {
        console.warn('[NB DeepLink] plugin import failed:', e);
        return;
    }

    // Link received while the app is running.
    try {
        await onOpenUrl((urls) => deliver(firstUrl(urls)));
    } catch (e) {
        console.warn('[NB DeepLink] onOpenUrl failed:', e);
    }

    // Cold start: app was launched from a link.
    try {
        const current = await getCurrent();
        const url = firstUrl(current);
        if (url) deliver(url);
    } catch {}
}
