import { PHP } from '@php-wasm/universal';
import { getPHPLoaderModule } from '@php-wasm/web-8-2';
import { loadPHPRuntime } from '@php-wasm/universal';

let php = null;

export async function boot(onProgress) {
    if (php) return php;

    if (onProgress) onProgress('Loading PHP WASM...');
    const loaderModule = await getPHPLoaderModule();

    if (onProgress) onProgress('Starting PHP runtime...');
    const runtimeId = await loadPHPRuntime(loaderModule);

    php = new PHP(runtimeId);

    php.mkdirTree('/app');
    php.mkdirTree('/app/public');
    php.chdir('/app');

    if (onProgress) onProgress('PHP ready!');
    return php;
}

export async function run(code) {
    const instance = await boot();
    const result = await instance.run({ code });
    return result.text;
}

export function getInstance() {
    return php;
}
