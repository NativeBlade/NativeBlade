const PENDING_PATH = '/tmp/__nb_http_pending.json';
const CACHE_DIR = '/tmp/__nb_http_cache';
const MAX_RETRIES = 10;

const nativeFetch = window.fetch.bind(window);

let retryCount = 0;

/**
 * Check if PHP output signals a pending HTTP request.
 * If so, fulfill it and return true (caller should re-execute PHP).
 */
export async function hasPendingRequest(php, output) {
    return typeof output === 'string' && output.includes('__NB_HTTP_PENDING__');
}

/**
 * Fulfill the pending HTTP request by making a real fetch,
 * then caching the result in the WASM filesystem for the PHP re-run.
 * Returns true if fulfilled successfully and PHP should be re-executed.
 */
export async function fulfill(php) {
    if (retryCount >= MAX_RETRIES) {
        console.error('[NativeBlade HTTP] Max retries reached');
        cleanup(php);
        return false;
    }
    retryCount++;

    try {
        const pending = JSON.parse(php.readFileAsText(PENDING_PATH));
        if (!pending?.url) {
            cleanup(php);
            return false;
        }

        console.log(`[NativeBlade HTTP] ${pending.method} ${pending.url}`);

        const options = { method: pending.method || 'GET' };
        if (pending.headers && Object.keys(pending.headers).length) {
            options.headers = pending.headers;
        }
        if (pending.body) {
            options.body = pending.body;
        }

        const response = await nativeFetch(pending.url, options);
        const body = await response.text();

        const cached = JSON.stringify({
            status: response.status,
            headers: Object.fromEntries(response.headers.entries()),
            body,
        });

        try { php.mkdirTree(CACHE_DIR); } catch {}
        php.writeFile(`${CACHE_DIR}/${pending.key}.json`, cached);
        try { php.unlink(PENDING_PATH); } catch {}

        return true;
    } catch (err) {
        console.error('[NativeBlade HTTP] Bridge error:', err);
        cleanup(php);
        return false;
    }
}

/** Reset retry counter and clean cache — call after a full request cycle completes. */
export function done(php) {
    retryCount = 0;
    clearCache(php);
}

function cleanup(php) {
    retryCount = 0;
    try { php.unlink(PENDING_PATH); } catch {}
    clearCache(php);
}

function clearCache(php) {
    try {
        const files = php.listFiles(CACHE_DIR);
        for (const f of files) {
            if (f !== '.' && f !== '..') {
                try { php.unlink(CACHE_DIR + '/' + f); } catch {}
            }
        }
    } catch {}
}
