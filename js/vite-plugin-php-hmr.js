import { readFileSync } from 'fs';
import path from 'path';

export default function phpHmrPlugin(projectRoot) {
    const changes = [];
    let version = 0;

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

        configureServer(server) {
            const dirs = ['app', 'resources/views', 'routes', 'config', 'lang'];
            for (const dir of dirs) {
                server.watcher.add(path.join(projectRoot, dir));
            }

            server.watcher.on('change', (filePath) => {
                if (!/\.(php|blade\.php|json)$/.test(filePath)) return;

                try {
                    const content = readFileSync(filePath, 'utf-8');
                    const wasmPath = toWasmPath(filePath);
                    version++;

                    changes.push({ wasmPath, content, version });
                    if (changes.length > 100) changes.splice(0, changes.length - 100);

                    server.ws.send('php-file-changed', { wasmPath, content });
                } catch {}
            });

            server.middlewares.use((req, res, next) => {
                if (!req.url.startsWith('/__php_changes')) return next();

                const url = new URL(req.url, 'http://localhost');
                const since = parseInt(url.searchParams.get('since') || '0', 10);
                const pending = changes.filter(c => c.version > since);

                res.setHeader('Content-Type', 'application/json');
                res.setHeader('Access-Control-Allow-Origin', '*');
                res.end(JSON.stringify({ version, changes: pending }));
            });
        },
    };
}
