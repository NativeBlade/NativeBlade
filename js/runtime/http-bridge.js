const PENDING_PATH = '/tmp/__nb_http_pending.json';
const CACHE_DIR = '/tmp/__nb_http_cache';
const MAX_RETRIES = 10;

const nativeFetch = window.fetch.bind(window);

let retryCount = 0;
let abortController = null;

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

            const response = await nativeFetch(pending.url, options);
            const body = await response.text();

            return {
                key: pending.key,
                data: {
                    status: response.status,
                    headers: Object.fromEntries(response.headers.entries()),
                    body,
                },
            };
        });

        const results = await Promise.all(fetches);

        try { php.mkdirTree(CACHE_DIR); } catch {}
        for (const { key, data } of results) {
            php.writeFile(`${CACHE_DIR}/${key}.json`, JSON.stringify(data));
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
