// Navigation notifications for shell-side code (shell modules, components).
// The router emits once per completed navigation, after the new page is on
// screen; subscribers get `{ path, from, direction, transition }`.
//
// Leaf module (no runtime imports) so it can be unit-tested without the
// router's module graph.

const listeners = new Set();

/**
 * Subscribe to completed navigations. Returns an unsubscribe function.
 *
 * @param {(detail: {path: string, from: string|null, direction: 'forward'|'back', transition: string}) => void} callback
 */
export function onNavigate(callback) {
    if (typeof callback !== 'function') {
        throw new TypeError('nb.onNavigate expects a function');
    }
    listeners.add(callback);
    return () => { listeners.delete(callback); };
}

/**
 * Called by the router once a navigation rendered. Each listener runs in its
 * own try/catch: a broken listener must not break the others or the router.
 * Also dispatched as the `nb:navigate` DOM event on the shell window, for code
 * that cannot import the router.
 */
export function emitNavigation(detail) {
    for (const listener of [...listeners]) {
        try { listener(detail); } catch (err) { console.error('[NativeBlade] nb.onNavigate listener failed:', err); }
    }

    if (typeof window !== 'undefined' && typeof window.dispatchEvent === 'function' && typeof CustomEvent === 'function') {
        try { window.dispatchEvent(new CustomEvent('nb:navigate', { detail })); } catch {}
    }
}

export function __resetForTests() { listeners.clear(); }
