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
        available = true;
        return true;
    } catch (e) {
        // A failure on a previously-working session (transient) falls back to
        // CSS for this navigation only; an initial failure disables probing.
        if (available !== true) available = false;
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
