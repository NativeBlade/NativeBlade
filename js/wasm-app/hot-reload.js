import { getInstance } from '../runtime/wasm-server.js';

let navigateFn = null;
let getPathFn = null;

export function init(navigate, getCurrentPath) {
    navigateFn = navigate;
    getPathFn = getCurrentPath;
    setupHMR();
    setupPolling();
}

async function applyChange(wasmPath, content) {
    const php = getInstance();
    if (!php) return;

    try {
        const files = php.listFiles('/app/storage/framework/views');
        for (const f of files) {
            if (f !== '.' && f !== '..') {
                try { php.unlink('/app/storage/framework/views/' + f); } catch {}
            }
        }
    } catch {}

    try {
        php.mkdirTree(wasmPath.substring(0, wasmPath.lastIndexOf('/')));
        php.writeFile(wasmPath, content);
    } catch {}

    if (navigateFn && getPathFn) {
        await navigateFn(getPathFn());
    }
}

function setupHMR() {
    if (!import.meta.hot) return;
    import.meta.hot.on('php-file-changed', async (data) => {
        await applyChange(data.wasmPath, data.content);
    });
}

function setupPolling() {
    let version = 0;
    const scripts = document.querySelectorAll('script[src*="vite/client"]');
    let serverUrl = '';
    scripts.forEach(s => { try { serverUrl = new URL(s.src).origin; } catch {} });
    if (!serverUrl && location.port) serverUrl = location.origin;
    if (!serverUrl) return;

    const nativeFetch = window.fetch.bind(window);

    async function check() {
        try {
            const res = await nativeFetch(`${serverUrl}/__php_changes?since=${version}`);
            const data = await res.json();
            if (data.changes?.length) {
                for (const change of data.changes) {
                    await applyChange(change.wasmPath, change.content);
                }
            }
            version = data.version;
        } catch {}
        setTimeout(check, 1000);
    }

    setTimeout(check, 3000);
}
