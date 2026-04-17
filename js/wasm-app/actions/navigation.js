// Navigation actions — navigate, showModal, hideModal
// Uses: window.postMessage, component-registry

import { getComponent } from '../component-registry.js';

export function navigate(payload) {
    window.postMessage({
        type: 'nativeblade-navigate',
        path: payload.path,
        replace: !!payload.replace,
        transition: payload.transition,
    }, '*');
}

export function showModal() {
    const modal = getComponent('modal');
    if (modal?.show) modal.show();
}

export function hideModal() {
    const modal = getComponent('modal');
    if (modal?.hide) modal.hide();
}
