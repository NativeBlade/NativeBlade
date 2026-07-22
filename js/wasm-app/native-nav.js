// Native transition compositor (optional NATIVE_NAV plugin, Android-only
// prototype). The router snapshots the outgoing page as a NATIVE overlay,
// swaps the DOM instantly beneath it, and the platform animates the overlay
// away in its own idiom — rendered by the OS compositor, immune to wasm
// main-thread jank. Self-detecting: the first failed snapshot (plugin absent,
// desktop, API < 26) disables the path for the session and the router keeps
// using its CSS transitions.

let available = null; // null = unprobed, then true/false

export async function nativeNavBegin(frame) {
    if (available === false) return false;
    if (typeof window === 'undefined' || !window.__TAURI_INTERNALS__) {
        available = false;
        return false;
    }
    // Desktop bypass — SYNCHRONOUS (bridge.js sets __NB_IS_MOBILE__ at boot).
    // No await here: this is the navigation hot path and must never block it.
    // On mobile we still try; the plugin's own Unsupported response self-detects
    // (Android works today; iOS bypasses now, works when its Swift side lands).
    if (!window.__NB_IS_MOBILE__) {
        available = false;
        return false;
    }
    try {
        const { invoke } = await import('@tauri-apps/api/core');
        const r = frame.getBoundingClientRect();
        await invoke('plugin:nativeblade-native-nav|snapshot', {
            x: r.left,
            y: r.top,
            width: r.width,
            height: r.height,
            dpr: window.devicePixelRatio || 1,
        });
        if (available !== true) console.info('[NB] native-nav active: transitions run on the OS compositor');
        available = true;
        return true;
    } catch (e) {
        // A failure on a previously-working session (transient) falls back to
        // CSS for this navigation only; an initial failure disables probing.
        if (available !== true) {
            available = false;
            console.info('[NB] native-nav unavailable, using CSS transitions:', e?.message || e);
        }
        return false;
    }
}

export async function nativeNavFinish(direction, duration) {
    try {
        const { invoke } = await import('@tauri-apps/api/core');
        await invoke('plugin:nativeblade-native-nav|animate', { direction, duration });
    } catch {
        try {
            const { invoke } = await import('@tauri-apps/api/core');
            await invoke('plugin:nativeblade-native-nav|cancel', {});
        } catch {}
    }
}
