// One bridge cycle: fulfil what PHP asked for (HTTP, DB or FS), re-run the PHP
// request so it can read the result, and hand the final result to whoever is
// waiting for it.
//
// Leaf module (no runtime imports) so the cycle can be unit-tested with fake
// bridges and a fake re-run, the same way frame-request.js is.

/**
 * Result delivered when the bridge could not complete the cycle: the fetches
 * were aborted by a navigation, the retry budget ran out, or the pending file
 * was unreadable. Callers treat it like a failed request.
 */
export const BRIDGE_ABORTED = Object.freeze({ text: '', errors: '', httpStatusCode: 0, aborted: true });

/**
 * @param {object} opts
 * @param {object} opts.php          php-wasm instance
 * @param {object} opts.bridge       module with `fulfill(php) -> Promise<boolean>`
 * @param {Function} opts.rerun      re-runs the original request; resolves with its result
 * @param {Function} opts.getCallback returns the completion callback, read after the cycle
 *                                    (the main window's callback is a mutable global)
 */
export async function runBridgeCycle({ php, bridge, rerun, getCallback }) {
    const fulfilled = await bridge.fulfill(php);

    if (!fulfilled) {
        const cb = getCallback();
        if (cb) cb(BRIDGE_ABORTED);
        return;
    }

    const result = await rerun();
    if (result.bridgePending) return;

    const cb = getCallback();
    if (cb) cb(result);
}
