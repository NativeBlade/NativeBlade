import { PHP, loadPHPRuntime } from '@php-wasm/universal';
import { getPHPLoaderModule } from '@php-wasm/web-8-2';

let php = null;

export async function initRuntime() {
    if (php) return php;

    const loaderModule = await getPHPLoaderModule();
    const runtimeId = await loadPHPRuntime(loaderModule);
    php = new PHP(runtimeId);

    return php;
}

export function getInstance() {
    return php;
}
