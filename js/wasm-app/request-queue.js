// The one serial queue every PHP request goes through.
//
// php-wasm runs one request at a time and a request may be suspended inside
// a native call, so two must never overlap on the shared instance. The lock
// inside php-wasm already prevents that, but handleRequest writes the
// request's $_SERVER and body to files before php-wasm takes its lock: a
// request that skipped this queue would overwrite the files of one still
// waiting for the lock, and that one would run with the wrong URL and body.
// Navigation, Livewire updates, the satellite relay, push, deep links,
// schedules and task results all come through here. Leaf module apart from
// the runtime entry point.

import { request } from '../runtime/wasm-server.js';
import { createSerialQueue } from './frame-request.js';

const enqueue = createSerialQueue();

/**
 * Run a request after every request already queued and resolve with the
 * final result (after the whole bridge cycle). A thrown error becomes a 500.
 */
export function requestFull(path, options = {}) {
    return enqueue(() => request(path, options).catch(
        (err) => ({ text: String(err && err.message || err), httpStatusCode: 500 })
    ));
}
