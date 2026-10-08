import { getInstance } from './php-runtime.js';
import { detectPlatform } from './filesystem.js';
import * as httpBridge from './http-bridge.js';
import * as fsBridge from './fs-bridge.js';
import * as dbBridge from './db-bridge.js';
import { inlineAssets } from './inline-assets.js';
import { runBridgeCycle, settleBridges } from './bridge-cycle.js';
import { createReplayDetector, readPending, formatDivergence } from './replay-detector.js';
import { reportProblem } from './report.js';
import { isDevMode } from './dev-mode.js';

// Checked in this order after every PHP execution; only one can be pending
// per execution because PHP exits at its first bridge call.
const BRIDGES = { http: httpBridge, fs: fsBridge, db: dbBridge };

// One logical request = several PHP runs. The detector compares the bridge
// calls each run makes and reports the first one that differs, which is the
// symptom of non-deterministic PHP before a bridge call (see replay-detector.js).
const replay = createReplayDetector();

// The main window's bridge-completion callback (posts responses back to the app
// iframe). A single global is fine for the main window because Livewire drives
// its requests one at a time. A caller that needs its OWN completion (the window
// relay serving a satellite) passes a per-request `onBridge` to request() instead
// of swapping this global — so the two can never clobber each other.
let pendingBridgeCallback = null;

export function setOnBridgeComplete(fn) {
    pendingBridgeCallback = fn;
}

const STATIC_MIME = {
    '.png': 'image/png', '.jpg': 'image/jpeg', '.jpeg': 'image/jpeg',
    '.gif': 'image/gif', '.svg': 'image/svg+xml', '.ico': 'image/x-icon',
    '.css': 'text/css', '.js': 'application/javascript',
    '.woff': 'font/woff', '.woff2': 'font/woff2', '.ttf': 'font/ttf',
};

export async function handleRequest(path, options = {}, onBridge = null) {
    const php = getInstance();
    if (!php) throw new Error('PHP not initialized');

    const method = (options.method || 'GET').toUpperCase();
    const body = options.body || '';
    const headers = options.headers || {};
    const contentType = headers['Content-Type'] || headers['content-type'] || '';
    const isJson = contentType.includes('application/json');

    // Build an $_SERVER bootstrap array on disk (as JSON) and load it from PHP.
    // Interpolating user-controlled strings straight into PHP source is unsafe —
    // a stray quote/backslash in a URL, header or body would either crash the
    // parser or, in a hostile environment, allow code injection. Passing through
    // JSON + json_decode keeps the PHP source static and handles any bytes.
    const serverVars = {
        DOCUMENT_ROOT: '/app/public',
        SCRIPT_FILENAME: '/app/public/index.php',
        SCRIPT_NAME: '/index.php',
        PHP_SELF: '/index.php',
        REQUEST_URI: String(path),
        REQUEST_METHOD: method,
        SERVER_NAME: 'localhost',
        SERVER_PORT: '80',
        HTTP_HOST: 'localhost',
        HTTP_ACCEPT: 'text/html',
        CONTENT_TYPE: String(contentType),
        CONTENT_LENGTH: String(body.length),
        APP_BASE_PATH: '/app',
        NATIVEBLADE_PLATFORM: detectPlatform(),
        NATIVEBLADE_TIMESTAMP: String(Math.floor(Date.now() / 1000)),
        // Lets PHP (NativeBlade::isDev()) fail loud only while served by nativeblade:dev.
        NATIVEBLADE_DEV: isDevMode() ? '1' : '0',
    };
    for (const [k, v] of Object.entries(headers)) {
        const key = 'HTTP_' + k.toUpperCase().replace(/-/g, '_');
        serverVars[key] = String(v);
    }

    php.writeFile('/tmp/__nb_server.json', JSON.stringify(serverVars));
    if (body) php.writeFile('/tmp/request_body', body);

    // Shell-module ride-along: snapshot the current shell-owned #[NativeProp]
    // values (window.__NB_SHELL_PROPS__ is set by the shell-module action) so
    // HasNativeShell components read them fresh at hydrate — zero extra requests.
    // Always overwrite, even with {}: a leftover snapshot from a previous
    // request would hydrate stale values after a module is destroyed.
    try {
        const shellProps = typeof window !== 'undefined' && typeof window.__NB_SHELL_PROPS__ === 'function'
            ? window.__NB_SHELL_PROPS__()
            : null;
        php.writeFile(
            '/tmp/__nb_shell_props.json',
            JSON.stringify(shellProps && typeof shellProps === 'object' ? shellProps : {})
        );
    } catch {}

    const hasBody = body ? '1' : '0';
    const parsePost = (!isJson && body) ? '1' : '0';

    const code = `<?php
        chdir('/app/public');
        $__nb_server = json_decode(file_get_contents('/tmp/__nb_server.json'), true) ?: [];
        foreach ($__nb_server as $__nb_k => $__nb_v) { $_SERVER[$__nb_k] = $__nb_v; }
        unset($__nb_server, $__nb_k, $__nb_v);
        putenv('APP_BASE_PATH=/app');
        if (${hasBody}) {
            $GLOBALS['__wasm_request_body'] = file_get_contents('/tmp/request_body');
            if (${parsePost}) {
                $_POST = [];
                parse_str($GLOBALS['__wasm_request_body'], $_POST);
            }
        }
        require '/app/public/index.php';
    `;

    const result = await php.run({ code });
    let text = result.text || '';

    // Each run of a logical request starts PHP from scratch, so the last run's
    // stderr holds every log line and error the request produced. Keeping only
    // the latest run and flushing it once the request settles (or is
    // abandoned) is what stops a NativeBlade::log() before an Http call from
    // showing up twice.
    bufferedStderr = result.errors || '';

    const pending = await settleBridges({
        php,
        text,
        bridges: BRIDGES,
        startCycle: (type) => {
            // In development a divergence ends the request: re-running after
            // it only repeats the same mistake until the retry budget is gone,
            // with a copy of every log line each time. A store build keeps
            // going, since some divergences still converge.
            if (reportReplayDivergence(php, type) && isDevMode()) {
                return abandonInBackground(php, onBridge);
            }
            return fulfillInBackground(php, path, options, type, onBridge);
        },
    });
    if (pending) {
        return { text: '', errors: '', httpStatusCode: 200, bridgePending: true };
    }
    replay.reset();
    flushStderr();

    try {
        const json = JSON.parse(text);
        if (json?.nativeblade && json?.actions) {
            return { text, errors: result.errors, httpStatusCode: 200, nativeblade: json.actions };
        }
    } catch {}

    if (!isJson) text = inlineAssets(text, php);

    return { text, errors: result.errors, httpStatusCode: result.httpStatusCode || 200 };
}

