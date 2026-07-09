<?php

namespace NativeBlade\Commands;

use Illuminate\Console\Command;
use NativeBlade\Config\PluginRegistry;
use NativeBlade\NativeBladeServiceProvider;
use NativeBlade\ShellConfig;

class BuildCommand extends Command
{
    protected $signature = 'nativeblade:build
        {platform : android, ios, windows, macos, or linux}
        {--targets= : Comma-separated Android architectures (aarch64,armv7,x86_64,i686). Default: all}
        {--host= : Build an installable preview/dev-client that loads live from this Vite dev host with HMR, instead of bundling static assets. Debug-only.}
        {--port=1420 : Vite dev server port used by --host preview builds}';
    protected $description = 'Build the app for the specified platform';

    public function handle(): int
    {
        $platform = $this->argument('platform');

        // OS-specific desktop targets — Tauri can't cross-compile, so each must run
        // on its own OS. `desktop` stays as an alias that builds for the host OS.
        $desktopOs = ['windows' => 'Windows', 'macos' => 'Darwin', 'linux' => 'Linux'];

        if (!in_array($platform, ['android', 'ios', 'desktop', 'windows', 'macos', 'linux'])) {
            $this->error("  Invalid platform: {$platform}. Use: android, ios, windows, macos, or linux");
            return self::FAILURE;
        }

        if (isset($desktopOs[$platform]) && PHP_OS_FAMILY !== $desktopOs[$platform]) {
            $this->error("  {$platform} builds must run on {$desktopOs[$platform]} — this host is " . PHP_OS_FAMILY . ". Desktop apps can't be cross-compiled.");
            return self::FAILURE;
        }

        app()->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

        $version = $this->getVersion($platform);
        if (!$version) return self::FAILURE;

        if ($this->option('host')) {
            return $this->buildPreview($platform, $version);
        }

        $this->info('');
        $this->info("  Building {$platform} v{$version['version']} (build {$version['buildNumber']})");
        $this->info('');

        $this->line('  Applying config...');
        $this->call('nativeblade:config');

        $this->line('');
        $this->line('  Building frontend...');
        if (!$this->runProcess($this->npmCommand('run build'))) {
            $this->error('  Frontend build failed.');
            return self::FAILURE;
        }
        $this->line("  <fg=green>✓</> Frontend built (public/build/manifest.json)");

        $this->line('');
        $this->line('  Bundling Laravel app...');
        $bundleScript = NativeBladeServiceProvider::packagePath('js/scripts/bundle-laravel.js');
        if (!$this->runProcess('node ' . escapeshellarg($bundleScript) . ' ' . escapeshellarg(base_path()))) {
            $this->error('  Laravel bundle failed.');
            return self::FAILURE;
        }
        $this->line("  <fg=green>✓</> Laravel bundle (public/laravel-bundle.json.gz)");

        $this->line('');
        $this->line('  Building WASM frontend...');
        if (!$this->runProcess($this->npxCommand('vite build --config vite.wasm.config.js'))) {
            $this->error('  WASM frontend build failed.');
            return self::FAILURE;
        }
        $this->line("  <fg=green>✓</> WASM frontend built (dist-wasm/)");

        $this->line('');
        $this->line("  Building {$platform}...");

        $success = match ($platform) {
            'android' => $this->buildAndroid($version),
            'ios' => $this->buildIos($version),
            default => $this->buildDesktop($version, $platform), // desktop, windows, macos, linux
        };

        if (!$success) {
            $this->error("  Build failed for {$platform}.");
            return self::FAILURE;
        }

        $this->info('');
        $this->info("  Build complete! Artifacts in build/{$platform}/");
        $this->info('');

        return self::SUCCESS;
    }

