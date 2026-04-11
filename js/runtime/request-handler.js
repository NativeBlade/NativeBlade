import { getInstance } from './php-runtime.js';
import { detectPlatform } from './filesystem.js';
import * as httpBridge from './http-bridge.js';
import * as fsBridge from './fs-bridge.js';

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
    const escapedBody = body.replace(/\\/g, '\\\\').replace(/'/g, "\\'");

    const headerLines = Object.entries(headers).map(([k, v]) => {
        const key = 'HTTP_' + k.toUpperCase().replace(/-/g, '_');
        const val = String(v).replace(/'/g, "\\'");
        return `$_SERVER['${key}'] = '${val}';`;
    }).join('\n        ');

    if (body) php.writeFile('/tmp/request_body', body);

    const code = `<?php
        chdir('/app/public');
        $_SERVER['DOCUMENT_ROOT'] = '/app/public';
        $_SERVER['SCRIPT_FILENAME'] = '/app/public/index.php';
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $_SERVER['PHP_SELF'] = '/index.php';
        $_SERVER['REQUEST_URI'] = '${path}';
        $_SERVER['REQUEST_METHOD'] = '${method}';
        $_SERVER['SERVER_NAME'] = 'localhost';
        $_SERVER['SERVER_PORT'] = '80';
        $_SERVER['HTTP_HOST'] = 'localhost';
        $_SERVER['HTTP_ACCEPT'] = 'text/html';
        $_SERVER['CONTENT_TYPE'] = '${contentType}';
        $_SERVER['CONTENT_LENGTH'] = '${body.length}';
        $_SERVER['APP_BASE_PATH'] = '/app';
        $_SERVER['NATIVEBLADE_PLATFORM'] = '${detectPlatform()}';
        $_SERVER['NATIVEBLADE_TIMESTAMP'] = '${Math.floor(Date.now() / 1000)}';
        putenv('APP_BASE_PATH=/app');
        ${headerLines}
        ${body ? `$GLOBALS['__wasm_request_body'] = file_get_contents('/tmp/request_body');` : ''}
        ${!isJson && body ? `$_POST = []; parse_str('${escapedBody}', $_POST);` : ''}
        require '/app/public/index.php';
    `;

    const result = await php.run({ code });
    let text = result.text || '';

    if (result.errors) console.warn('[NativeBlade PHP Errors]', result.errors);

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

    try {
        const json = JSON.parse(text);
        if (json?.nativeblade && json?.actions) {
            return { text, errors: result.errors, httpStatusCode: 200, nativeblade: json.actions };
        }
    } catch {}

    if (!isJson) text = inlineAssets(text, php);

    return { text, errors: result.errors, httpStatusCode: result.httpStatusCode || 200 };
}

async function fulfillInBackground(php, originalPath, originalOptions, type = 'http') {
    const bridge = type === 'fs' ? fsBridge : httpBridge;
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
