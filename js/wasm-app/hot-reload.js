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

    const portalBase = (typeof window !== 'undefined' ? window.__NB_BUNDLE_BASE__ : null)
        ?? readLocalStorage('nb:bundleBase');
    if (portalBase) {
        if (serverUrl) setupPolling(serverUrl);
        return;
    }

    const hasWs = typeof import.meta.hot !== 'undefined' && import.meta.hot !== null;
    if (hasWs) {
        setupHMR();
        return;
    }
    if (serverUrl) {
        setupPolling(serverUrl);
    }
}

function resolveServerUrl() {
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

function scheduleChange(change) {
    const { version = 0 } = change;
    if (version && version <= lastVersion) return;
    pendingChanges.set(change.wasmPath, change);
    if (version) lastVersion = Math.max(lastVersion, version);

    if (flushTimer) clearTimeout(flushTimer);
    flushTimer = setTimeout(flushPending, FLUSH_DELAY_MS);
}

async function flushPending() {
    flushTimer = null;
    if (pendingChanges.size === 0) return;

    const php = getInstance();
    if (!php) return;

    const entries = [...pendingChanges.values()];
    pendingChanges.clear();

    for (const change of entries) {
        try {
            if (change.op === 'unlink') {
                try { php.unlink(change.wasmPath); } catch {}
                continue;
            }
            const parent = change.wasmPath.substring(0, change.wasmPath.lastIndexOf('/'));
            if (parent) php.mkdirTree(parent);
            php.writeFile(change.wasmPath, change.content);
        } catch {}
    }

    if (navigateFn && getPathFn) {
        try { await navigateFn(getPathFn(), { transition: 'none', force: true }); } catch {}
    }
}

function setupHMR() {
    import.meta.hot.on('php-file-changed', (data) => {
        scheduleChange(data);
    });
}

function setupPolling(serverUrl) {
    const fetchReady = resolvePollFetch(serverUrl);
    let backoffMs = 1000;
    const backoffCeil = 30000;
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
        const doFetch = await fetchReady;
        const ctrl = new AbortController();
        const timer = setTimeout(() => ctrl.abort(), FETCH_TIMEOUT_MS);
        try {
            const res = await doFetch(url, { signal: ctrl.signal });
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
            backoffMs = 1000;
            postStatus('connected');
            setTimeout(check, 1000);
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
                    scheduleChange(change);
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
        setTimeout(check, backoffMs);
    }

    baseline();
}

async function resolvePollFetch(serverUrl) {
    const isTauri = typeof window !== 'undefined' && !!window.__TAURI_INTERNALS__;
    if (isTauri && /^http:\/\//i.test(serverUrl)) {
        try {
            const mod = await import('@tauri-apps/plugin-http');
            if (mod?.fetch) return mod.fetch;
        } catch {}
    }
    return window.fetch.bind(window);
}
