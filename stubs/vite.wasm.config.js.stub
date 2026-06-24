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

// Installed @tauri-apps packages = the declared plugins (NativeBladeConfig::plugins).
const installedScoped = new Set(Object.keys(scopedAliases));

// Dev-only: the runtime dynamically imports every optional @tauri-apps plugin
// inside a try/catch, but Vite's dev server fails the transform when an
// undeclared (uninstalled) plugin can't be resolved. Resolve those to a module
// that throws on import so the try/catch no-ops, exactly as it would at runtime.
// The build handles this through rolldownOptions.external instead.
function absentTauriPlugins(installed) {
    const VIRT = '\0nb-absent-tauri:';
    return {
        name: 'nativeblade-absent-tauri-plugins',
        apply: 'serve',
        enforce: 'pre',
        resolveId(id) {
            const pkg = id.match(/^(@tauri-apps\/plugin-[^/]+)/)?.[1];
            return pkg && !installed.has(pkg) ? VIRT + pkg : null;
        },
        load(id) {
            if (id.startsWith(VIRT)) {
                const pkg = id.slice(VIRT.length);
                return `throw new Error(${JSON.stringify('NativeBlade: @tauri-apps plugin not installed: ' + pkg)});`;
            }
            return null;
        },
    };
}

// php-wasm imports its .wasm/.dat assets with a bare `import` and instantiates
// them at runtime. Vite 8 / rolldown otherwise treats the .wasm as a module and
// tries to resolve its host imports (env, wasi_snapshot_preview1), which fails.
// Redirect those bare asset imports to `?url` so they resolve as plain assets.
function phpWasmAssets() {
    const ASSET_RE = /\.(wasm|dat)$/;
    return {
        name: 'nativeblade-php-wasm-assets',
        enforce: 'pre',
        async resolveId(source, importer, options) {
            if (importer && ASSET_RE.test(source) && !source.includes('?')) {
                return this.resolve(source + '?url', importer, { ...options, skipSelf: true });
            }
            return null;
        },
    };
}

export default defineConfig({
    plugins: [phpHmrPlugin(projectRoot), absentTauriPlugins(installedScoped), phpWasmAssets()],
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
        allowedHosts: true,
        fs: {
            allow: [nativebladeBase, projectRoot],
        },
        watch: {
            ignored: [
                '**/public/laravel-bundle.json',
                '**/public/laravel-bundle.json.gz',
                '**/public/laravel-bundle.json.gz.tmp',
                '**/public/laravel-bundle.json.tmp',
                '**/public/bundle-meta.json',
                '**/public/bundle-meta.json.tmp',
                '**/public/nativeblade-config.json',
            ],
        },
    },
    optimizeDeps: {
        exclude: ['@php-wasm/web-8-3', '@php-wasm/web-8-4', '@php-wasm/web-8-5'],
    },
    // php-wasm imports non-JS assets (.wasm/.so/.dat/.la) via `import` so the
    // bundler tracks them statically; treat them as assets (URLs) instead of
    // trying to bundle the wasm (which fails resolving its WASI imports).
    assetsInclude: [/\.dat$/, /\.wasm$/, /\.so$/, /\.la$/],
    build: {
        outDir: path.resolve(__dirname, 'dist-wasm'),
        emptyOutDir: true,
        chunkSizeWarningLimit: 5000,
        rolldownOptions: {
            // Externalize @tauri-apps plugins that aren't declared/installed —
            // bridge.js try/catches each import, so absent ones no-op at runtime.
            external(id) {
                const pkg = id.match(/^(@tauri-apps\/[^/]+)/)?.[1];
                return pkg ? !installedScoped.has(pkg) : false;
            },
            onwarn(warning, warn) {
                if (warning.code === 'EVAL') return;
                if (warning.plugin === 'rolldown:vite-resolve') return;
                if (warning.message?.includes('externalized for browser compatibility')) return;
                warn(warning);
            },
        },
    },
});
