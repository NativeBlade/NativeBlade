const PENDING_PATH = '/tmp/__nb_http_pending.json';
const CACHE_DIR = '/tmp/__nb_http_cache';
const MAX_RETRIES = 10;

const nativeFetch = (typeof window !== 'undefined' && typeof window.fetch === 'function')
    ? window.fetch.bind(window)
    : (typeof fetch === 'function' ? fetch : null);

let retryCount = 0;
let abortController = null;

let _fetchOverride = null;
export function __setFetchForTests(fn) { _fetchOverride = fn; }
export function __resetForTests() {
    retryCount = 0;
    abortController = null;
    _fetchOverride = null;
}

export async function hasPendingRequest(php, output) {
    return typeof output === 'string' && output.includes('__NB_HTTP_PENDING__');
}

export async function fulfill(php) {
    if (retryCount >= MAX_RETRIES) {
        cleanup(php);
        return false;
    }
    retryCount++;

    abortController = new AbortController();

    try {
        const pendingList = JSON.parse(php.readFileAsText(PENDING_PATH));
        if (!Array.isArray(pendingList) || pendingList.length === 0) {
            cleanup(php);
            return false;
        }

        const signal = abortController.signal;

        const fetches = pendingList.map(async (pending) => {
            const options = { method: pending.method || 'GET', signal };
            if (pending.headers && Object.keys(pending.headers).length) {
                options.headers = pending.headers;
            }
            if (pending.body) {
                options.body = pending.body;
            }

            const response = await (_fetchOverride ?? nativeFetch)(pending.url, options);
            const body = await response.text();

            return {
                status: response.status,
                headers: Object.fromEntries(response.headers.entries()),
                body,
            };
        });

        const settled = await Promise.allSettled(fetches);

        const allAborted = settled.every(r =>
            r.status === 'rejected' && r.reason && r.reason.name === 'AbortError'
        );
        if (allAborted) {
            abortController = null;
            return false;
        }

        try { php.mkdirTree(CACHE_DIR); } catch {}

        for (let i = 0; i < settled.length; i++) {
            const pending = pendingList[i];
            const res = settled[i];

            if (res.status === 'fulfilled') {
                php.writeFile(`${CACHE_DIR}/${pending.key}.json`, JSON.stringify(res.value));
            } else if (res.reason && res.reason.name === 'AbortError') {
                continue;
            } else {
                php.writeFile(`${CACHE_DIR}/${pending.key}.json`, JSON.stringify({
                    status: 0,
                    headers: {},
                    body: '',
                    error: (res.reason && res.reason.message) || String(res.reason),
                }));
            }
        }

        try { php.unlink(PENDING_PATH); } catch {}
        abortController = null;
        return true;
    } catch (err) {
        abortController = null;
        if (err.name === 'AbortError') return false;
        cleanup(php);
        return false;
    }
}

export function abort() {
    if (abortController) {
        abortController.abort();
        abortController = null;
    }
    retryCount = 0;
}

export function done(php) {
    retryCount = 0;
    abortController = null;
    clearCache(php);
}

function cleanup(php) {
    retryCount = 0;
    abortController = null;
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
