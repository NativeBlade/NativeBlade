// Bundle push: over-the-air updates for the Laravel bundle.
//
// Stores downloaded bundles in IndexedDB. The next boot picks the cached
// bundle automatically via tryLoadCachedBundle() — see filesystem.js.
//
// Triggered by NativeBladeConfig::bundlePush(...) on the PHP side, which
// writes its config to public/nativeblade-config.json. Fetched here at
// boot.

import { getBundleBase } from './bundle-base.js';

const DB_NAME = 'nb-bundle-cache';
const DB_VERSION = 1;
const STORE = 'bundles';
const KEY = 'current';

const VERSION_KEY = 'nb:bundleVersion';

function openDb() {
    return new Promise((resolve, reject) => {
        const req = indexedDB.open(DB_NAME, DB_VERSION);
        req.onupgradeneeded = () => req.result.createObjectStore(STORE);
        req.onsuccess = () => resolve(req.result);
        req.onerror = () => reject(req.error);
    });
}

async function tx(mode) {
    const db = await openDb();
    return db.transaction(STORE, mode).objectStore(STORE);
}

export async function getCachedBundle() {
    try {
        const store = await tx('readonly');
        return await new Promise((resolve, reject) => {
            const req = store.get(KEY);
            req.onsuccess = () => resolve(req.result || null);
            req.onerror = () => reject(req.error);
        });
    } catch {
        return null;
    }
}

async function putCachedBundle(blob) {
    const store = await tx('readwrite');
    return new Promise((resolve, reject) => {
        const req = store.put(blob, KEY);
        req.onsuccess = () => resolve();
        req.onerror = () => reject(req.error);
    });
}

async function clearCache() {
    try {
        const store = await tx('readwrite');
        await new Promise((resolve) => {
            const req = store.delete(KEY);
            req.onsuccess = () => resolve();
            req.onerror = () => resolve();
        });
    } catch {}
}

function versionGte(a, b) {
    const pa = String(a).split('.').map(n => parseInt(n, 10) || 0);
    const pb = String(b).split('.').map(n => parseInt(n, 10) || 0);
    const len = Math.max(pa.length, pb.length);
    for (let i = 0; i < len; i++) {
        const x = pa[i] || 0;
        const y = pb[i] || 0;
        if (x > y) return true;
        if (x < y) return false;
    }
    return true;
}

async function loadConfig() {
    if (typeof window !== 'undefined' && window.__NB_BUNDLE_PUSH__) {
        return window.__NB_BUNDLE_PUSH__;
    }
    try {
        const r = await fetch(getBundleBase() + 'nativeblade-config.json', { cache: 'no-store' });
        if (!r.ok) return null;
        const json = await r.json();
        return json?.bundlePush || null;
    } catch {
        return null;
    }
}

/**
 * Run on boot. Checks the version endpoint, downloads new bundle if
 * available and compatible. Errors are logged but do not block boot.
 */
export async function checkAndDownload() {
    const config = await loadConfig();
    if (!config?.url) return;

    let manifest;
    try {
        manifest = await fetch(config.url, { cache: 'no-store' }).then(r => r.json());
    } catch (e) {
        console.warn('[NB Push] manifest fetch failed:', e);
        return;
    }

    const next = manifest?.bundle;
    if (!next?.version || !next?.url) return;

    const currentVersion = localStorage.getItem(VERSION_KEY) || window.__NB_SHELL_BUNDLE_VERSION__ || '0.0.0';
    if (versionGte(currentVersion, next.version) && currentVersion === next.version) return;

    if (next.minShellVersion && window.__NB_SHELL_VERSION__) {
        if (!versionGte(window.__NB_SHELL_VERSION__, next.minShellVersion)) {
            console.info('[NB Push] bundle requires shell >=' + next.minShellVersion + ', skipping');
            return;
        }
    }

    let blob;
    try {
        const res = await fetch(next.url, { cache: 'no-store' });
        if (!res.ok) throw new Error('HTTP ' + res.status);
        blob = await res.blob();
    } catch (e) {
        console.warn('[NB Push] bundle download failed:', e);
        return;
    }

    try {
        await putCachedBundle(blob);
        localStorage.setItem(VERSION_KEY, next.version);
        console.info('[NB Push] bundle ' + next.version + ' downloaded — applies on next reload');
    } catch (e) {
        console.warn('[NB Push] could not persist bundle:', e);
    }
}

/**
 * Called by filesystem.js before the default fetch. If a cached bundle
 * exists, returns it as a Blob. Caller decompresses and loads it. On any
 * failure (corruption, decompression error), the cache is cleared so the
 * default flow takes over.
 */
export async function tryLoadCachedBundle() {
    const blob = await getCachedBundle();
    if (!blob) return null;

    try {
        if (typeof DecompressionStream !== 'undefined') {
            const stream = blob.stream().pipeThrough(new DecompressionStream('gzip'));
            return await new Response(stream).text();
        }
        return await blob.text();
    } catch (e) {
        console.warn('[NB Push] cached bundle invalid, clearing:', e);
        await clearCache();
        return null;
    }
}
