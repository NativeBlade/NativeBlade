import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { shouldReloadAfterCrash, MAX_RELOADS, WINDOW_MS } from '../../../js/runtime/crash-reload.js';

function fakeStorage(initial = {}) {
    const data = new Map(Object.entries(initial));
    return {
        getItem: (k) => (data.has(k) ? data.get(k) : null),
        setItem: (k, v) => data.set(k, v),
        data,
    };
}

describe('crash-reload', () => {
    it('reloads for the first crashes of a window and then stops', () => {
        const storage = fakeStorage();
        const t0 = 1_000_000;
        for (let i = 0; i < MAX_RELOADS; i++) {
            assert.equal(shouldReloadAfterCrash(storage, t0 + i * 1000), true, `reload ${i + 1}`);
        }
        assert.equal(shouldReloadAfterCrash(storage, t0 + 5000), false);
        assert.equal(shouldReloadAfterCrash(storage, t0 + 6000), false);
    });

    it('opens a new window once the old one has expired', () => {
        const storage = fakeStorage();
        const t0 = 1_000_000;
        for (let i = 0; i < MAX_RELOADS; i++) shouldReloadAfterCrash(storage, t0);
        assert.equal(shouldReloadAfterCrash(storage, t0 + 1000), false);
        assert.equal(shouldReloadAfterCrash(storage, t0 + WINDOW_MS), true);
    });

    it('ignores a corrupt or foreign value in storage', () => {
        assert.equal(shouldReloadAfterCrash(fakeStorage({ 'nb:crash-reloads': '{not json' }), 5), true);
        assert.equal(shouldReloadAfterCrash(fakeStorage({ 'nb:crash-reloads': '"1"' }), 5), true);
    });

    it('does not reload when storage is unavailable', () => {
        assert.equal(shouldReloadAfterCrash(null, 5), false);
        const throwing = { getItem: () => { throw new Error('blocked'); }, setItem: () => { throw new Error('blocked'); } };
        assert.equal(shouldReloadAfterCrash(throwing, 5), false);
    });
});
