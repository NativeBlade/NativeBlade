// The JavaScript end of NativeBridge (PHP).
//
// PHP calls post_message_to_js() with `{"nativeblade": "<type>", ...}`.
// php-wasm runs with JSPI or Asyncify, so the PHP stack is suspended until
// the listener's promise settles and resumes with the string it returns.
// The request therefore runs once, top to bottom: no cache, no re-run.
//
// Reply: `{"ok": true, "result": ...}` or `{"ok": false, "error": "..."}`.
// Messages that are not NativeBlade's get '' back, which php-wasm treats as
// "not handled here".

import * as http from './http-bridge.js';
import * as db from './db-bridge.js';
import * as fs from './fs-bridge.js';

export const EXECUTORS = {
    http: http.execute,
    http_pool: http.executePool,
    db: db.execute,
    fs: fs.execute,
};

const installed = new WeakSet();

// The native call PHP is in, or was last in. When the PHP instance dies
// inside a call (an Asyncify build suspended where it cannot), this is the
// only clue the shell has about where.
let lastCall = null;

export function lastNativeCall() {
    return lastCall;
}

export function describeCall(message) {
    switch (message.nativeblade) {
        case 'http': return `HTTP ${message.method || 'GET'} ${message.url || ''}`.trim();
        case 'http_pool': return `HTTP pool of ${Array.isArray(message.requests) ? message.requests.length : 0} requests`;
        case 'db': return `query ${String(message.sql || '').slice(0, 120)}`.trim();
        case 'fs': return `filesystem ${message.op || ''} ${message.path || ''}`.trim();
        default: return `native call ${message.nativeblade}`;
    }
}

/** Register the bridge on a php-wasm instance; idempotent. */
export function installNativeBridge(php, executors = EXECUTORS) {
    if (!php || typeof php.onMessage !== 'function' || installed.has(php)) return;
    installed.add(php);
    php.onMessage(createBridgeHandler(executors, { php }));
}

/**
 * @param {Record<string, (message: object, context: object) => Promise<unknown>>} executors keyed by message type
 * @param {{php?: object}} context handed to every executor (the php instance, for files PHP wrote)
 * @returns {(raw: string) => Promise<string>} the php-wasm onMessage listener
 */
export function createBridgeHandler(executors = EXECUTORS, context = {}) {
    return async function handle(raw) {
        let message;
        try {
            message = JSON.parse(raw);
        } catch {
            return '';
        }
        if (!message || typeof message !== 'object' || typeof message.nativeblade !== 'string') return '';

        lastCall = describeCall(message);

        const execute = executors[message.nativeblade];
        if (typeof execute !== 'function') {
            return reply({ ok: false, error: `Unknown native call '${message.nativeblade}'.` });
        }

        try {
            return reply({ ok: true, result: await execute(message, context) });
        } catch (err) {
            return reply({ ok: false, error: describeError(err) });
        }
    };
}

function reply(payload) {
    return JSON.stringify(payload);
}

function describeError(err) {
    if (err == null) return 'unknown error';
    if (typeof err === 'string') return err;
    return err.message || (typeof err.toString === 'function' ? err.toString() : String(err));
}
