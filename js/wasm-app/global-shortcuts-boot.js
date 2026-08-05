// Desktop-only global (system-wide) keyboard shortcuts -> nb:shortcut.
//
// Reads the accelerator=>name map from nativeblade-config.json (written by
// NativeBladeConfig::globalShortcuts([...])), registers each one with Tauri's
// global-shortcut plugin, and forwards each press into the app frame as
// nb:shortcut { name, accelerator }, which a component handles with
// #[On('nb:shortcut')]. No-op on mobile, in the browser, or when
// Plugin::GLOBAL_SHORTCUT isn't declared (the import throws and we bail).

import { postToApp } from './bridge.js';

export async function init() {
    // Tauri host only; the plugin is desktop-only.
    if (typeof window === 'undefined' || !window.__TAURI_INTERNALS__) return;

    let shortcuts;
    try {
        const r = await fetch('./nativeblade-config.json', { cache: 'no-store' });
        if (!r.ok) return;
        shortcuts = (await r.json())?.globalShortcuts;
    } catch {
        return;
    }
    if (!shortcuts || typeof shortcuts !== 'object' || !Object.keys(shortcuts).length) return;

    let gs;
    try {
        gs = await import('@tauri-apps/plugin-global-shortcut');
    } catch (e) {
        console.warn('[NB shortcuts] global-shortcut plugin unavailable:', e);
        return;
    }

    for (const [accelerator, name] of Object.entries(shortcuts)) {
        try {
            await gs.register(accelerator, (event) => {
                if (event && event.state && event.state !== 'Pressed') return;
                postToApp('nativeblade-shortcut', { name, accelerator });
            });
        } catch (e) {
            console.warn(`[NB shortcuts] could not register "${accelerator}":`, e);
        }
    }
}
