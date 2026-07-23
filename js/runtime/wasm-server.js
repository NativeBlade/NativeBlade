import { initRuntime, getInstance } from './php-runtime.js';
import { prepareDirs, loadBundle, patchEnv, runMigrations } from './filesystem.js';
import { handleRequest } from './request-handler.js';
import { loadTranslations, t } from './i18n.js';

export { getInstance, t, loadTranslations };

export async function boot(onProgress) {
    await loadTranslations();

    onProgress?.(t('splash.loading'));
    await initRuntime();

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

export async function request(path, options, onBridge) {
    return handleRequest(path, options, onBridge);
}