let bufferedStderr = '';

function flushStderr() {
    const raw = bufferedStderr;
    bufferedStderr = '';
    if (raw) processStderr(raw);
}

function processStderr(raw) {
    const logPattern = /__NB_LOG__([\s\S]+?)__NB_LOG_END__/g;
    let match;
    while ((match = logPattern.exec(raw)) !== null) {
        try {
            const entry = JSON.parse(match[1]);
            window.parent.postMessage({
                type: 'nativeblade-native',
                action: 'log',
                payload: entry,
            }, '*');
        } catch {}
    }
    const rest = raw.replace(logPattern, '').trim();
    if (rest) {
        // PHP warnings and fatals go through the log action, so they reach the
        // log file, the dev terminal and the dev overlay, not only the console.
        reportProblem('error', rest, {}, 'php');
    }
}

function fulfillInBackground(php, originalPath, originalOptions, type = 'http', onBridge = null) {
    return runBridgeCycle({
        php,
        bridge: BRIDGES[type],
        rerun: () => handleRequest(originalPath, originalOptions, onBridge),
        getCallback: () => onBridge || pendingBridgeCallback,
        onAbandon: () => abandonRequest(php),
    });
}

// Ends the logical request without a result, through the same path a bridge
// that gave up takes (the waiting caller receives BRIDGE_ABORTED).
function abandonInBackground(php, onBridge = null) {
    return runBridgeCycle({
        php,
        bridge: { fulfill: async () => false },
        rerun: async () => ({ bridgePending: true }),
        getCallback: () => onBridge || pendingBridgeCallback,
        onAbandon: () => abandonRequest(php),
    });
}

function abandonRequest(php) {
    flushStderr();
    replay.reset();
    for (const bridge of Object.values(BRIDGES)) bridge.done(php);
}

/** @returns {boolean} true when at least one divergence was found and reported */
function reportReplayDivergence(php, type) {
    let findings;
    try {
        findings = replay.track(type, readPending(php, BRIDGES[type].PENDING_PATH));
    } catch {
        return false;
    }
    for (const finding of findings) {
        reportProblem('error', formatDivergence(finding), {
            type: finding.type, index: finding.index, was: finding.was, now: finding.now,
        });
    }
    return findings.length > 0;
}
