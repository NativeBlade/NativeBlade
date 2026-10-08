import { describe, it, beforeEach, afterEach } from 'node:test';
import assert from 'node:assert/strict';
import { isDevMode } from '../../../js/runtime/dev-mode.js';
import { createDevOverlay, showDevError, __resetDevOverlayForTests, MAX_ENTRIES, OVERLAY_ID } from '../../../js/wasm-app/dev-overlay.js';

// Just enough DOM for the overlay: elements with children, attributes, text and
// click listeners. No jsdom in the test toolchain.
function fakeDocument({ dev = false } = {}) {
    function element(tag) {
        const el = {
            tag, children: [], attrs: {}, listeners: {}, parentNode: null, textContent: '',
            appendChild(child) { child.parentNode = el; el.children.push(child); return child; },
            removeChild(child) { el.children = el.children.filter(c => c !== child); child.parentNode = null; return child; },
            setAttribute(name, value) { el.attrs[name] = value; },
            addEventListener(type, fn) { (el.listeners[type] ||= []).push(fn); },
            click() { for (const fn of el.listeners.click || []) fn(); },
        };
        return el;
    }
    const body = element('body');
    return {
        body,
        createElement: (tag) => element(tag),
        querySelector: (selector) => (dev && selector.includes('nativeblade-vite-url') ? element('meta') : null),
    };
}

const texts = (el) => el.children.map(c => c.textContent);

describe('dev-mode', () => {
    it('is on only when the page carries the dev server meta tag', () => {
        assert.equal(isDevMode(fakeDocument({ dev: true })), true);
        assert.equal(isDevMode(fakeDocument({ dev: false })), false);
        assert.equal(isDevMode(null), false);
        assert.equal(isDevMode({}), false);
        assert.equal(isDevMode({ querySelector() { throw new Error('x'); } }), false);
    });
});

describe('dev-overlay', () => {
    it('mounts once on first show and stacks entries with title, detail and context', () => {
        const doc = fakeDocument();
        const overlay = createDevOverlay(doc);

        overlay.show({ title: 'Replay diverged', detail: 'call #2 changed', context: { index: 1 } });
        overlay.show({ title: 'PHP error', detail: 'Fatal error: boom' });

        assert.equal(doc.body.children.length, 1, 'a single root element');
        const root = doc.body.children[0];
        assert.equal(root.id, OVERLAY_ID);
        assert.equal(overlay.element, root);

        const list = root.children[2];
        assert.equal(list.children.length, 2);
        const [first, second] = list.children;
        assert.equal(first.children[0].textContent, 'Replay diverged');
        assert.match(first.children[1].textContent, /call #2 changed\n\{\n\s+"index": 1\n\}/);
        assert.deepEqual(texts(second), ['PHP error', 'Fatal error: boom']);
    });

    it('keeps only the newest entries', () => {
        const doc = fakeDocument();
        const overlay = createDevOverlay(doc);
        for (let i = 0; i < MAX_ENTRIES + 3; i++) overlay.show({ title: `e${i}` });

        const list = overlay.element.children[2];
        assert.equal(list.children.length, MAX_ENTRIES);
        assert.equal(list.children[0].children[0].textContent, 'e3');
    });

    it('dismiss removes the overlay and a later error mounts it again', () => {
        const doc = fakeDocument();
        const overlay = createDevOverlay(doc);
        overlay.show({ title: 'one' });

        overlay.element.children[0].click();
        assert.equal(doc.body.children.length, 0);
        assert.equal(overlay.element, null);

        overlay.show({ title: 'two' });
        assert.equal(doc.body.children.length, 1);
        assert.equal(overlay.element.children[2].children.length, 1);
    });

    describe('showDevError', () => {
        let originalDocument;
        beforeEach(() => { originalDocument = globalThis.document; __resetDevOverlayForTests(); });
        afterEach(() => {
            if (originalDocument === undefined) delete globalThis.document;
            else globalThis.document = originalDocument;
            __resetDevOverlayForTests();
        });

        it('shows nothing in a store build', () => {
            globalThis.document = fakeDocument({ dev: false });
            assert.equal(showDevError('x', 'y'), false);
            assert.equal(globalThis.document.body.children.length, 0);
        });

        it('shows the error when served by the dev server', () => {
            globalThis.document = fakeDocument({ dev: true });
            assert.equal(showDevError('Unknown action', "action 'foo' was dropped", { action: 'foo' }), true);
            assert.equal(showDevError('Second', ''), true);
            const list = globalThis.document.body.children[0].children[2];
            assert.equal(list.children.length, 2, 'one overlay, two entries');
        });

        it('does nothing without a document', () => {
            delete globalThis.document;
            assert.equal(showDevError('x'), false);
        });
    });
});
