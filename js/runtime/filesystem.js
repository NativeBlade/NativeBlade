import { getInstance } from './php-runtime.js';
import { getBundleBase } from './bundle-base.js';
import { tryLoadCachedBundle } from './bundle-push.js';

export { getBundleBase };

export function detectPlatform() {
    const ua = navigator.userAgent;

    if (/Android/i.test(ua)) return 'android';
    if (/iPhone|iPad|iPod/i.test(ua)) return 'ios';

    if (/Macintosh|Mac OS X/i.test(ua)) return 'macos';
    if (/Windows/i.test(ua)) return 'windows';
    if (/Linux/i.test(ua)) return 'linux';

    return 'web';
}

export function prepareDirs() {
    const php = getInstance();
    ['/app/public', '/app/database', '/app/storage/framework/views',
     '/app/storage/framework/cache/data', '/app/storage/framework/sessions',
     '/app/storage/logs', '/tmp'
    ].forEach(d => php.mkdirTree(d));
}

const FETCH_TIMEOUT_MS = 60000;
const FAILURE_COUNTER_KEY = 'nb:bundleBaseFailures';
const FAILURE_THRESHOLD = 3;

function timedFetch(url, ms = FETCH_TIMEOUT_MS) {
    if (typeof AbortController === 'undefined') {
        return fetch(url);
    }
    const ctrl = new AbortController();
    const timer = setTimeout(() => ctrl.abort(new Error('timeout')), ms);
    return fetch(url, { signal: ctrl.signal }).finally(() => clearTimeout(timer));
}

async function decompressGzipBytes(bytes) {
    if (typeof DecompressionStream !== 'undefined') {
        try {
            const stream = new Blob([bytes]).stream().pipeThrough(new DecompressionStream('gzip'));
            return await new Response(stream).text();
        } catch (e) {
            console.warn('[NB] DecompressionStream failed on bytes, falling back to fflate:', e?.message || e);
        }
    }

    const { gunzipSync, strFromU8 } = await import('https://cdn.jsdelivr.net/npm/fflate@0.8.2/+esm');
    return strFromU8(gunzipSync(new Uint8Array(bytes)));
}

async function tryFetchAt(base) {
    try {
        const res = await timedFetch(base + 'laravel-bundle.json.gz');
        if (!res.ok) {
            console.warn('[NB] bundle .json.gz fetch failed at', base, 'HTTP', res.status);
        } else {
            try {
                const bytes = await res.arrayBuffer();
                return await decompressGzipBytes(bytes);
            } catch (e) {
                console.warn('[NB] bundle .json.gz decompression failed at', base, e?.message || e);
            }
        }
    } catch (e) {
        console.warn('[NB] bundle .json.gz fetch threw at', base, e?.message || e);
    }

    try {
        const res = await timedFetch(base + 'laravel-bundle.json');
        if (!res.ok) {
            console.warn('[NB] bundle .json fetch failed at', base, 'HTTP', res.status);
            return null;
        }
        return await res.text();
    } catch (e) {
        console.warn('[NB] bundle .json fetch threw at', base, e?.message || e);
        return null;
    }
}

function readFailureCount() {
    try {
        const raw = window.localStorage?.getItem?.(FAILURE_COUNTER_KEY);
        return raw ? parseInt(raw, 10) || 0 : 0;
    } catch {
        return 0;
    }
}

function bumpFailureCount() {
    try {
        const next = readFailureCount() + 1;
        window.localStorage?.setItem?.(FAILURE_COUNTER_KEY, String(next));
        return next;
    } catch {
        return 0;
    }
}

function clearFailureCount() {
    try { window.localStorage?.removeItem?.(FAILURE_COUNTER_KEY); } catch {}
}

async function fetchBundleJson() {
    const cached = await tryLoadCachedBundle();
    if (cached) {
        clearFailureCount();
        return cached;
    }

    const base = getBundleBase();
    const text = await tryFetchAt(base);
    if (text) {
        clearFailureCount();
        return text;
    }

    if (base !== './') {
        const failures = bumpFailureCount();
        const shouldGiveUp = failures >= FAILURE_THRESHOLD;

        if (shouldGiveUp) {
            try { window.localStorage?.removeItem?.('nb:bundleBase'); } catch {}
            try { delete window.__NB_BUNDLE_BASE__; } catch {}
            clearFailureCount();
            console.warn('[NB] gave up on custom bundle base after', failures, 'failures. Cleared persisted URL.');
        }

        const fallback = await tryFetchAt('./');
        if (fallback) {
            try {
                window.__NB_BUNDLE_FALLBACK__ = {
                    attemptedBase: base,
                    attemptedUrl: base + 'laravel-bundle.json.gz',
                    failures,
                    cleared: shouldGiveUp,
                    at: Date.now(),
                };
                window.localStorage?.setItem?.('nb:bundleFallback', JSON.stringify(window.__NB_BUNDLE_FALLBACK__));
            } catch {}
            console.warn('[NB] custom bundle base unreachable (failure', failures + '). Loaded embedded bundle. Will retry on next launch unless cleared.');
            return fallback;
        }
    }

    throw new Error('Bundle fetch failed: unreachable and no fallback bundle available');
}

export async function loadBundle(onProgress) {
    const php = getInstance();
    const text = await fetchBundleJson();
    const bundle = JSON.parse(text);
    const paths = Object.keys(bundle);
    let loaded = 0;

    for (const path of paths) {
        const fullPath = '/app' + path;
        try {
            php.mkdirTree(fullPath.substring(0, fullPath.lastIndexOf('/')));
            php.writeFile(fullPath, bundle[path]);
        } catch {}
        if (++loaded % 500 === 0) onProgress?.(`Loading files... ${loaded}/${paths.length}`);
    }
}

export function patchEnv() {
    const php = getInstance();
    let env = php.readFileAsText('/app/.env');
    env = env.replace(/APP_DEBUG=.*/, 'APP_DEBUG=true');
    env = env.replace(/DB_CONNECTION=.*/, 'DB_CONNECTION=sqlite');
    env = env.replace(/SESSION_DRIVER=.*/, 'SESSION_DRIVER=file');
    env = env.replace(/CACHE_STORE=.*/, 'CACHE_STORE=file');
    env = env.replace(/QUEUE_CONNECTION=.*/, 'QUEUE_CONNECTION=sync');
    php.writeFile('/app/.env', env);

    try { php.readFileAsText('/app/database/database.sqlite'); }
    catch { php.writeFile('/app/database/database.sqlite', ''); }
}

export async function runMigrations() {
    const php = getInstance();
    try {
        await php.run({
            code: `<?php
                chdir('/app');
                $_SERVER['APP_BASE_PATH'] = '/app';
                putenv('APP_BASE_PATH=/app');
                require '/app/vendor/autoload.php';
                $app = require '/app/bootstrap/app.php';
                $kernel = $app->make(Illuminate\\Contracts\\Console\\Kernel::class);
                $kernel->call('migrate', ['--force' => true]);
            `
        });
    } catch {}
}
