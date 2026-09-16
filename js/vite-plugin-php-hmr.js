import { readFileSync, readdirSync } from 'fs';
import path from 'path';
import os from 'node:os';

const UNLINK_GRACE_MS = 1000;

export default function phpHmrPlugin(projectRoot) {
    const changes = [];
    let version = 0;
    let serverUrl = '';
    let isServe = false;

    const wasmPathMap = {
        'app': '/app/app',
        'resources': '/app/resources',
        'routes': '/app/routes',
        'config': '/app/config',
        'database': '/app/database',
        'lang': '/app/lang',
    };

    function toWasmPath(filePath) {
        const rel = path.relative(projectRoot, filePath).replace(/\\/g, '/');
        for (const [dir, wasmDir] of Object.entries(wasmPathMap)) {
            if (rel.startsWith(dir + '/')) {
                return wasmDir + rel.substring(dir.length);
            }
        }
        return '/app/' + rel;
    }

    return {
        name: 'nativeblade-php-hmr',

        configResolved(config) {
            isServe = config.command === 'serve';
        },

        config(userConfig) {
            const host = resolvePublicHost();
            if (!host) return {};
            const port = userConfig.server?.port || 1420;
            return {
                server: {
                    hmr: { host, port, clientPort: port, protocol: 'ws' },
                },
            };
        },

        configureServer(server) {
            // nativeblade:dev turns this on by default via the env flag (off with
            // --no-css, or when the project has no Vite build). It makes the watcher
            // also hot-push the Laravel Vite build output (Tailwind CSS + manifest).
            const cssHot = !!process.env.NATIVEBLADE_CSS_HOT;

            const dirs = ['app', 'resources/views', 'routes', 'config', 'lang', 'public'];
            for (const dir of dirs) {
                server.watcher.add(path.join(projectRoot, dir));
            }
            if (cssHot) server.watcher.add(path.join(projectRoot, 'public/build'));

            server.httpServer?.once('listening', () => {
                const address = server.httpServer.address();
                if (address && typeof address === 'object') {
                    const host = resolvePublicHost()
                        || (typeof server.config.server.host === 'string' && server.config.server.host !== '0.0.0.0'
                            ? server.config.server.host
                            : detectLanIp() || 'localhost');
                    serverUrl = `http://${host}:${address.port}`;
                }
            });

            // PHP/Blade/JSON in the watched source dirs, plus CSS/JS assets under
            // public/ — the inliner pulls those into the page, so hot-pushing them
            // and re-rendering shows the change with no rebuild. Never the generated
            // bundle or vite's build output.
            const isWatched = (filePath) => {
                const rel = path.relative(projectRoot, filePath).replace(/\\/g, '/');
                if (rel.startsWith('public/')) {
                    if (rel.startsWith('public/laravel-bundle')) return false;
                    if (rel.startsWith('public/build/')) {
                        // Vite/Tailwind build output — only when CSS hot is on (env
                        // flag). Text assets only (css/js/manifest); binary fonts stay
                        // in the bundle.
                        return cssHot && /\.(css|js|json)$/.test(rel);
                    }
                    return /\.(css|js)$/.test(rel);
                }
                return /\.(php|blade\.php|json)$/.test(filePath);
            };

            const kindFor = (filePath) => {
                if (/\.blade\.php$/.test(filePath)) return 'blade';
                if (/\.css$/.test(filePath)) return 'css';
                if (/\.js$/.test(filePath)) return 'js';
                if (/\.json$/.test(filePath)) return 'json';
                return 'php';
            };

            // Last content pushed per file. A save that leaves a file identical is
            // not a change: the Tailwind watcher rewrites public/build on every
            // Blade save, usually byte for byte, and each pushed batch makes the
            // app re-render, so unchanged rewrites showed up as extra reloads.
            const lastContent = new Map();
            if (cssHot) {
                for (const filePath of listFiles(path.join(projectRoot, 'public/build'))) {
                    if (!isWatched(filePath)) continue;
                    try { lastContent.set(toWasmPath(filePath), readFileSync(filePath, 'utf-8')); } catch {}
                }
            }

            // Vite empties public/build before each rebuild and writes the same
            // files back ~30 ms later. A delete is only pushed if the file stays
            // gone for UNLINK_GRACE_MS; if it comes back first, it is compared to
            // the content it had before like any other write.
            const pendingUnlinks = new Map();

            const emit = (op, filePath, content) => {
                try {
                    const wasmPath = toWasmPath(filePath);

                    const pending = pendingUnlinks.get(wasmPath);
                    if (pending) {
                        clearTimeout(pending);
                        pendingUnlinks.delete(wasmPath);
                    }

                    if (op === 'unlink') {
                        pendingUnlinks.set(wasmPath, setTimeout(() => {
                            pendingUnlinks.delete(wasmPath);
                            lastContent.delete(wasmPath);
                            publish('unlink', filePath, wasmPath, null);
                        }, UNLINK_GRACE_MS));
                        return;
                    }

                    if (lastContent.get(wasmPath) === content) return;
                    lastContent.set(wasmPath, content);

                    publish(op, filePath, wasmPath, content);
                } catch {}
            };

            const publish = (op, filePath, wasmPath, content) => {
                try {
                    const kind = kindFor(filePath);
                    version++;

                    const change = { op, wasmPath, content, kind, version };
                    changes.push(change);
                    if (changes.length > 100) changes.splice(0, changes.length - 100);

                    server.ws.send('php-file-changed', change);
                } catch {}
            };

            server.watcher.on('change', (filePath) => {
                if (!isWatched(filePath)) return;
                try { emit('change', filePath, readFileSync(filePath, 'utf-8')); } catch {}
            });

            server.watcher.on('add', (filePath) => {
                if (!isWatched(filePath)) return;
                try { emit('add', filePath, readFileSync(filePath, 'utf-8')); } catch {}
            });

            server.watcher.on('unlink', (filePath) => {
                if (!isWatched(filePath)) return;
                emit('unlink', filePath, null);
            });

            // Permissive CORS on every response so the installed Portal app
            // (served from a different origin) can fetch the bundle and poll.
            server.middlewares.use((req, res, next) => {
                res.setHeader('Access-Control-Allow-Origin', '*');
                res.setHeader('Access-Control-Allow-Methods', 'GET, HEAD, OPTIONS');
                res.setHeader('Access-Control-Allow-Headers', '*');
                if (req.method === 'OPTIONS') {
                    res.statusCode = 204;
                    res.end();
                    return;
                }
                next();
            });

            // App identity for the Portal's app list: product name + icon as a
            // data URL (smallest icon first — this lands in the Portal's
            // localStorage, one entry per remembered app).
            server.middlewares.use((req, res, next) => {
                if (!req.url.startsWith('/__app_meta')) return next();

                res.setHeader('Content-Type', 'application/json');

                let name = '';
                try {
                    const conf = JSON.parse(readFileSync(path.join(projectRoot, 'src-tauri/tauri.conf.json'), 'utf-8'));
                    name = conf.productName || '';
                } catch {}
                if (!name) name = path.basename(projectRoot);

                let icon = '';
                for (const candidate of ['128x128.png', 'icon.png', 'logo.png']) {
                    try {
                        const buf = readFileSync(path.join(projectRoot, 'src-tauri/icons', candidate));
                        icon = 'data:image/png;base64,' + buf.toString('base64');
                        break;
                    } catch {}
                }

                res.end(JSON.stringify({ name, icon }));
            });

            server.middlewares.use((req, res, next) => {
                if (!req.url.startsWith('/__php_changes') && !req.url.startsWith('/__php_version')) {
                    return next();
                }

                res.setHeader('Content-Type', 'application/json');

                if (req.url.startsWith('/__php_version')) {
                    res.end(JSON.stringify({ version }));
                    return;
                }

                const url = new URL(req.url, 'http://localhost');
                const since = parseInt(url.searchParams.get('since') || '0', 10);
                const pending = changes.filter(c => c.version > since);
                res.end(JSON.stringify({ version, changes: pending }));
            });
        },

        transformIndexHtml() {
            if (!isServe) return [];
            return [
                {
                    tag: 'meta',
                    attrs: { name: 'nativeblade-vite-url', content: serverUrl },
                    injectTo: 'head-prepend',
                },
            ];
        },
    };
}

