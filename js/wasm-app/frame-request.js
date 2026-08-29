// Serving a single `nativeblade-request` from a frame (a Livewire update or a
// GET), plus the serial queue that keeps bridge cycles from interleaving. Kept
// in its own leaf module — no runtime imports — so both can be unit-tested
// without the router's full module graph.

/**
 * A FIFO serializer. Each enqueued task runs only after the previous one has
 * fully settled, so two request+bridge cycles never interleave their re-runs
 * through the shared php-wasm instance (the bridges use shared temp files, cache
 * dirs and counters, which a true overlap would corrupt).
 *
 * `enqueue(task)` returns the task's own result; a rejected task neither breaks
 * the chain nor leaks an unhandled rejection into the queue's bookkeeping.
 */
export function createSerialQueue() {
    let tail = Promise.resolve();
    return function enqueue(task) {
        const result = tail.then(task, task);
        tail = result.then(() => {}, () => {});
        return result;
    };
}

/**
 * Run one frame request and deliver its response to `source`.
 *
 * `requestFull` runs the request through the shared serial queue and resolves
 * with the FINAL result (after the whole bridge cycle), so overlapping requests
 * are serialized rather than racing. A delivery whose navigation generation is
 * no longer current is dropped, since the frame may now show a different page
 * and a stale response would corrupt it.
 *
 * init()'s message handler is the only production caller.
 */
export async function serveFrameRequest({ id, path, options, source, requestFull, schedulePersist, getGeneration }) {
    const generation = getGeneration();

    let result;
    try {
        result = await requestFull(path, options);
    } catch (err) {
        result = { text: (err && err.message) || String(err), httpStatusCode: 500 };
    }

    if (getGeneration() !== generation) return;

    try {
        source.postMessage({
            type: 'nativeblade-response', id,
            result: { text: result.text, httpStatusCode: result.httpStatusCode },
        }, '*');
    } catch {}

    if (result.httpStatusCode !== 500 && options.method && options.method !== 'GET') {
        schedulePersist();
    }
}
