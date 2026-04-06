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

        $this->installComposerDependencies();
        $this->scaffoldTauri();
        $this->publishLayouts();
        $this->publishWasmApp();
        $this->publishViteConfig();
        $this->publishDefaultConfigs();
        $this->installNpmDependencies();
        $this->publishDemo();
        $this->updateAppServiceProvider();
        $this->updateBootstrap();
        $this->createDirectories();

        $this->info('');
        $this->info('  ✓ NativeBlade installed successfully!');
        $this->info('');
        $this->info('  Next steps:');
        $this->info('    1. Review config in app/Providers/AppServiceProvider.php');
        $this->info('    2. npm run build');
        $this->info('    3. php artisan nativeblade:dev');
        $this->info('');

        return 0;
    }

    private function installComposerDependencies(): void
    {
        $this->line('  Installing Livewire...');
        exec('cd ' . escapeshellarg(base_path()) . ' && composer require livewire/livewire 2>&1', $output, $code);

        if ($code === 0) {
            $this->line("  <fg=green>✓</> Livewire installed");
        } else {
            $this->line("  <fg=yellow>→</> Run manually: composer require livewire/livewire");
        }
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

        $iconsSource = NativeBladeServiceProvider::packagePath('stubs/icons');
        if (is_dir($iconsSource)) {
            foreach (scandir($iconsSource) as $file) {
                if ($file === '.' || $file === '..') continue;
                copy("{$iconsSource}/{$file}", "{$tauriDir}/icons/{$file}");
            }
        }

        $capsDir = $tauriDir . '/capabilities';
        if (!is_dir($capsDir)) mkdir($capsDir, 0755, true);
        $capsSource = NativeBladeServiceProvider::packagePath('stubs/capabilities');
        if (is_dir($capsSource)) {
            foreach (scandir($capsSource) as $file) {
                if ($file === '.' || $file === '..') continue;
                copy("{$capsSource}/{$file}", "{$capsDir}/{$file}");
            }
        }

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

    private function publishWasmApp(): void
    {
        $dir = resource_path('js');
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $this->publishStub('wasm-app/index.html', $dir . '/index.html');
        $this->publishStub('wasm-app/nativeblade.js', $dir . '/nativeblade.js');

        $this->line("  <fg=green>✓</> resources/js/ published (splash + entry point)");
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

    private function installNpmDependencies(): void
    {
        $deps = [
            '@php-wasm/web@^3.1.15',
            '@php-wasm/universal@^3.1.15',
            '@tauri-apps/cli@^2',
            '@tauri-apps/api@^2',
            '@tauri-apps/plugin-dialog@^2',
            '@tauri-apps/plugin-notification@^2',
            '@tauri-apps/plugin-process@^2',
            '@tauri-apps/plugin-store@^2',
        ];

        $this->line('  Installing npm dependencies...');
        $cmd = 'cd ' . escapeshellarg(base_path()) . ' && npm install ' . implode(' ', $deps) . ' 2>&1';
        exec($cmd, $output, $code);

        if ($code === 0) {
            $this->line("  <fg=green>✓</> npm dependencies installed");
        } else {
            $this->line("  <fg=yellow>→</> npm install failed, run manually:");
            $this->line("     npm install " . implode(' ', $deps));
        }
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

        $this->publishStub('AppServiceProvider.php.stub', $providerPath);
        $this->line("  <fg=green>✓</> AppServiceProvider replaced");
    }

    private function updateBootstrap(): void
    {
        $path = base_path('bootstrap/app.php');
        if (!file_exists($path)) return;

        $content = file_get_contents($path);

        if (str_contains($content, 'validateCsrfTokens')) {
            $this->line("  <fg=yellow>→</> bootstrap/app.php already configured, skipped");
            return;
        }

        $content = str_replace(
            '->withMiddleware(function (Middleware $middleware): void {',
            "->withMiddleware(function (Middleware \$middleware): void {\n        \$middleware->validateCsrfTokens(except: ['*']);\n        \$middleware->alias(['nb.auth' => \\App\\Http\\Middleware\\NativeBladeAuth::class]);",
            $content
        );

        file_put_contents($path, $content);
        $this->line("  <fg=green>✓</> bootstrap/app.php updated (CSRF disabled)");
    }

    private function publishDemo(): void
    {
        $livewireDir = app_path('Livewire');
        $viewsDir = resource_path('views/livewire');

        if (!is_dir($livewireDir)) mkdir($livewireDir, 0755, true);
        if (!is_dir($viewsDir)) mkdir($viewsDir, 0755, true);

        $middlewareDir = app_path('Http/Middleware');
        if (!is_dir($middlewareDir)) mkdir($middlewareDir, 0755, true);

        $this->publishStub('demo/NativeBladeAuth.php.stub', $middlewareDir . '/NativeBladeAuth.php');
        $this->publishStub('demo/Login.php.stub', $livewireDir . '/Login.php');
        $this->publishStub('demo/Home.php.stub', $livewireDir . '/Home.php');
        $this->publishStub('demo/login.blade.php.stub', $viewsDir . '/login.blade.php');
        $this->publishStub('demo/home.blade.php.stub', $viewsDir . '/home.blade.php');
        $this->publishStub('demo/routes.php.stub', base_path('routes/web.php'));

        $logo = NativeBladeServiceProvider::packagePath('logo_nb.png');
        if (file_exists($logo)) {
            copy($logo, public_path('logo_nb.png'));
        }

        @unlink(resource_path('views/welcome.blade.php'));

        $this->line("  <fg=green>✓</> Demo app published (Login + Home)");
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

        $jsLang = resource_path('js/lang');
        if (!is_dir($jsLang)) mkdir($jsLang, 0755, true);

        $langSource = NativeBladeServiceProvider::packagePath('stubs/lang');
        if (is_dir($langSource)) {
            foreach (scandir($langSource) as $file) {
                if ($file === '.' || $file === '..') continue;
                copy("{$langSource}/{$file}", "{$jsLang}/{$file}");
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
        $crateName = strtolower(preg_replace('/[^a-zA-Z0-9]/', '-', $this->appName));
        $crateName = trim(preg_replace('/-+/', '-', $crateName), '-');

        $replacements = array_merge([
            '{{APP_NAME}}' => $this->appName,
            '{{IDENTIFIER}}' => $this->identifier,
            '{{CRATE_NAME}}' => $crateName,
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
