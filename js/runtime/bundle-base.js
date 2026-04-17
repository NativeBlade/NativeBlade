// Resolves the base URL where the Laravel bundle and its companion files
// (/laravel-bundle.json(.gz), /lang/*.json, /__php_changes, /__php_version)
// live. In normal builds this is "./" (same origin as the app HTML). In the
// Portal flow, the installed app reads a URL that the user pasted or scanned
// and persists it in localStorage under "nb:bundleBase".
//
// The function is isolated from php-runtime.js on purpose: it's also imported
// by the Node-side test suite, which cannot resolve the @php-wasm packages.

export function getBundleBase() {
    let raw = null;

    if (typeof window !== 'undefined') {
        const fromGlobal = window.__NB_BUNDLE_BASE__;
        if (fromGlobal != null) {
            raw = fromGlobal;
        } else {
            try {
                raw = window.localStorage?.getItem?.('nb:bundleBase') ?? null;
            } catch {
                raw = null;
            }
        }
    }

    let base = (typeof raw === 'string' && raw.length) ? raw : './';
    if (!base.endsWith('/')) base += '/';
    return base;
}
