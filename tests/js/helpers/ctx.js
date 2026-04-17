// Test helpers — build a fake `ctx` object for action handlers.
//
// Every action handler has the signature `(payload, ctx) => void | Promise`.
// The ctx carries Tauri APIs (dialogApi, hapticsApi, etc.), platform flags
// and helpers. Real ctx is built by bridge.js#buildCtx; this mirrors the
// shape with recording stubs so we can assert what the handler called.

/**
 * Tracked postMessage calls, cleared per test.
 */
export class Recorder {
    constructor() {
        this.calls = [];
    }
    /** Returns a function that appends {type, data} to this.calls. */
    fn() {
        return (type, data = {}) => {
            this.calls.push({ type, data });
        };
    }
}

/**
 * Build a ctx with no APIs populated. Pass overrides to enable
 * specific sub-APIs per test.
 *
 *   const rec = new Recorder();
 *   const ctx = makeCtx({ post: rec.fn(), clipboardApi: { writeText: () => {} } });
 */
export function makeCtx(overrides = {}) {
    const base = {
        // Platform flags (bridge.js sets these once).
        isTauri: false,
        isMobile: false,
        isAndroid: false,

        // APIs left null by default — each test enables what it needs.
        dialogApi: null,
        notificationApi: null,
        clipboardApi: null,
        geolocationApi: null,
        hapticsApi: null,
        biometricApi: null,
        barcodeApi: null,
        nfcApi: null,
        openerApi: null,
        osApi: null,
        shellApi: null,
        uploadApi: null,

        // Iframe + bridge helpers.
        appFrame: null,
        post: () => {},
        camera: { open: () => {}, openGallery: () => {} },
        ensureChannel: async () => {},
        resolveFileDest: async (_path, to) => to,
    };
    return { ...base, ...overrides };
}

/**
 * Build a spy function that records every invocation.
 *
 *   const s = spy(() => 42);
 *   s(1, 2);
 *   s.calls // [[1, 2]]
 *   s.called // true
 *   s.callCount // 1
 */
export function spy(impl = () => {}) {
    const fn = (...args) => {
        fn.calls.push(args);
        fn.callCount++;
        fn.called = true;
        return impl(...args);
    };
    fn.calls = [];
    fn.callCount = 0;
    fn.called = false;
    return fn;
}

/**
 * Wait for all pending microtasks to drain. Useful after handlers that
 * kick off a promise chain without awaiting it.
 */
export async function flush() {
    await new Promise((r) => setImmediate(r));
}
