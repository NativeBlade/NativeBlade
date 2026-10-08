// HTTP executor for the native bridge: one fetch per `http` message, or a
// parallel batch for `http_pool` (NativeBlade::pool()). PHP is suspended
// while the fetch runs and receives `{status, headers, body}`; a failed
// fetch comes back as status 0 with the error, which the handler turns into
// a failed (503) response instead of an exception.
//
// A navigation calls abort(): the fetch in flight is cancelled and every
// later HTTP call of the same request fails at once, so the stale request
// finishes quickly and the new page's request, queued behind it, starts.
// beginRequest() re-arms the executor for the next request.

const nativeFetch = (typeof window !== 'undefined' && typeof window.fetch === 'function')
    ? window.fetch.bind(window)
    : (typeof fetch === 'function' ? fetch : null);

let abortController = null;
let cancelled = false;
let _fetchOverride = null;

export function __setFetchForTests(fn) { _fetchOverride = fn; }
export function __resetForTests() {
    abortController = null;
    cancelled = false;
    _fetchOverride = null;
}

/** Called by the request handler before each PHP run. */
export function beginRequest() {
    abortController = null;
    cancelled = false;
}

/**
 * Abort the fetches of the request in flight and refuse the ones it still
 * makes (a navigation left the page that asked for them). PHP gets failed
 * responses and finishes; its result is dropped by the navigation generation
 * check.
 */
export function abort() {
    cancelled = true;
    if (abortController) {
        abortController.abort();
        abortController = null;
    }
}

export async function execute(message, context = {}) {
    return fetchOne(message, currentSignal(), context);
}

export async function executePool(message, context = {}) {
    const requests = Array.isArray(message.requests) ? message.requests : [];
    const signal = currentSignal();
    return Promise.all(requests.map((request) => fetchOne(request, signal, context)));
}

function currentSignal() {
    if (!abortController) abortController = new AbortController();
    return abortController.signal;
}

async function fetchOne(request, signal, context) {
    if (cancelled) return aborted();

    const options = { method: request.method || 'GET', signal };
    if (request.headers && Object.keys(request.headers).length) {
        options.headers = request.headers;
    }

    try {
        const body = requestBody(request, context);
        if (body) options.body = body;

        const doFetch = _fetchOverride ?? nativeFetch;
        if (!doFetch) throw new Error('fetch is not available');
        const response = await doFetch(request.url, options);
        const text = await response.text();
        return {
            status: response.status,
            headers: Object.fromEntries(response.headers.entries()),
            body: text,
        };
    } catch (err) {
        if (err && err.name === 'AbortError') return aborted();
        return { status: 0, headers: {}, body: '', error: (err && err.message) || String(err) };
    }
}

/**
 * Small bodies travel base64 inside the message. Large ones (an upload with
 * several photos) are written by PHP to its own filesystem and only the path
 * travels, which spares a base64 copy and a JSON copy of every megabyte.
 */
function requestBody(request, context) {
    if (request.bodyFile) {
        const php = context.php;
        if (!php || typeof php.readFileAsBuffer !== 'function') {
            throw new Error('request body file is not readable: no php instance');
        }
        const bytes = php.readFileAsBuffer(request.bodyFile);
        try { php.unlink(request.bodyFile); } catch {}
        return bytes;
    }
    if (request.body) return base64ToBytes(request.body);
    return null;
}

function aborted() {
    return { status: 0, headers: {}, body: '', error: 'aborted' };
}

function base64ToBytes(b64) {
    const binary = atob(b64);
    const bytes = new Uint8Array(binary.length);
    for (let i = 0; i < binary.length; i++) {
        bytes[i] = binary.charCodeAt(i);
    }
    return bytes;
}
