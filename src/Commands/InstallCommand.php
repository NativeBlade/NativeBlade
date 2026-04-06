<?php

namespace NativeBlade\Commands;

use Illuminate\Console\Command;
use NativeBlade\NativeBladeServiceProvider;

class InstallCommand extends Command
{
    protected $signature = 'nativeblade:install';
    protected $description = 'Install NativeBlade into your Laravel project';

    private string $appName;
    private string $identifier;

    public function handle(): int
    {
        $this->info('');
        $this->info('  ⚡ NativeBlade Installer');
        $this->info('');

        $this->appName = $this->ask('App name', config('app.name', 'MyApp'));
        $this->identifier = $this->ask('Identifier (com.example.app)', $this->guessIdentifier());

        $this->scaffoldTauri();
        $this->publishLayouts();
        $this->publishViteConfig();
        $this->publishDefaultConfigs();
        $this->updateAppServiceProvider();
        $this->createDirectories();

        $this->info('');
        $this->info('  ✓ NativeBlade installed successfully!');
        $this->info('');
        $this->info('  Next steps:');
        $this->info('    1. Review config in app/Providers/AppServiceProvider.php');
        $this->info('    2. Run: php artisan nativeblade:dev');
        $this->info('');

        return 0;
    }

    private function scaffoldTauri(): void
    {
        $tauriDir = base_path('src-tauri');
        $srcDir = $tauriDir . '/src';

        if (!is_dir($srcDir)) {
            mkdir($srcDir, 0755, true);
        }
        if (!is_dir($tauriDir . '/icons')) {
            mkdir($tauriDir . '/icons', 0755, true);
        }

        $this->publishStub('main.rs.stub', $srcDir . '/main.rs');
        $this->publishStub('build.rs.stub', $tauriDir . '/build.rs');
        $this->publishStub('Cargo.toml.stub', $tauriDir . '/Cargo.toml', [
            '{{NATIVEBLADE_RUST_PATH}}' => $this->rustCratePath(),
        ]);
        $this->publishStub('tauri.conf.json.stub', $tauriDir . '/tauri.conf.json');

        $this->line("  <fg=green>✓</> src-tauri/ scaffolded");
    }

    private function publishLayouts(): void
    {
        $layoutDir = resource_path('views/components/layouts');

        if (!is_dir($layoutDir)) {
            mkdir($layoutDir, 0755, true);
        }

        $this->publishStub('app.blade.php.stub', $layoutDir . '/app.blade.php');
        $this->publishStub('auth.blade.php.stub', $layoutDir . '/auth.blade.php');

        $this->line("  <fg=green>✓</> Blade layouts published");
    }

    private function publishViteConfig(): void
    {
        $dest = base_path('vite.wasm.config.js');

        if (!file_exists($dest)) {
            $this->publishStub('vite.wasm.config.js.stub', $dest);
            $this->line("  <fg=green>✓</> vite.wasm.config.js created");
        } else {
            $this->line("  <fg=yellow>→</> vite.wasm.config.js already exists, skipped");
        }
    }

    private function publishDefaultConfigs(): void
    {
        $tauriDir = base_path('src-tauri');

        file_put_contents($tauriDir . '/menu.json', '[]');
        file_put_contents($tauriDir . '/tray.json', json_encode([
            'enabled' => false,
            'tooltip' => $this->appName,
            'hideOnClose' => false,
            'customIcon' => false,
            'menu' => [],
        ], JSON_PRETTY_PRINT));

        $this->line("  <fg=green>✓</> Default configs created");
    }

    private function updateAppServiceProvider(): void
    {
        $providerPath = app_path('Providers/AppServiceProvider.php');
        if (!file_exists($providerPath)) return;

        $content = file_get_contents($providerPath);

        if (str_contains($content, 'NativeBlade::')) {
            $this->line("  <fg=yellow>→</> AppServiceProvider already has NativeBlade config, skipped");
            return;
        }

        $stub = file_get_contents($this->stubPath('AppServiceProvider.stub'));
        $stub = $this->replacePlaceholders($stub);

        $useStatement = "use NativeBlade\\Facades\\NativeBlade;\n";

        if (!str_contains($content, 'NativeBlade\\Facades\\NativeBlade')) {
            $content = preg_replace(
                '/(namespace [^;]+;\n)/',
                "$1\n{$useStatement}",
                $content
            );
        }

        $content = preg_replace(
            '/(public function boot\(\).*?\{)\s*(\n\s*(?:\/\/.*)?)/s',
            "$1\n{$stub}\n",
            $content
        );

        file_put_contents($providerPath, $content);
        $this->line("  <fg=green>✓</> AppServiceProvider updated");
    }

    private function createDirectories(): void
    {
        $dirs = [
            base_path('nativeblade-components'),
            base_path('lang'),
        ];

        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }

        $this->line("  <fg=green>✓</> Project directories created");
    }

    private function publishStub(string $stub, string $dest, array $extra = []): void
    {
        $content = file_get_contents($this->stubPath($stub));
        $content = $this->replacePlaceholders($content, $extra);
        file_put_contents($dest, $content);
    }

    private function replacePlaceholders(string $content, array $extra = []): string
    {
        $replacements = array_merge([
            '{{APP_NAME}}' => $this->appName,
            '{{IDENTIFIER}}' => $this->identifier,
        ], $extra);

        return str_replace(array_keys($replacements), array_values($replacements), $content);
    }

    private function stubPath(string $name): string
    {
        return NativeBladeServiceProvider::packagePath("stubs/{$name}");
    }

    private function rustCratePath(): string
    {
        $vendorPath = NativeBladeServiceProvider::packagePath('rust');
        return str_replace('\\', '/', realpath($vendorPath) ?: $vendorPath);
    }

    private function guessIdentifier(): string
    {
        $name = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $this->appName ?? config('app.name', 'myapp')));
        return "com.{$name}.app";
    }
}
