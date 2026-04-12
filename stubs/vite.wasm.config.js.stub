import { defineConfig } from 'vite';
import path from 'path';
import { readdirSync } from 'fs';
import phpHmrPlugin from './vendor/nativeblade/nativeblade/js/vite-plugin-php-hmr.js';

const projectRoot = path.resolve(__dirname);
const nativebladeBase = path.resolve(__dirname, 'vendor/nativeblade/nativeblade/js');
const nodeModules = path.join(projectRoot, 'node_modules');

const scopedAliases = {};
for (const scope of ['@php-wasm', '@tauri-apps']) {
    const scopePath = path.join(nodeModules, scope);
    try {
        for (const pkg of readdirSync(scopePath)) {
            scopedAliases[`${scope}/${pkg}`] = path.join(scopePath, pkg);
        }
    } catch {}
}

export default defineConfig({
    plugins: [phpHmrPlugin(projectRoot)],
    root: path.resolve(__dirname, 'resources/js'),
    publicDir: path.resolve(__dirname, 'public'),
    resolve: {
        alias: {
            '@nativeblade': nativebladeBase,
            '@nativeblade-php-loader': path.resolve(__dirname, 'resources/js/php-loader.js'),
            '@components': path.resolve(__dirname, 'nativeblade-components'),
            ...scopedAliases,
        },
    },
    server: {
        port: 1420,
        strictPort: true,
        host: '0.0.0.0',
        fs: {
            allow: [nativebladeBase, projectRoot],
        },
    },
    optimizeDeps: {
        exclude: ['@php-wasm/web-8-3', '@php-wasm/web-8-4', '@php-wasm/web-8-5'],
    },
    assetsInclude: ['**/*.so', '**/*.wasm'],
    build: {
        outDir: path.resolve(__dirname, 'dist-wasm'),
        emptyOutDir: true,
    },
});
