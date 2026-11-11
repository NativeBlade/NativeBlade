// Explains a php-wasm crash: the instance ended with a wasm trap instead of
// a PHP exit code. On the Asyncify build (used where the WebView has no
// JSPI) that is what a suspension through a C path that is not instrumented
// looks like; the PHP-side SuspendGuard refuses the known cases before
// pausing, and this is the message for the rest. Leaf module.

const CRASH_SIGNATURES = /unreachable|null function|asyncify|RuntimeError|memory access out of bounds/i;

/**
 * @param {unknown} err        what php.run() rejected with
 * @param {string|null} lastCall the native call in flight, from native-bridge.js; null when the bridge was never used since the page loaded
 * @param {{runtime?: string|null, missing?: string[]|null}} [details] the php-wasm build ('jspi' or 'asyncify') and the functions php-wasm reports as missing from the Asyncify instrumentation after the crash
 * @returns {string|null} a message for the log and the dev overlay, or null when the error is not a runtime crash
 */
export function explainRuntimeCrash(err, lastCall, details = {}) {
    const text = err && (err.message || String(err));
    if (!text || !CRASH_SIGNATURES.test(text)) return null;

    const reason = text.slice(0, 120);
    const parts = [];

    if (lastCall) {
        parts.push(`PHP crashed during ${lastCall} (${reason}). The runtime could not pause PHP at that point: `
            + 'the native call was probably made inside a callback such as usort, json_encode or preg_replace_callback. '
            + 'Move the call out of the callback.');
    } else if (details.runtime === 'jspi') {
        parts.push(`PHP crashed (${reason}). No native call was in flight and the JSPI build was running, so pausing is not the cause: `
            + 'look for a PHP stack overflow (deep or infinite recursion) in the request that crashed.');
    } else {
        parts.push(`PHP crashed (${reason}). No NativeBlade native call (HTTP, native database, native filesystem) was in flight, `
            + 'so this is not a native call inside a callback. On the Asyncify build PHP also cannot be paused inside php-wasm\'s own '
            + 'suspension points: sleep, usleep, socket polling (file_get_contents on a URL, fsockopen) and proc_open. '
            + 'Look for those, or for a PHP stack overflow, in the request that crashed.');
    }

    if (Array.isArray(details.missing) && details.missing.length) {
        parts.push(`php-wasm reports these functions as missing from the Asyncify instrumentation: ${details.missing.slice(0, 10).join(', ')}.`);
    }

    return parts.join(' ');
}