function listFiles(dir) {
    const files = [];
    try {
        for (const entry of readdirSync(dir, { withFileTypes: true })) {
            const full = path.join(dir, entry.name);
            if (entry.isDirectory()) files.push(...listFiles(full));
            else if (entry.isFile()) files.push(full);
        }
    } catch {}
    return files;
}

function resolvePublicHost() {
    const envHost = process.env.NATIVEBLADE_HOST;
    if (envHost && envHost !== '0.0.0.0' && envHost !== 'localhost') return envHost;
    return null;
}

function isUsableLanIp(ip) {
    if (ip.startsWith('127.') || ip.startsWith('169.254.')) return false;
    if (ip.startsWith('192.168.56.') || ip.startsWith('192.168.99.')) return false;
    return true;
}

function detectLanIp() {
    try {
        const interfaces = os.networkInterfaces();
        const candidates = [];
        for (const name of Object.keys(interfaces)) {
            if (/virtualbox|vmware|vethernet|wsl|hyper-?v|loopback/i.test(name)) continue;
            for (const iface of interfaces[name]) {
                if (iface.family === 'IPv4' && !iface.internal && isUsableLanIp(iface.address)) {
                    candidates.push(iface.address);
                }
            }
        }
        return candidates.find(ip => ip.startsWith('192.168.'))
            || candidates.find(ip => ip.startsWith('10.'))
            || candidates[0]
            || null;
    } catch {}
    return null;
}
