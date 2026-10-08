// Explains a php-wasm crash that happened inside a native call. On the
// Asyncify build a suspension from a C path that is not instrumented ends
// the instance with "unreachable" (or "null function"); the PHP-side
// SuspendGuard refuses the known cases before pausing, and this is the
// message for one it did not know. Leaf module.

const CRASH_SIGNATURES = /unreachable|null function|asyncify|RuntimeError|memory access out of bounds/i;

/**
 * @param {unknown} err        what php.run() rejected with
 * @param {string|null} lastCall the native call in flight, from native-bridge.js
 * @returns {string|null} a message for the log and the dev overlay, or null when the error is not a runtime crash
 */
export function explainRuntimeCrash(err, lastCall) {
    const text = err && (err.message || String(err));
    if (!text || !CRASH_SIGNATURES.test(text)) return null;

    const where = lastCall ? ` during ${lastCall}` : '';
    return `PHP crashed${where} (${text.slice(0, 120)}). The runtime could not pause PHP at that point: `
        + 'a native call (HTTP, native database, native filesystem) was probably made inside a callback such as '
        + 'usort, json_encode or preg_replace_callback. Move the call out of the callback. '
        + 'The shell reloads once to recover.';
}
