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

function getChannel(config) {
    return config?.channel || 'stable';
}

function channelVersionKey(channel) {
    return (!channel || channel === 'stable') ? VERSION_KEY : `${VERSION_KEY}:${channel}`;
}

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
        const record = await new Promise((resolve, reject) => {
            const req = store.get(KEY);
            req.onsuccess = () => resolve(req.result || null);
            req.onerror = () => reject(req.error);
        });
        if (!record) return null;

        if (record instanceof Blob) {
            await clearCache();
            return null;
        }

        const currentBase = getBundleBase();
        if (record.sourceBase !== currentBase) {
            await clearCache();
            return null;
        }

        const currentChannel = getChannel(await loadConfig());
        if ((record.channel || 'stable') !== currentChannel) {
            await clearCache();
            return null;
        }
        return record.blob;
    } catch {
        return null;
    }
}

async function putCachedBundle(blob, channel) {
    const store = await tx('readwrite');
    return new Promise((resolve, reject) => {
        const req = store.put({ blob, sourceBase: getBundleBase(), channel: channel || 'stable' }, KEY);
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
        const r = await fetch('./nativeblade-config.json', { cache: 'no-store' });
        if (!r.ok) return null;
        const json = await r.json();
        return json?.bundlePush || null;
    } catch {
        return null;
    }
}

/**
 * Probe the configured manifest for a newer bundle without downloading it.
 * Returns a shape the caller can use to drive UI:
 *   { available: true,  currentVersion, nextVersion, url }
 *   { available: false, reason: 'not-configured' | 'fetch-failed' | 'invalid-manifest'
 *                              | 'up-to-date' | 'shell-too-old',
 *                       currentVersion?, requiredShell?, currentShell?, error? }
 */
export async function checkForUpdate() {
    const config = await loadConfig();
    if (!config?.url) return { available: false, reason: 'not-configured' };

    let manifest;
    try {
        manifest = await fetch(config.url, { cache: 'no-store' }).then(r => r.json());
    } catch (e) {
        return { available: false, reason: 'fetch-failed', error: e.message || String(e) };
    }

    const channel = getChannel(config);
    let next;
    if (channel !== 'stable') {
        next = manifest?.channels?.[channel];
        if (!next) return { available: false, reason: 'up-to-date' };
    } else {
        next = manifest?.bundle;
    }
    if (!next?.version || !next?.url) {
        return { available: false, reason: 'invalid-manifest' };
    }

    const currentVersion = localStorage.getItem(channelVersionKey(channel)) || window.__NB_SHELL_BUNDLE_VERSION__ || '0.0.0';
    if (versionGte(currentVersion, next.version) && currentVersion === next.version) {
        return { available: false, reason: 'up-to-date', currentVersion };
    }

    if (next.minShellVersion && window.__NB_SHELL_VERSION__) {
        if (!versionGte(window.__NB_SHELL_VERSION__, next.minShellVersion)) {
            return {
                available: false,
                reason: 'shell-too-old',
                requiredShell: next.minShellVersion,
                currentShell: window.__NB_SHELL_VERSION__,
            };
        }
    }

    return {
        available: true,
        currentVersion,
        nextVersion: next.version,
        url: next.url,
        channel,
    };
}

/**
 * Force a download of the next bundle right now and persist it. Returns:
 *   { applied: true,  version }
 *   { applied: false, reason?, error? }  // includes everything checkForUpdate returns on failure
 */
export async function downloadUpdate() {
    const check = await checkForUpdate();
    if (!check.available) {
        return { applied: false, ...check };
    }

    let blob;
    try {
        const res = await fetch(check.url, { cache: 'no-store' });
        if (!res.ok) throw new Error('HTTP ' + res.status);
        blob = await res.blob();
    } catch (e) {
        return { applied: false, reason: 'download-failed', error: e.message || String(e) };
    }

    try {
        await putCachedBundle(blob, check.channel);
        localStorage.setItem(channelVersionKey(check.channel), check.nextVersion);
        return { applied: true, version: check.nextVersion };
    } catch (e) {
        return { applied: false, reason: 'persist-failed', error: e.message || String(e) };
    }
}

/**
 * Boot-time entry point. Background-only: errors logged, never thrown.
 * Calls downloadUpdate() and applies on next reload.
 */
export async function checkAndDownload() {
    const result = await downloadUpdate();
    if (result.applied) {
        console.info('[NB Push] bundle ' + result.version + ' downloaded — applies on next reload');
    } else if (result.reason && result.reason !== 'not-configured' && result.reason !== 'up-to-date') {
        console.warn('[NB Push] ' + result.reason + (result.error ? ': ' + result.error : ''));
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
