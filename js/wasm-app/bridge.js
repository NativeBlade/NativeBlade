// NativeBlade JS bridge — wires PHP/Livewire-emitted actions to native APIs.
//
// The fat switch that used to live here was split into one file per plugin
// under ./actions/. This file now just:
//   1) boots the Tauri plugin APIs during init()
//   2) builds a context object that exposes those APIs + helpers
//   3) dispatches incoming actions to the correct handler in ./actions/index.js

import * as cameraModule from './components/camera/camera.js';
import { getComponent } from './component-registry.js';
import { actions } from './actions/index.js';

let appFrameRef = null;
let isTauri = false;
let isMobile = false;
let isAndroid = false;

// All the lazily-imported Tauri plugin APIs live on this object so we can
// spread it into the ctx without listing each one.
const apis = {
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
    uploadApi: null,
    shellApi: null,
    invokeTauri: null,
};

// Modules that must react to a navigation frame swap (e.g. shell-module GC)
// register here — bridge stays dependency-free of them.
const frameSwapCallbacks = [];
export function onFrameSwap(callback) {
    frameSwapCallbacks.push(callback);
}

// Update the active iframe reference. Called by the router when it swaps the
// visible frame for a buffer frame during a SPA-style navigation.
export function setFrame(appFrame) {
    appFrameRef = appFrame;
    cameraModule.init(appFrame);
    for (const callback of frameSwapCallbacks) {
        try { callback(appFrame); } catch (e) { console.error('[NB] frame-swap callback failed', e); }
    }
}

// Post a message into the active app iframe (follows router frame swaps).
// For boot-level modules that emit events outside a dispatched action
// (network changes, late purchase results).
export function postToApp(type, data = {}) {
    appFrameRef?.contentWindow?.postMessage({ type, ...data }, '*');
}

export async function init(appFrame) {
    appFrameRef = appFrame;
    cameraModule.init(appFrame);

    // The ONLY reliable signal for "running inside Tauri" is the host-injected
    // global. Being able to import a @tauri-apps package is NOT a signal: the
    // bundler ships those modules into the browser preview too, where their
    // invoke() has no host and throws. (This is what made every native action
    // fire an invoke in the browser preview.)
    isTauri = typeof window !== 'undefined' && !!window.__TAURI_INTERNALS__;

    try {
        apis.dialogApi = await import('@tauri-apps/plugin-dialog');
    } catch {}

    try {
        const core = await import('@tauri-apps/api/core');
        apis.invokeTauri = core.invoke;
    } catch {}

    if (!isTauri) return;

    try {
        const os = await import('@tauri-apps/plugin-os');
        const platform = await os.platform();
        isMobile = platform === 'android' || platform === 'ios';
        isAndroid = platform === 'android';
    } catch {}

    if (!isMobile) {
        try { apis.notificationApi = await import('@tauri-apps/plugin-notification'); } catch {}
    }

    try { apis.clipboardApi   = await import('@tauri-apps/plugin-clipboard-manager'); } catch {}
    try { apis.geolocationApi = await import('@tauri-apps/plugin-geolocation'); } catch {}
    try { apis.hapticsApi     = await import('@tauri-apps/plugin-haptics'); } catch {}
    try { apis.biometricApi   = await import('@tauri-apps/plugin-biometric'); } catch {}
    try { apis.barcodeApi     = await import('@tauri-apps/plugin-barcode-scanner'); } catch {}
    try { apis.nfcApi         = await import('@tauri-apps/plugin-nfc'); } catch {}
    try { apis.openerApi      = await import('@tauri-apps/plugin-opener'); } catch {}
    try { apis.osApi          = await import('@tauri-apps/plugin-os'); } catch {}
    try { apis.uploadApi      = await import('@tauri-apps/plugin-upload'); } catch {}
    try { apis.shellApi       = await import('@tauri-apps/plugin-shell'); } catch {}
}

// Used by components (e.g. bottom-nav) for internal tap feedback.
export function hapticSelection() {
    if (!apis.hapticsApi || !isMobile) return;
    try { apis.hapticsApi.selectionFeedback().catch(() => {}); } catch {}
}

// --- helpers shared by multiple actions ---

async function resolveFileDest(pathApi, relativePath, purpose) {
    const purposeMap = {
        app: () => pathApi.appDataDir(),
        export: () => pathApi.documentDir(),
        downloads: () => pathApi.downloadDir(),
        cache: () => pathApi.appCacheDir(),
        temp: () => pathApi.tempDir(),
    };
    const baseDir = await (purposeMap[purpose] || purposeMap.app)();
    const sep = await pathApi.sep();
    const base = baseDir.endsWith(sep) ? baseDir : baseDir + sep;
    return base + relativePath.replace(/\//g, sep);
}

// --- dispatcher ---

function buildCtx(appFrame, replyWindow = null) {
    return {
        // all the Tauri APIs, spread so handlers can destructure what they need
        ...apis,
        // platform flags
        isTauri,
        isMobile,
        isAndroid,
        // frame + postMessage helper. Events reply to the window that
        // dispatched the action when known: during a navigation transition
        // the incoming page (still in the buffer, pre-swap) fires wire:init
        // actions, and posting to the "current" frame would deliver the
        // response to the OUTGOING page instead.
        appFrame,
        // The window that dispatched this action (buffer frame during a
        // navigation transition) — lets handlers tie state to its source page.
        replyWindow,
        post: (type, data = {}) =>
            (replyWindow ?? appFrame?.contentWindow)?.postMessage({ type, ...data }, '*'),
        // shared modules + helpers
        camera: cameraModule,
        resolveFileDest,
    };
}

export function handleNativeAction(action, payload, appFrame, replyWindow = null) {
    const handler = actions[action];
    if (handler) {
        try {
            const result = handler(payload, buildCtx(appFrame, replyWindow));
            if (result && typeof result.catch === 'function') {
                result.catch(e => console.error(`[NB] action '${action}' failed`, e));
            }
        } catch (e) {
            console.error(`[NB] action '${action}' failed`, e);
        }
        return;
    }

    const comp = getComponent(action);
    if (comp?.render) {
        comp.render(payload);
        return;
    }
    import(`@components/${action}/${action}.js`)
        .then(mod => { if (mod.render) mod.render(payload); })
        .catch(() => {});
}