    /**
     * Build an installable preview / dev-client artifact that loads the
     * frontend live from a running Vite dev server (HMR + live Laravel bundle)
     * instead of bundling static assets. Same wiring as `nativeblade:dev`
     * (devUrl over http, cleartext enabled by Tauri's debug profile), but as a
     * standalone build so it installs once and reconnects without an adb tether.
     *
     * Debug-only on purpose: it loads remote code into a WebView with native
     * plugin access, so it must never ship to stores.
     */
    private function buildPreview(string $platform, array $version): int
    {
        $host = $this->option('host');
        $port = $this->option('port') ?: '1420';

        $url = str_contains($host, '://') ? rtrim($host, '/') : "http://{$host}:{$port}";

        // Preview is a debug build for the host OS — every desktop target is equivalent here.
        $key = in_array($platform, ['desktop', 'windows', 'macos', 'linux'], true) ? 'desktop' : $platform;

        $this->info('');
        $this->info("  Preview build ({$platform}) → {$url}");
        $this->line('  <fg=yellow>→</> Loads live from your Vite dev server with HMR. Debug artifact, not for stores.');
        $this->info('');

        $this->line('  Applying config...');
        $this->call('nativeblade:config');

        $override = $this->configArg(json_encode([
            'build' => ['frontendDist' => $url, 'devUrl' => $url],
        ]));

        $androidTargets = '';
        if ($platform === 'android') {
            foreach ($this->resolveAndroidTargets() as $target) {
                $androidTargets .= "--target {$target} ";
            }
        }

        $base = match ($key) {
            'android' => 'tauri android build --debug ' . trim($androidTargets) . ' --config ' . $override,
            'ios' => 'tauri ios build --debug --config ' . $override,
            'desktop' => 'tauri build --debug --config ' . $override,
        };

        $this->line('');
        $this->line("  Building {$platform} preview...");

        // A debug build keeps full DWARF debug info, which dominates the native
        // .so size (a preview APK can hit hundreds of MB). Nobody debugs the
        // Rust layer in a preview, so strip symbols from the dev profile to cut
        // the bulk while keeping the debug Android build (cleartext + signing).
        putenv('CARGO_PROFILE_DEV_STRIP=symbols');

        if (!$this->runProcess($this->npxCommand($base . ' ' . $this->cargoFeaturesArg()))) {
            $this->error('  Preview build failed.');
            return self::FAILURE;
        }

        $buildDir = base_path("build/{$platform}");
        if (!is_dir($buildDir)) mkdir($buildDir, 0755, true);

        $searchDir = match ($key) {
            'android' => 'src-tauri/gen/android',
            'ios' => 'src-tauri/gen/apple',
            'desktop' => 'src-tauri/target/debug/bundle',
        };
        $exts = match ($key) {
            'android' => ['apk', 'aab'],
            'ios' => ['ipa'],
            'desktop' => ['msi', 'exe', 'dmg', 'app', 'AppImage', 'deb', 'rpm'],
        };
        $this->searchAndCopyArtifacts($searchDir, $buildDir, $version['version'] . '-preview', $exts);

        $this->info('');
        $this->info('  Preview build ready.');
        $this->line("  <fg=green>→</> Install it, then run `php artisan nativeblade:dev --platform={$platform} --host={$host}` so the app can reach the dev server.");
        $this->info('');

        return self::SUCCESS;
    }

    private function configArg(string $json): string
    {
        return PHP_OS_FAMILY === 'Windows'
            ? '"' . str_replace('"', '\\"', $json) . '"'
            : escapeshellarg($json);
    }

    private function buildAndroid(array $version): bool
    {
        $buildDir = base_path('build/android');
        if (!is_dir($buildDir)) mkdir($buildDir, 0755, true);

        $targets = $this->resolveAndroidTargets();
        $targetArgs = '';
        foreach ($targets as $target) {
            $targetArgs .= "--target {$target} ";
        }

        if (!$this->runProcess($this->npxCommand('tauri android build ' . trim($targetArgs) . ' ' . $this->cargoFeaturesArg()))) {
            return false;
        }

        $v = $version['version'];
        $apkSource = base_path('src-tauri/gen/android/app/build/outputs/apk/universal/release/app-universal-release-unsigned.apk');
        $aabSource = base_path('src-tauri/gen/android/app/build/outputs/bundle/universalRelease/app-universal-release.aab');

        if (file_exists($apkSource)) {
            copy($apkSource, "{$buildDir}/{$v}.apk");
            $this->line("  <fg=green>✓</> build/android/{$v}.apk");
        }

        if (file_exists($aabSource)) {
            copy($aabSource, "{$buildDir}/{$v}.aab");
            $this->line("  <fg=green>✓</> build/android/{$v}.aab");
        }

        if (!file_exists($apkSource) && !file_exists($aabSource)) {
            $this->searchAndCopyArtifacts('src-tauri/gen/android', $buildDir, $v, ['apk', 'aab']);
        }

        return true;
    }

