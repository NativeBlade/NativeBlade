import { readFileSync } from 'fs';
import path from 'path';

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
            const dirs = ['app', 'resources/views', 'routes', 'config', 'lang'];
            for (const dir of dirs) {
                server.watcher.add(path.join(projectRoot, dir));
            }

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

            server.watcher.on('change', (filePath) => {
                if (!/\.(php|blade\.php|json)$/.test(filePath)) return;

                try {
                    const content = readFileSync(filePath, 'utf-8');
                    const wasmPath = toWasmPath(filePath);
                    version++;

                    changes.push({ wasmPath, content, version });
                    if (changes.length > 100) changes.splice(0, changes.length - 100);

                    server.ws.send('php-file-changed', { wasmPath, content, version });
                } catch {}
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
        const os = require('os');
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
