import bridge from './bridge.js';

export function init() {
    document.addEventListener('htmx:afterRequest', (event) => {
        const xhr = event.detail.xhr;
        if (!xhr) return;

        const action = xhr.getResponseHeader('X-Action');
        const target = xhr.getResponseHeader('X-Target');

        if (action) {
            bridge.handle(action, target);
        }
    });
}
