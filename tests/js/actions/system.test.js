import { describe, it, beforeEach, afterEach } from 'node:test';
import assert from 'node:assert/strict';
import { log, exit } from '../../../js/wasm-app/actions/system.js';

describe('actions/system', () => {
    let calls;
    const originalConsole = { log: console.log, warn: console.warn, error: console.error, debug: console.debug };

    beforeEach(() => {
        calls = [];
        for (const method of ['log', 'warn', 'error', 'debug']) {
            console[method] = (...args) => calls.push({ method, args });
        }
    });

    afterEach(() => {
        Object.assign(console, originalConsole);
    });

    describe('log', () => {
        it('routes info level to console.log', () => {
            log({ level: 'info', message: 'hi' });
            assert.equal(calls[0].method, 'log');
            assert.ok(calls[0].args[0].includes('[NB:info]'));
            assert.equal(calls[0].args[2], 'hi');
        });

        it('routes warn level to console.warn', () => {
            log({ level: 'warn', message: 'watch out' });
            assert.equal(calls[0].method, 'warn');
            assert.ok(calls[0].args[0].includes('[NB:warn]'));
        });

        it('routes error level to console.error', () => {
            log({ level: 'error', message: 'bad' });
            assert.equal(calls[0].method, 'error');
            assert.ok(calls[0].args[0].includes('[NB:error]'));
        });

        it('routes debug level to console.debug', () => {
            log({ level: 'debug', message: 'trace' });
            assert.equal(calls[0].method, 'debug');
            assert.ok(calls[0].args[0].includes('[NB:debug]'));
        });

        it('defaults to info when level is missing', () => {
            log({ message: 'x' });
            assert.equal(calls[0].method, 'log');
        });

        it('passes context as a third argument when non-empty', () => {
            log({ level: 'info', message: 'hi', context: { user: 42 } });
            assert.deepEqual(calls[0].args[3], { user: 42 });
        });

        it('omits context when empty', () => {
            log({ level: 'info', message: 'hi', context: {} });
            assert.equal(calls[0].args.length, 3); // prefix, style, message
        });

        it('falls back to console.log for unknown levels', () => {
            log({ level: 'verbose', message: 'hi' });
            assert.equal(calls[0].method, 'log');
        });

        it('defaults message to empty string when missing', () => {
            log({ level: 'info' });
            assert.equal(calls[0].args[2], '');
        });
    });

    describe('exit', () => {
        // Outside Tauri the plugin import resolves but exit(0) rejects; the
        // handler must swallow it (no unhandled rejection) rather than throw.
        it('rejects quietly outside Tauri instead of throwing', async () => {
            await assert.doesNotReject(() => Promise.resolve(exit()));
        });
    });
});
