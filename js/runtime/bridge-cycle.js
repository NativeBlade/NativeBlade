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
 * @param {Function} [opts.onAbandon] called when the bridge gives up, before the caller is told
 */
export async function runBridgeCycle({ php, bridge, rerun, getCallback, onAbandon = null }) {
    const fulfilled = await bridge.fulfill(php);

    if (!fulfilled) {
        // The logical request is over without a result, so nothing of it may
        // survive into the next request (cached responses, retry counters).
        if (onAbandon) onAbandon();
        const cb = getCallback();
        if (cb) cb(BRIDGE_ABORTED);
        return;
    }

    const result = await rerun();
    if (result.bridgePending) return;

    const cb = getCallback();
    if (cb) cb(result);
}

/**
 * Decide what happens after one PHP execution: start a bridge cycle for the
 * bridge whose sentinel is in the output, or, when none is pending, let every
 * bridge clear its cache and counters because the request is really finished.
 *
 * A cache must live until the whole logical request is done, not until its
 * own bridge stops being the pending one. Clearing the HTTP cache the moment
 * the DB bridge takes over made the re-run repeat the HTTP call (and its side
 * effects) that had already been answered.
 *
 * @param {object} opts
 * @param {object} opts.php
 * @param {string} opts.text        PHP output of this execution
 * @param {object} opts.bridges     ordered `{ type: bridge }`; each bridge has hasPendingRequest/done
 * @param {Function} opts.startCycle `(type, bridge) => void`, starts the background cycle
 * @returns {Promise<boolean>} true when a bridge cycle was started
 */
export async function settleBridges({ php, text, bridges, startCycle }) {
    for (const [type, bridge] of Object.entries(bridges)) {
        if (await bridge.hasPendingRequest(php, text)) {
            startCycle(type, bridge);
            return true;
        }
    }

    for (const bridge of Object.values(bridges)) bridge.done(php);
    return false;
}
