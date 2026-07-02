// Live connectivity changes → nb:network-changed.
//
// On mobile the native plugin watches the default network for the whole app
// lifetime and triggers a deduped `network-changed` plugin event; this
// forwards each one into the app frame. When the plugin isn't available
// (desktop, web, or Plugin::NETWORK not declared), the browser's
// online/offline events fill in with type 'unknown', so handler code runs
// unchanged everywhere.

import { postToApp } from './bridge.js';

export async function init() {
    if (window.__TAURI_INTERNALS__) {
        try {
            const { addPluginListener } = await import('@tauri-apps/api/core');
            await addPluginListener('nativeblade-network', 'network-changed', (status) => {
                postToApp('nativeblade-network-changed', {
                    connected: !!status?.connected,
                    type: status?.type ?? 'unknown',
                    metered: !!status?.metered,
                });
            });
            return;
        } catch {
            // Plugin not declared or not compiled on this platform — fall
            // through to the browser events.
        }
    }

    const emit = () => postToApp('nativeblade-network-changed', {
        connected: !!navigator.onLine,
        type: 'unknown',
        metered: false,
    });
    window.addEventListener('online', emit);
    window.addEventListener('offline', emit);
}
