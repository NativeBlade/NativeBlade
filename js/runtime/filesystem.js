import { getInstance } from './php-runtime.js';

export function detectPlatform() {
    const ua = navigator.userAgent;

    if (/Android/i.test(ua)) return 'android';
    if (/iPhone|iPad|iPod/i.test(ua)) return 'ios';

    if (/Macintosh|Mac OS X/i.test(ua)) return 'macos';
    if (/Windows/i.test(ua)) return 'windows';
    if (/Linux/i.test(ua)) return 'linux';

    return 'web';
}

export function prepareDirs() {
    const php = getInstance();
    ['/app/public', '/app/database', '/app/storage/framework/views',
     '/app/storage/framework/cache/data', '/app/storage/framework/sessions',
     '/app/storage/logs', '/tmp'
    ].forEach(d => php.mkdirTree(d));
}

export async function loadBundle(onProgress) {
    const php = getInstance();
    const response = await fetch('./laravel-bundle.json');
    if (!response.ok) throw new Error(`Bundle fetch failed: ${response.status}`);

    const bundle = JSON.parse(await response.text());
    const paths = Object.keys(bundle);
    let loaded = 0;

    for (const path of paths) {
        const fullPath = '/app' + path;
        try {
            php.mkdirTree(fullPath.substring(0, fullPath.lastIndexOf('/')));
            php.writeFile(fullPath, bundle[path]);
        } catch {}
        if (++loaded % 500 === 0) onProgress?.(`Loading files... ${loaded}/${paths.length}`);
    }
}

export function patchEnv() {
    const php = getInstance();
    let env = php.readFileAsText('/app/.env');
    env = env.replace(/APP_DEBUG=.*/, 'APP_DEBUG=true');
    env = env.replace(/DB_CONNECTION=.*/, 'DB_CONNECTION=sqlite');
    env = env.replace(/SESSION_DRIVER=.*/, 'SESSION_DRIVER=file');
    env = env.replace(/CACHE_STORE=.*/, 'CACHE_STORE=file');
    env = env.replace(/QUEUE_CONNECTION=.*/, 'QUEUE_CONNECTION=sync');
    php.writeFile('/app/.env', env);

    try { php.readFileAsText('/app/database/database.sqlite'); }
    catch { php.writeFile('/app/database/database.sqlite', ''); }
}

export async function runMigrations() {
    const php = getInstance();
    try {
        await php.run({
            code: `<?php
                chdir('/app');
                $_SERVER['APP_BASE_PATH'] = '/app';
                putenv('APP_BASE_PATH=/app');
                require '/app/vendor/autoload.php';
                $app = require '/app/bootstrap/app.php';
                $kernel = $app->make(Illuminate\\Contracts\\Console\\Kernel::class);
                $kernel->call('migrate', ['--force' => true]);
            `
        });
    } catch {}
}
