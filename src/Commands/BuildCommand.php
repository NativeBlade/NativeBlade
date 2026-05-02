<?php

namespace NativeBlade\Commands;

use Illuminate\Console\Command;
use NativeBlade\Config\PluginRegistry;
use NativeBlade\ShellConfig;

class BuildCommand extends Command
{
    protected $signature = 'nativeblade:build
        {platform : android, ios, or desktop}
        {--targets= : Comma-separated Android architectures (aarch64,armv7,x86_64,i686). Default: all}';
    protected $description = 'Build the app for the specified platform';

    public function handle(): int
    {
        $platform = $this->argument('platform');

        if (!in_array($platform, ['android', 'ios', 'desktop'])) {
            $this->error("  Invalid platform: {$platform}. Use: android, ios, or desktop");
            return self::FAILURE;
        }

        app()->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

        $version = $this->getVersion($platform);
        if (!$version) return self::FAILURE;

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
        $this->line("  <fg=green>✓</> Frontend built");

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
            'desktop' => $this->buildDesktop($version),
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

    private function buildDesktop(array $version): bool
    {
        $buildDir = base_path('build/desktop');
        if (!is_dir($buildDir)) mkdir($buildDir, 0755, true);

        if (!$this->runProcess($this->npxCommand('tauri build ' . $this->cargoFeaturesArg()))) {
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
        try {
            return ShellConfig::getVersion($platform === 'desktop' ? 'desktop' : $platform);
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
