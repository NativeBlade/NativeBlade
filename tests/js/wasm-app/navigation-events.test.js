import { describe, it, beforeEach, afterEach } from 'node:test';
import assert from 'node:assert/strict';
import { onNavigate, emitNavigation, __resetForTests } from '../../../js/wasm-app/navigation-events.js';

const detail = { path: '/orders', from: '/', direction: 'forward', transition: 'slide' };

describe('wasm-app/navigation-events', () => {
    beforeEach(() => __resetForTests());

    it('calls every subscriber with the navigation detail', () => {
        const seen = [];
        onNavigate((d) => seen.push(['a', d]));
        onNavigate((d) => seen.push(['b', d]));

        emitNavigation(detail);

        assert.deepEqual(seen, [['a', detail], ['b', detail]]);
    });

    it('returns an unsubscribe function', () => {
        const seen = [];
        const off = onNavigate((d) => seen.push(d));

        emitNavigation(detail);
        off();
        emitNavigation({ ...detail, path: '/next' });

        assert.deepEqual(seen, [detail]);
    });

    it('keeps going when a listener throws', () => {
        const seen = [];
        const originalError = console.error;
        console.error = () => {};
        try {
            onNavigate(() => { throw new Error('boom'); });
            onNavigate((d) => seen.push(d));

            emitNavigation(detail);
        } finally {
            console.error = originalError;
        }

        assert.deepEqual(seen, [detail]);
    });

    it('rejects a non-function subscriber', () => {
        assert.throws(() => onNavigate('nope'), TypeError);
    });

    describe('DOM event', () => {
        let dispatched;
        beforeEach(() => {
            dispatched = [];
            globalThis.window = { dispatchEvent: (e) => dispatched.push(e) };
            globalThis.CustomEvent = class { constructor(type, init) { this.type = type; this.detail = init.detail; } };
        });
        afterEach(() => {
            delete globalThis.window;
            delete globalThis.CustomEvent;
        });

        it('also dispatches nb:navigate on the shell window', () => {
            emitNavigation(detail);

            assert.equal(dispatched.length, 1);
            assert.equal(dispatched[0].type, 'nb:navigate');
            assert.deepEqual(dispatched[0].detail, detail);
        });
    });
});
