import { watch } from 'chokidar';
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
            if (rel.startsWith(dir + '/') || rel === dir) {
                return wasmDir + rel.substring(dir.length);
            }
        }
        return '/app/' + rel;
    }

    return {
        name: 'nativeblade-php-hmr',

        configureServer(server) {
            const watcher = watch([
                path.join(projectRoot, 'app/**/*.php'),
                path.join(projectRoot, 'resources/views/**/*.blade.php'),
                path.join(projectRoot, 'routes/**/*.php'),
                path.join(projectRoot, 'config/**/*.php'),
                path.join(projectRoot, 'lang/**/*.{php,json}'),
            ], {
                ignoreInitial: true,
                awaitWriteFinish: { stabilityThreshold: 100, pollInterval: 50 },
            });

            watcher.on('change', (filePath) => {
                try {
                    const content = readFileSync(filePath, 'utf-8');
                    const wasmPath = toWasmPath(filePath);
                    version++;

                    changes.push({ wasmPath, content, version });

                    // Keep only last 100 changes
                    if (changes.length > 100) changes.splice(0, changes.length - 100);

                    // Send via Vite HMR
                    server.ws.send('php-file-changed', { wasmPath, content });
                } catch {}
            });

            // Serve polling endpoint
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
