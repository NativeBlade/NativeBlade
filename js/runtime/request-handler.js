import { getInstance } from './php-runtime.js';
import { detectPlatform } from './filesystem.js';
import * as httpBridge from './http-bridge.js';
import * as fsBridge from './fs-bridge.js';
import * as dbBridge from './db-bridge.js';

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

export async function handleRequest(path, options = {}) {
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
    };
    for (const [k, v] of Object.entries(headers)) {
        const key = 'HTTP_' + k.toUpperCase().replace(/-/g, '_');
        serverVars[key] = String(v);
    }

    php.writeFile('/tmp/__nb_server.json', JSON.stringify(serverVars));
    if (body) php.writeFile('/tmp/request_body', body);

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

    if (result.errors) processStderr(result.errors);

    if (await httpBridge.hasPendingRequest(php, text)) {
        fulfillInBackground(php, path, options, 'http');
        return { text: '', errors: '', httpStatusCode: 200, bridgePending: true };
    }
    httpBridge.done(php);

    if (await fsBridge.hasPendingRequest(php, text)) {
        fulfillInBackground(php, path, options, 'fs');
        return { text: '', errors: '', httpStatusCode: 200, bridgePending: true };
    }
    fsBridge.done(php);

    if (await dbBridge.hasPendingRequest(php, text)) {
        fulfillInBackground(php, path, options, 'db');
        return { text: '', errors: '', httpStatusCode: 200, bridgePending: true };
    }
    dbBridge.done(php);

    try {
        const json = JSON.parse(text);
        if (json?.nativeblade && json?.actions) {
            return { text, errors: result.errors, httpStatusCode: 200, nativeblade: json.actions };
        }
    } catch {}

    if (!isJson) text = inlineAssets(text, php);

    return { text, errors: result.errors, httpStatusCode: result.httpStatusCode || 200 };
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
    if (rest) console.warn('[NativeBlade PHP Errors]', rest);
}

async function fulfillInBackground(php, originalPath, originalOptions, type = 'http') {
    const bridge = type === 'db' ? dbBridge : type === 'fs' ? fsBridge : httpBridge;
    const fulfilled = await bridge.fulfill(php);
    if (!fulfilled) return;

    const result = await handleRequest(originalPath, originalOptions);
    if (result.bridgePending) return;

    if (pendingBridgeCallback) {
        pendingBridgeCallback(result);
    }
}

function inlineAssets(html, php) {
    let inlineJs = '';

    html = html.replace(
        /<link[^>]*href="[^"]*\/build\/assets\/([^"]+\.css)"[^>]*\/?>/g,
        (m, file) => { try { return '<style>' + php.readFileAsText('/app/public/build/assets/' + file) + '</style>'; } catch { return ''; } }
    );

    html = html.replace(/<link[^>]*href="[^"]*\/build\/assets\/([^"]+\.js)"[^>]*\/?>/g, () => '');

    html = html.replace(
        /<script[^>]*src="[^"]*\/build\/assets\/([^"]+\.js)"[^>]*><\/script>/g,
        (m, file) => { try { inlineJs += php.readFileAsText('/app/public/build/assets/' + file) + '\n'; } catch {} return ''; }
    );

    html = html.replace(
        /<script\s+src="[^"]*livewire[^"]*\.js[^"]*"([^>]*)><\/script>/g,
        (m, attrs) => {
            try {
                let js;
                try { js = php.readFileAsText('/app/vendor/livewire/livewire/dist/livewire.min.js'); }
                catch { js = php.readFileAsText('/app/vendor/livewire/livewire/dist/livewire.js'); }
                attrs = attrs.replace(/data-module-url="[^"]*"/, 'data-module-url=""')
                    .replace(/data-update-uri="http[s]?:\/\/[^"]*\/livewire/, 'data-update-uri="/livewire');
                return '<script' + attrs + '>' + js + '</script>';
            } catch { return ''; }
        }
    );

    if (inlineJs) html = html.replace('</body>', '<script>' + inlineJs + '</script></body>');

    const mimeMap = { png: 'image/png', jpg: 'image/jpeg', jpeg: 'image/jpeg', gif: 'image/gif', svg: 'image/svg+xml' };

    html = html.replace(
        /(<img[^>]*src=")(?:https?:\/\/[^"]*)?\/([^"]+\.(png|jpg|jpeg|gif|svg))("[^>]*>)/gi,
        (m, before, file, ext, after) => {
            try {
                const content = php.readFileAsText('/app/public/' + file);
                if (content.startsWith('data:')) return before + content + after;
                const mime = mimeMap[ext.toLowerCase()] || 'application/octet-stream';
                const bytes = php.readFileAsBuffer('/app/public/' + file);
                let binary = '';
                for (let i = 0; i < bytes.length; i++) binary += String.fromCharCode(bytes[i]);
                const b64 = btoa(binary);
                return before + 'data:' + mime + ';base64,' + b64 + after;
            } catch {}
            return m;
        }
    );

    return html;
}
