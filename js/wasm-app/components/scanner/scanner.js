// Automatic scanning overlay for the barcode scanner.
//
// The Tauri barcode plugin is headless: on scan it shows the camera behind a
// transparent webview and expects the app to draw the scanning UI. This module
// renders it automatically (viewfinder corners + scan line + hint + Cancel),
// so `NativeBlade::scan()` works with no extra code from the developer.
//
// The camera takes ~a second to warm up after the webview goes transparent —
// a solid backdrop covers that gap and cross-fades out, so the user sees
// dark → camera instead of a gray flash.
//
// Pure DOM, guarded so importing it outside a browser (tests) is a no-op.

import { t } from '../../../runtime/i18n.js';

let overlayEl = null;
let cancelHandler = null;
let activeFrame = null;
let liveTimer = null;

const REVEAL_DELAY_MS = 450; // camera warm-up cover before the backdrop fades

// t() returns the key unchanged when missing — fall back to English for
// projects whose lang files predate these keys.
function label(key, fallback) {
    const value = t(key);
    return value && value !== key ? value : fallback;
}

function build() {
    const el = document.createElement('div');
    el.id = 'nb-scanner-overlay';
    el.innerHTML =
        '<div class="nb-scanner-backdrop"></div>' +
        '<div class="nb-scanner-header"><div class="nb-scanner-title"></div></div>' +
        '<div class="nb-scanner-frame">' +
            '<span class="nb-sc nb-sc-tl"></span><span class="nb-sc nb-sc-tr"></span>' +
            '<span class="nb-sc nb-sc-bl"></span><span class="nb-sc nb-sc-br"></span>' +
            '<div class="nb-scanner-line"></div>' +
        '</div>' +
        '<div class="nb-scanner-hint"></div>' +
        '<button type="button" class="nb-scanner-cancel"></button>';
    el.querySelector('.nb-scanner-title').textContent = label('scanner.title', 'Scan code');
    el.querySelector('.nb-scanner-hint').textContent = label('scanner.hint', 'Point the camera at the code');
    el.querySelector('.nb-scanner-cancel').textContent = label('scanner.cancel', 'Cancel');
    el.querySelector('.nb-scanner-cancel').addEventListener('click', () => {
        if (cancelHandler) cancelHandler();
    });
    document.body.appendChild(el);
    return el;
}

// The camera lives behind the whole webview, so the path to it must be
// transparent: the shell body + iframe (CSS) and the iframe's own document.
function setFrameTransparent(frame, on) {
    try {
        const doc = frame?.contentDocument;
        if (doc?.documentElement) doc.documentElement.style.background = on ? 'transparent' : '';
        if (doc?.body) doc.body.style.background = on ? 'transparent' : '';
    } catch {}
}

export function showScanner(appFrame, onCancel) {
    if (typeof document === 'undefined') return;
    if (!overlayEl) overlayEl = build();
    cancelHandler = onCancel;
    activeFrame = appFrame;
    document.body.classList.add('nb-scanning');
    setFrameTransparent(appFrame, true);
    overlayEl.classList.remove('is-live');
    overlayEl.style.display = 'block';
    clearTimeout(liveTimer);
    liveTimer = setTimeout(() => overlayEl?.classList.add('is-live'), REVEAL_DELAY_MS);
}

export function hideScanner() {
    if (typeof document === 'undefined') return;
    clearTimeout(liveTimer);
    liveTimer = null;
    document.body.classList.remove('nb-scanning');
    if (overlayEl) {
        overlayEl.style.display = 'none';
        overlayEl.classList.remove('is-live');
    }
    setFrameTransparent(activeFrame, false);
    activeFrame = null;
    cancelHandler = null;
}
