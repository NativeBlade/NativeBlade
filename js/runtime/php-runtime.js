import { PHP, loadPHPRuntime } from '@php-wasm/universal';
import { getPHPLoaderModule } from '@nativeblade-php-loader';

let php = null;
let runtimeKind = null;

export async function initRuntime() {
    if (php) return php;

    applyForcedRuntime();
    const loaderModule = await getPHPLoaderModule();
    runtimeKind = typeof WebAssembly.Suspending === 'function' ? 'jspi' : 'asyncify';
    console.info(`[NB] php-wasm runtime: ${runtimeKind}`);

    const runtimeId = await loadPHPRuntime(loaderModule);
    php = new PHP(runtimeId);

    return php;
}

export function getInstance() {
    return php;
}

/** 'jspi' or 'asyncify': how PHP suspends inside native calls; null before boot. */
export function getRuntimeKind() {
    return runtimeKind;
}

/**
 * php-wasm picks the JSPI build when the WebView supports it and the
 * Asyncify build otherwise. To try the Asyncify build where JSPI exists (to
 * reproduce an iOS or macOS problem on a desktop), open the dev server with
 * `?php=asyncify` or set `localStorage['nb:php-runtime'] = 'asyncify'` and
 * reload. Development only; a store build ignores both.
 */
function applyForcedRuntime() {
    if (typeof window === 'undefined') return;
    let forced = null;
    try {
        forced = new URLSearchParams(window.location.search).get('php')
            || window.localStorage.getItem('nb:php-runtime');
    } catch {}
    if (forced !== 'asyncify') return;
    if (!document.querySelector('meta[name="nativeblade-vite-url"]')) return;
    if (typeof WebAssembly.Suspending === 'function') {
        try {
            delete WebAssembly.Suspending;
            console.warn('[NB] php-wasm runtime forced to asyncify (dev switch)');
        } catch {}
    }
}
