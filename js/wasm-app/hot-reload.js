import { getInstance } from '../runtime/wasm-server.js';

let navigateFn = null;
let getPathFn = null;
let lastVersion = 0;
let pendingChanges = new Map();
let flushTimer = null;
const FLUSH_DELAY_MS = 100;
const FETCH_TIMEOUT_MS = 5000;

export function init(navigate, getCurrentPath) {
    navigateFn = navigate;
    getPathFn = getCurrentPath;

    const serverUrl = resolveServerUrl();
    const hasWs = typeof import.meta.hot !== 'undefined' && import.meta.hot !== null;

    if (hasWs) {
        setupHMR();
    }
    if (serverUrl) {
        setupPolling(serverUrl, hasWs);
    }
}

function resolveServerUrl() {
    // Portal mode: the bundle is served from a remote dev server, and the same
    // origin hosts the /__php_changes + /__php_version polling endpoints.
    const portalBase = (typeof window !== 'undefined' ? window.__NB_BUNDLE_BASE__ : null)
        ?? readLocalStorage('nb:bundleBase');
    if (typeof portalBase === 'string' && portalBase.length) {
        try {
            return new URL(portalBase, location.href).origin;
        } catch {
            return portalBase.replace(/\/+$/, '');
        }
    }

    const meta = document.querySelector('meta[name="nativeblade-vite-url"]');
    const fromMeta = meta?.getAttribute('content');
    if (fromMeta) return fromMeta;

    const scripts = document.querySelectorAll('script[src*="vite/client"]');
    for (const s of scripts) {
        try { return new URL(s.src).origin; } catch {}
    }

    if (location.protocol.startsWith('http') && location.port) {
        return location.origin;
    }

    return '';
}

function readLocalStorage(key) {
    try { return window.localStorage?.getItem?.(key) ?? null; } catch { return null; }
}

function scheduleChange(wasmPath, content, version) {
    if (version && version <= lastVersion) return;
    pendingChanges.set(wasmPath, content);
    if (version) lastVersion = Math.max(lastVersion, version);

    if (flushTimer) clearTimeout(flushTimer);
    flushTimer = setTimeout(flushPending, FLUSH_DELAY_MS);
}

async function flushPending() {
    flushTimer = null;
    if (pendingChanges.size === 0) return;

    const php = getInstance();
    if (!php) return;

    const entries = [...pendingChanges.entries()];
    pendingChanges.clear();

    try {
        const files = php.listFiles('/app/storage/framework/views');
        for (const f of files) {
            if (f !== '.' && f !== '..') {
                try { php.unlink('/app/storage/framework/views/' + f); } catch {}
            }
        }
    } catch {}

    for (const [wasmPath, content] of entries) {
        try {
            const parent = wasmPath.substring(0, wasmPath.lastIndexOf('/'));
            if (parent) php.mkdirTree(parent);
            php.writeFile(wasmPath, content);
        } catch {}
    }

    if (navigateFn && getPathFn) {
        try { await navigateFn(getPathFn()); } catch {}
    }
}

function setupHMR() {
    import.meta.hot.on('php-file-changed', (data) => {
        scheduleChange(data.wasmPath, data.content, data.version || 0);
    });
}

function setupPolling(serverUrl, hasWs) {
    const nativeFetch = window.fetch.bind(window);
    let backoffMs = 1000;
    const backoffCeil = 30000;
    let baselined = false;
    let lastStatus = null;

    function postStatus(status) {
        if (status === lastStatus) return;
        lastStatus = status;
        try {
            if (window.parent && window.parent !== window) {
                window.parent.postMessage({ type: 'nb:vite-status', status }, '*');
            }
        } catch {}
    }

    async function fetchJson(url) {
        const ctrl = new AbortController();
        const timer = setTimeout(() => ctrl.abort(), FETCH_TIMEOUT_MS);
        try {
            const res = await nativeFetch(url, { signal: ctrl.signal });
            if (!res.ok) throw new Error('HTTP ' + res.status);
            return await res.json();
        } finally {
            clearTimeout(timer);
        }
    }

    async function baseline() {
        try {
            const data = await fetchJson(`${serverUrl}/__php_version`);
            if (typeof data.version === 'number') {
                lastVersion = Math.max(lastVersion, data.version);
            }
            baselined = true;
            backoffMs = 1000;
            postStatus('connected');
        } catch {
            postStatus('disconnected');
            backoffMs = Math.min(backoffMs * 2, backoffCeil);
            setTimeout(baseline, backoffMs);
        }
    }

    async function check() {
        try {
            const data = await fetchJson(`${serverUrl}/__php_changes?since=${lastVersion}`);
            if (Array.isArray(data.changes)) {
                for (const change of data.changes) {
                    scheduleChange(change.wasmPath, change.content, change.version || 0);
                }
            }
            if (typeof data.version === 'number') {
                lastVersion = Math.max(lastVersion, data.version);
            }
            backoffMs = 1000;
            postStatus('connected');
        } catch {
            postStatus('disconnected');
            backoffMs = Math.min(backoffMs * 2, backoffCeil);
        }
        setTimeout(check, hasWs ? backoffMs * 3 : backoffMs);
    }

    (async () => {
        await baseline();
        if (baselined) setTimeout(check, hasWs ? 3000 : 1000);
    })();
}
