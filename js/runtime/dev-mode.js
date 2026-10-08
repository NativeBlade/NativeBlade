// Development mode means the shell was served by `nativeblade:dev`: the Vite
// plugin injects a <meta name="nativeblade-vite-url"> tag into the page, and a
// store build never has it. APP_DEBUG cannot be used for this because the
// runtime forces it on inside wasm (see filesystem.js patchEnv).
//
// Leaf module: no runtime imports.

export const DEV_META_SELECTOR = 'meta[name="nativeblade-vite-url"]';

export function isDevMode(doc = (typeof document !== 'undefined' ? document : null)) {
    if (!doc || typeof doc.querySelector !== 'function') return false;
    try {
        return !!doc.querySelector(DEV_META_SELECTOR);
    } catch {
        return false;
    }
}
