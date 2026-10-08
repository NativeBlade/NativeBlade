import { getInstance } from './php-runtime.js';
import { detectPlatform } from './filesystem.js';
import { inlineAssets } from './inline-assets.js';
import { reportProblem } from './report.js';
import { isDevMode } from './dev-mode.js';
import { installNativeBridge, lastNativeCall } from './native-bridge.js';
import { beginRequest as beginHttpRequest } from './http-bridge.js';
import { explainRuntimeCrash } from './runtime-crash.js';

const STATIC_MIME = {
    '.png': 'image/png', '.jpg': 'image/jpeg', '.jpeg': 'image/jpeg',
    '.gif': 'image/gif', '.svg': 'image/svg+xml', '.ico': 'image/x-icon',
    '.css': 'text/css', '.js': 'application/javascript',
    '.woff': 'font/woff', '.woff2': 'font/woff2', '.ttf': 'font/ttf',
};

/**
 * Run one request through PHP and return its final result. Native calls
 * (HTTP, database, filesystem) happen inside the run: PHP is suspended at
 * post_message_to_js() while the shell does the work, then resumes. See
 * native-bridge.js.
 */
export async function handleRequest(path, options = {}) {
    const php = getInstance();
    if (!php) throw new Error('PHP not initialized');
    installNativeBridge(php);

    const method = (options.method || 'GET').toUpperCase();
    const body = options.body || '';
    const headers = options.headers || {};
    const contentType = headers['Content-Type'] || headers['content-type'] || '';
    const isJson = contentType.includes('application/json');

    // Build an $_SERVER bootstrap array on disk (as JSON) and load it from PHP.
    // Interpolating user-controlled strings straight into PHP source is unsafe:
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
    // HasNativeShell components read them fresh at hydrate, with no extra requests.
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

    // A navigation that aborted the previous request must not bleed into this one.
    beginHttpRequest();

    let result;
    try {
        result = await php.run({ code });
    } catch (err) {
        // Last resort for a native call the SuspendGuard did not foresee: the
        // instance is gone, so say where it died and reload the shell.
        const explained = explainRuntimeCrash(err, lastNativeCall());
        if (explained) {
            reportProblem('error', explained);
            scheduleReload();
        }
        throw err;
    }
    let text = result.text || '';

    if (result.errors) processStderr(result.errors);

    try {
        const json = JSON.parse(text);
        if (json?.nativeblade && json?.actions) {
            return { text, errors: result.errors, httpStatusCode: 200, nativeblade: json.actions };
        }
    } catch {}

    if (!isJson) text = inlineAssets(text, php);

    return { text, errors: result.errors, httpStatusCode: result.httpStatusCode || 200 };
}

// One reload per crash, never a loop: a crash during the reload's own boot
// leaves the flag set and only reports.
function scheduleReload() {
    if (typeof window === 'undefined' || !window.location) return;
    try {
        if (window.sessionStorage.getItem('nb:crash-reload') === '1') return;
        window.sessionStorage.setItem('nb:crash-reload', '1');
        setTimeout(() => window.location.reload(), 1500);
    } catch {}
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
