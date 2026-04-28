// Bridge to the `nativeblade-media` Tauri plugin.
//
// Exposes pickFromCamera / pickFromGallery / pickVideo — both as importable
// functions (used by js/wasm-app/actions/media.js for PHP-initiated picks)
// and on `window.nbMedia` (for imperative JS use from inside the iframe).
//
// This module does NOT dispatch anything to PHP by itself. When a pick is
// triggered via NativeBlade::pickCamera/Gallery/Video, the actions/media.js
// handler calls these functions and then posts `nativeblade-media-result`
// into the app iframe, where interceptor.js converts it to the Livewire
// `nb:media-result` event.

let invokeFn = null;
let convertFileSrcFn = null;

function tauriReady() {
    return !!(window.__TAURI_INTERNALS__ && invokeFn);
}

// Convert returned items' `path` to a webview-loadable asset URL so Blade
// can bind `<img src="{{ $item['assetUrl'] }}">` without a round-trip.
function decorateItems(items) {
    if (!Array.isArray(items)) return [];
    return items.map((item) => {
        const out = { ...item };
        if (out.path && convertFileSrcFn) {
            try { out.assetUrl = convertFileSrcFn(out.path); }
            catch { out.assetUrl = out.url || ''; }
        } else {
            out.assetUrl = out.url || '';
        }
        return out;
    });
}

async function callPlugin(command, opts) {
    if (!tauriReady()) {
        throw new Error('media plugin only available in Tauri builds');
    }
    return invokeFn(`plugin:nativeblade-media|${command}`, opts || {});
}

export async function pickFromCamera(opts = {}) {
    const result = await callPlugin('pick_from_camera', opts);
    return {
        source: 'camera',
        items: decorateItems(result?.items || []),
        id: result?.id ?? opts.id ?? null,
    };
}

export async function pickFromGallery(opts = {}) {
    const result = await callPlugin('pick_from_gallery', opts);
    return {
        source: 'gallery',
        items: decorateItems(result?.items || []),
        id: result?.id ?? opts.id ?? null,
    };
}

export async function pickVideo(opts = {}) {
    const result = await callPlugin('pick_video', opts);
    return {
        source: 'video',
        items: decorateItems(result?.items || []),
        id: result?.id ?? opts.id ?? null,
    };
}

export async function checkPermissions() {
    return tauriReady() ? callPlugin('check_permissions', {}) : null;
}

export async function requestPermissions() {
    return tauriReady() ? callPlugin('request_permissions', {}) : null;
}

export async function readAsset(url) {
    if (!tauriReady()) throw new Error('readAsset requires Tauri build');
    return invokeFn('plugin:nativeblade-media|read_asset', { url });
}

export async function init() {
    if (!window.__TAURI_INTERNALS__) return;

    try {
        const core = await import('@tauri-apps/api/core');
        invokeFn = core.invoke;
        convertFileSrcFn = core.convertFileSrc;
    } catch (e) {
        console.warn('[NB Media] tauri api import failed:', e);
        return;
    }

    // Imperative escape hatch for components that want to trigger a pick
    // directly without routing through NativeResponse. The result is NOT
    // forwarded to Livewire — do that yourself if you use this path.
    try {
        window.nbMedia = {
            pickFromCamera,
            pickFromGallery,
            pickVideo,
            checkPermissions,
            requestPermissions,
            readAsset,
            available: true,
        };
    } catch (_) {}
}
