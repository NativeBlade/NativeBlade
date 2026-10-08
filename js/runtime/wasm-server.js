import { initRuntime, getInstance } from './php-runtime.js';
import { prepareDirs, loadBundle, patchEnv, runMigrations } from './filesystem.js';
import { handleRequest } from './request-handler.js';
import { installNativeBridge } from './native-bridge.js';
import { loadTranslations, t } from './i18n.js';

export { getInstance, t, loadTranslations };

export async function boot(onProgress) {
    await loadTranslations();

    onProgress?.(t('splash.loading'));
    await initRuntime();
    // PHP's native calls (HTTP, database, filesystem) are answered here from
    // the first request on, migrations included.
    installNativeBridge(getInstance());

    onProgress?.(t('boot.filesystem'));
    prepareDirs();

    onProgress?.(t('boot.bundle'));
    await loadBundle((msg) => {
        const match = msg.match(/(\d+)\/(\d+)/);
        if (match) onProgress?.(t('boot.bundle_progress', { loaded: match[1], total: match[2] }));
    });

    onProgress?.(t('boot.config'));
    patchEnv();

    onProgress?.(t('boot.migrations'));
    await runMigrations();

    onProgress?.(t('boot.ready'));
    return getInstance();
}

export async function request(path, options) {
    return handleRequest(path, options);
}
