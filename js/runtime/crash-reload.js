// Reloading the shell after a php-wasm crash, with a cap.
//
// A crashed instance cannot run PHP again (php-wasm disables every method on
// it), so the shell reloads to get a fresh one. Reloading without a cap turns
// a crash that repeats on the first page into a loop, so at most MAX_RELOADS
// per WINDOW_MS; after that the crash is only reported. The counter lives in
// sessionStorage: it survives the reload and dies with the WebView. Leaf
// module, so the policy can be unit-tested.

export const MAX_RELOADS = 3;
export const WINDOW_MS = 2 * 60 * 1000;
const KEY = 'nb:crash-reloads';

/**
 * Record a crash and say whether the shell may reload for it.
 *
 * @param {{getItem: Function, setItem: Function}|null} storage sessionStorage, or null when unavailable
 * @param {number} now Date.now()
 * @returns {boolean} true when the shell should reload
 */
export function shouldReloadAfterCrash(storage, now = Date.now()) {
    if (!storage) return false;

    let state = null;
    try { state = JSON.parse(storage.getItem(KEY) || 'null'); } catch {}
    if (!state || typeof state.since !== 'number' || typeof state.count !== 'number' || now - state.since >= WINDOW_MS) {
        state = { since: now, count: 0 };
    }
    if (state.count >= MAX_RELOADS) return false;

    state.count += 1;
    try { storage.setItem(KEY, JSON.stringify(state)); } catch { return false; }
    return true;
}
