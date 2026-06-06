// Auto screen tracking for Firebase Analytics.
//
// The native SDK cannot see navigations inside NativeBlade's single WebView,
// so the router calls this on each page render. It is gated by the
// autoScreenTracking flag (written into nativeblade-config.json by
// NativeBladeConfig::analytics(...)) and is a no-op outside Tauri or when the
// flag is off, so importing it is always safe.

let enabledFlag = null;

async function autoScreensEnabled() {
    if (enabledFlag !== null) return enabledFlag;
    enabledFlag = false;
    try {
        if (typeof window !== 'undefined' && window.__NB_ANALYTICS__) {
            enabledFlag = !!window.__NB_ANALYTICS__.autoScreenTracking;
            return enabledFlag;
        }
        const r = await fetch('./nativeblade-config.json', { cache: 'no-store' });
        if (r.ok) {
            const json = await r.json();
            enabledFlag = !!json?.analytics?.autoScreenTracking;
        }
    } catch {}
    return enabledFlag;
}

export async function logScreenIfEnabled(path) {
    if (typeof window === 'undefined' || !window.__TAURI_INTERNALS__) return;
    if (!(await autoScreensEnabled())) return;
    try {
        const { invoke } = await import('@tauri-apps/api/core');
        await invoke('plugin:nativeblade-analytics|apply', {
            ops: [{ op: 'screen', name: path }],
        });
    } catch {}
}
