import { describe, it, beforeEach, afterEach } from 'node:test';
import assert from 'node:assert/strict';

// getBundleBase lives in a standalone module (bundle-base.js) so it can be
// tested without pulling the php-runtime import chain which requires Vite.
globalThis.window ??= {};

import { getBundleBase } from '../../../js/runtime/bundle-base.js';

function resetWindow() {
    delete globalThis.window.__NB_BUNDLE_BASE__;
    globalThis.window.localStorage = undefined;
}

// Minimal localStorage stub — only getItem is read by getBundleBase.
function mockLocalStorage(value) {
    globalThis.window.localStorage = {
        getItem: (key) => (key === 'nb:bundleBase' ? value : null),
    };
}

describe('filesystem.js/getBundleBase', () => {
    beforeEach(resetWindow);
    afterEach(resetWindow);

    it('defaults to "./" when no source is configured', () => {
        assert.equal(getBundleBase(), './');
    });

    it('prefers window.__NB_BUNDLE_BASE__ over localStorage', () => {
        globalThis.window.__NB_BUNDLE_BASE__ = 'http://dev.lan:1420';
        mockLocalStorage('http://ignored:9999');

        assert.equal(getBundleBase(), 'http://dev.lan:1420/');
    });

    it('falls back to localStorage "nb:bundleBase" when window global is unset', () => {
        mockLocalStorage('http://192.168.1.42:1420');

        assert.equal(getBundleBase(), 'http://192.168.1.42:1420/');
    });

    it('appends a trailing slash so fetch(base + "x") concatenates cleanly', () => {
        globalThis.window.__NB_BUNDLE_BASE__ = 'http://host:1420';
        assert.equal(getBundleBase(), 'http://host:1420/');

        globalThis.window.__NB_BUNDLE_BASE__ = 'http://host:1420/';
        assert.equal(getBundleBase(), 'http://host:1420/');
    });

    it('ignores non-string values and uses the default', () => {
        globalThis.window.__NB_BUNDLE_BASE__ = 123; // bogus
        assert.equal(getBundleBase(), './');

        globalThis.window.__NB_BUNDLE_BASE__ = '';
        assert.equal(getBundleBase(), './');
    });

    it('survives a localStorage.getItem that throws', () => {
        globalThis.window.localStorage = {
            getItem() { throw new Error('SecurityError'); },
        };

        // Should not throw and should fall through to the default.
        assert.equal(getBundleBase(), './');
    });
});
