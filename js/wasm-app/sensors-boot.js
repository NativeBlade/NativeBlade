// Live sensor stream → nb:sensor-changed.
//
// Watches started with NativeBlade::watchSensor() emit deduped/throttled
// readings through the plugin's `sensor-changed` event; this forwards each
// into the app frame. Registered once at boot; a no-op while nothing is
// being watched (and on desktop, where the plugin does not exist).

import { postToApp } from './bridge.js';

export async function init() {
    if (!window.__TAURI_INTERNALS__) return;
    try {
        const { addPluginListener } = await import('@tauri-apps/api/core');
        await addPluginListener('nativeblade-sensors', 'sensor-changed', (reading) => {
            postToApp('nativeblade-sensor-changed', reading ?? {});
        });
    } catch {
        // Plugin::SENSORS not declared, or desktop build — nothing to stream.
    }
}
