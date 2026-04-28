// Media actions — pick_camera, pick_gallery, pick_video
//
// Each one invokes the corresponding native function from ../media.js and
// posts `nativeblade-media-result` into the app iframe. interceptor.js
// converts that into the Livewire `nb:media-result` event, which components
// listen to with `#[On('nb:media-result')]`.
//
// Uses: ctx.post, plus the exported helpers from ../media.js

import {
    pickFromCamera,
    pickFromGallery,
    pickVideo as pickVideoFn,
} from '../media.js';

// User-cancel reasons from the native layer. These aren't errors — the
// user just closed the picker or selected nothing — so we log at debug
// level instead of console.error (which surfaces as an overlay in Tauri
// mobile dev builds).
const CANCEL_MESSAGES = /^(cancelled|no items selected|failed to process picked items)$/i;

async function dispatch(payload, ctx, fn, sourceLabel) {
    try {
        const result = await fn(payload || {});
        ctx.post('nativeblade-media-result', {
            source: result?.source ?? sourceLabel,
            items: Array.isArray(result?.items) ? result.items : [],
            id: result?.id ?? payload?.id ?? null,
        });
    } catch (e) {
        const msg = e?.message || String(e);
        if (CANCEL_MESSAGES.test(msg)) {
            console.debug(`[NB] pick_${sourceLabel} cancelled:`, msg);
        } else {
            console.error(`[NB] pick_${sourceLabel} failed`, e);
        }
        ctx.post('nativeblade-media-result', {
            source: sourceLabel,
            items: [],
            id: payload?.id ?? null,
            error: msg,
            cancelled: CANCEL_MESSAGES.test(msg),
        });
    }
}

export function pick_camera(payload, ctx) {
    return dispatch(payload, ctx, pickFromCamera, 'camera');
}

export function pick_gallery(payload, ctx) {
    return dispatch(payload, ctx, pickFromGallery, 'gallery');
}

export function pick_video(payload, ctx) {
    return dispatch(payload, ctx, pickVideoFn, 'video');
}
