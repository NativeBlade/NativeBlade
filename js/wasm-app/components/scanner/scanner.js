// Automatic scanning overlay for the barcode scanner.
//
// The Tauri barcode plugin is headless: on scan it shows the camera behind a
// transparent webview and expects the app to draw the scanning UI. Without
// that, the user gets a fullscreen camera with no way out. This module renders
// the missing UI (a viewfinder frame + a Cancel button) automatically, so
// `NativeBlade::scan()` works with no extra code from the developer.
//
// Pure DOM, guarded so importing it outside a browser (tests) is a no-op.

import { t } from '../../../runtime/i18n.js';

let overlayEl = null;
let cancelHandler = null;
let activeFrame = null;

// t() returns the key unchanged when missing, so fall back to English for
// projects whose lang files predate the scanner.cancel key.
function cancelLabel() {
    const label = t('scanner.cancel');
    return label && label !== 'scanner.cancel' ? label : 'Cancel';
}

function build() {
    const el = document.createElement('div');
    el.id = 'nb-scanner-overlay';
    el.innerHTML =
        '<div class="nb-scanner-frame"></div>' +
        '<button type="button" class="nb-scanner-cancel"></button>';
    el.querySelector('.nb-scanner-cancel').textContent = cancelLabel();
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
    overlayEl.style.display = 'block';
}

export function hideScanner() {
    if (typeof document === 'undefined') return;
    document.body.classList.remove('nb-scanning');
    if (overlayEl) overlayEl.style.display = 'none';
    setFrameTransparent(activeFrame, false);
    activeFrame = null;
    cancelHandler = null;
}
