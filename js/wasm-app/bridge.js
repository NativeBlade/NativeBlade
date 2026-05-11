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

// Update the active iframe reference. Called by the router when it swaps the
// visible frame for a buffer frame during a SPA-style navigation.
export function setFrame(appFrame) {
    appFrameRef = appFrame;
    cameraModule.init(appFrame);
}

export async function init(appFrame) {
    appFrameRef = appFrame;
    cameraModule.init(appFrame);

    try {
        apis.dialogApi = await import('@tauri-apps/plugin-dialog');
        isTauri = true;
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

function buildCtx(appFrame) {
    return {
        // all the Tauri APIs, spread so handlers can destructure what they need
        ...apis,
        // platform flags
        isTauri,
        isMobile,
        isAndroid,
        // frame + postMessage helper
        appFrame,
        post: (type, data = {}) => appFrame?.contentWindow?.postMessage({ type, ...data }, '*'),
        // shared modules + helpers
        camera: cameraModule,
        resolveFileDest,
    };
}

export function handleNativeAction(action, payload, appFrame) {
    const handler = actions[action];
    if (handler) {
        try {
            const result = handler(payload, buildCtx(appFrame));
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