    private function buildIos(array $version): bool
    {
        $buildDir = base_path('build/ios');
        if (!is_dir($buildDir)) mkdir($buildDir, 0755, true);

        if (!$this->runProcess($this->npxCommand('tauri ios build ' . $this->cargoFeaturesArg()))) {
            return false;
        }

        $this->searchAndCopyArtifacts('src-tauri/gen/apple', $buildDir, $version['version'], ['ipa']);

        return true;
    }

    private function buildDesktop(array $version, string $platform = 'desktop'): bool
    {
        $buildDir = base_path("build/{$platform}");
        if (!is_dir($buildDir)) mkdir($buildDir, 0755, true);

        // Ask Tauri only for the installers that make sense on this OS (overrides the
        // config's bundle targets). `desktop` leaves it to whatever the host produces.
        $bundles = match ($platform) {
            'windows' => '--bundles nsis,msi',
            'macos' => '--bundles app,dmg',
            'linux' => '--bundles deb,rpm,appimage',
            default => '',
        };

        if (!$this->runProcess($this->npxCommand(trim('tauri build ' . $bundles . ' ' . $this->cargoFeaturesArg())))) {
            return false;
        }

        $this->searchAndCopyArtifacts('src-tauri/target/release/bundle', $buildDir, $version['version'], [
            'msi', 'exe', 'dmg', 'app', 'AppImage', 'deb', 'rpm',
        ]);

        return true;
    }

    /**
     * @return string[]
     */
    private function resolveAndroidTargets(): array
    {
        $opt = $this->option('targets');
        if (empty($opt)) {
            return ['aarch64', 'armv7', 'x86_64', 'i686'];
        }

        $valid = ['aarch64', 'armv7', 'x86_64', 'i686'];
        $requested = array_filter(array_map('trim', explode(',', $opt)));
        $resolved = [];
        foreach ($requested as $t) {
            if (in_array($t, $valid, true)) $resolved[] = $t;
        }
        return $resolved ?: ['aarch64'];
    }

    private function cargoFeaturesArg(): string
    {
        $plugins = PluginRegistry::resolve(ShellConfig::getDeclaredPlugins());
        $features = [];
        foreach ($plugins as $plugin) {
            $d = PluginRegistry::descriptor($plugin);
            if (isset($d['feature'])) $features[] = $d['feature'];
        }
        foreach (ShellConfig::getCustomPlugins() as $plugin) {
            $features[] = $plugin->feature;
        }
        if (empty($features)) return '';
        sort($features);
        return '--features ' . escapeshellarg(implode(',', $features));
    }

    private function searchAndCopyArtifacts(string $searchDir, string $destDir, string $version, array $extensions): void
    {
        $basePath = base_path($searchDir);
        if (!is_dir($basePath)) return;

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($basePath, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (in_array($file->getExtension(), $extensions) && $file->isFile()) {
                $dest = "{$destDir}/{$version}.{$file->getExtension()}";
                copy($file->getPathname(), $dest);
                $relative = str_replace(base_path() . DIRECTORY_SEPARATOR, '', $dest);
                $this->line("  <fg=green>✓</> {$relative}");
            }
        }
    }

    private function getVersion(string $platform): ?array
    {
        // Every desktop target shares the same versioning.
        $key = in_array($platform, ['desktop', 'windows', 'macos', 'linux']) ? 'desktop' : $platform;

        try {
            return ShellConfig::getVersion($key);
        } catch (\RuntimeException $e) {
            $this->error("  {$e->getMessage()}");
            return null;
        }
    }

    private function runProcess(string $command): bool
    {
        $this->line("  > {$command}");
        $this->line('');

        passthru($command, $code);

        return $code === 0;
    }

    private function npmCommand(string $args): string
    {
        return PHP_OS_FAMILY === 'Windows' ? "npm.cmd {$args}" : "npm {$args}";
    }

    private function npxCommand(string $args): string
    {
        return PHP_OS_FAMILY === 'Windows' ? "npx.cmd {$args}" : "npx {$args}";
    }
}
