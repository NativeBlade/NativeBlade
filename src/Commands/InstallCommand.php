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
    private string $template;

    public function handle(): int
    {
        $this->info('');
        $this->info('  ⚡ NativeBlade Installer');
        $this->info('');

        $this->appName = $this->ask('App name', config('app.name', 'MyApp'));
        $this->identifier = $this->ask('Identifier (com.example.app)', $this->guessIdentifier());
        $this->template = $this->choice(
            'Which starter template?',
            ['demo', 'blank'],
            'demo'
        );

        $this->installComposerDependencies();
        $this->scaffoldTauri();
        $this->publishLayouts();
        $this->publishWasmApp();
        $this->publishViteConfig();
        $this->publishDefaultConfigs();
        $this->installNpmDependencies();
        $this->publishTemplate();
        $this->updateAppServiceProvider();
        $this->updateBootstrap();
        $this->patchTailwindSources();
        $this->patchDatabaseConfig();
        $this->patchFilesystemsConfig();
        $this->createDirectories();
        $this->call('nativeblade:icon');
        $this->call('nativeblade:config');

        $this->info('');
        $this->info('  ✓ NativeBlade installed successfully!');
        $this->info('');
        $this->info('  Next steps:');
        $this->info('    1. Review generated config in app/Providers/AppServiceProvider.php');
        $this->info('    2. npm run build');
        $this->info('    3. Optional mobile: php artisan nativeblade:add android');
        $this->info('    4. php artisan nativeblade:dev');
        $this->info('');

        return self::SUCCESS;
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
        $this->publishStub('lib.rs.stub', $srcDir . '/lib.rs');
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

        $cssDir = resource_path('css');
        if (!is_dir($cssDir)) mkdir($cssDir, 0755, true);
        $animateSrc = NativeBladeServiceProvider::packagePath('stubs/animate.min.css');
        if (file_exists($animateSrc)) {
            copy($animateSrc, $cssDir . '/animate.min.css');
        }
        $nbAnimSrc = NativeBladeServiceProvider::packagePath('stubs/nb-animations.css');
        if (file_exists($nbAnimSrc)) {
            copy($nbAnimSrc, $cssDir . '/nb-animations.css');
        }

        $this->line("  <fg=green>✓</> Blade layouts published");
    }

    private function publishWasmApp(): void
    {
        $dir = resource_path('js');
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $this->publishStub('wasm-app/index.html', $dir . '/index.html');

        $this->call('nativeblade:php');
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
        $phpVersion = PHP_MAJOR_VERSION . '-' . PHP_MINOR_VERSION;
        $stubPath = $this->stubPath('package.json.stub');
        $targetPath = base_path('package.json');

        if (file_exists($stubPath)) {
            $stub = file_get_contents($stubPath);
            $stub = str_replace('{{PHP_VERSION}}', $phpVersion, $stub);

            if (file_exists($targetPath)) {
                $existing = json_decode(file_get_contents($targetPath), true) ?? [];
                $incoming = json_decode($stub, true) ?? [];

                $existing['scripts'] = array_merge($existing['scripts'] ?? [], $incoming['scripts'] ?? []);
                $existing['dependencies'] = array_merge($existing['dependencies'] ?? [], $incoming['dependencies'] ?? []);
                $existing['devDependencies'] = array_merge($existing['devDependencies'] ?? [], $incoming['devDependencies'] ?? []);

                file_put_contents($targetPath, json_encode($existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
            } else {
                file_put_contents($targetPath, $stub);
            }

            $this->line("  <fg=green>✓</> package.json updated");
        } else {
            $this->line("  <fg=red>✗</> package.json.stub not found at: {$stubPath}");
        }

        $this->line('  Installing npm dependencies...');
        $cmd = 'cd ' . escapeshellarg(base_path()) . ' && npm install 2>&1';
        exec($cmd, $output, $code);

        if ($code === 0) {
            $this->line("  <fg=green>✓</> npm dependencies installed");
        } else {
            $this->line("  <fg=yellow>→</> npm install failed, run manually: npm install");
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

    private function publishTemplate(): void
    {
        if ($this->template === 'blank') {
            $this->publishBlank();
        } else {
            $this->publishDemo();
        }
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
        $this->publishStub('demo/Trail.php.stub', $livewireDir . '/Trail.php');
        $this->publishStub('demo/Lesson.php.stub', $livewireDir . '/Lesson.php');
        $this->publishStub('demo/Rank.php.stub', $livewireDir . '/Rank.php');
        $this->publishStub('demo/Profile.php.stub', $livewireDir . '/Profile.php');
        $this->publishStub('demo/login.blade.php.stub', $viewsDir . '/login.blade.php');
        $this->publishStub('demo/trail.blade.php.stub', $viewsDir . '/trail.blade.php');
        $this->publishStub('demo/lesson.blade.php.stub', $viewsDir . '/lesson.blade.php');
        $this->publishStub('demo/rank.blade.php.stub', $viewsDir . '/rank.blade.php');
        $this->publishStub('demo/profile.blade.php.stub', $viewsDir . '/profile.blade.php');
        $this->publishStub('demo/routes.php.stub', base_path('routes/web.php'));

        $logo = NativeBladeServiceProvider::packagePath('logo_nb.png');
        if (file_exists($logo)) {
            copy($logo, public_path('logo_nb.png'));
        }

        @unlink(resource_path('views/welcome.blade.php'));

        $this->line("  <fg=green>✓</> Demo app published (Login, Trail, Lesson, Rank, Profile)");
    }

    private function publishBlank(): void
    {
        $livewireDir = app_path('Livewire');
        $viewsDir = resource_path('views/livewire');

        if (!is_dir($livewireDir)) mkdir($livewireDir, 0755, true);
        if (!is_dir($viewsDir)) mkdir($viewsDir, 0755, true);

        // Ship the auth middleware stub anyway so the nb.auth alias registered
        // in bootstrap/app.php resolves to a real class if the dev opts into
        // auth later.
        $middlewareDir = app_path('Http/Middleware');
        if (!is_dir($middlewareDir)) mkdir($middlewareDir, 0755, true);
        $this->publishStub('demo/NativeBladeAuth.php.stub', $middlewareDir . '/NativeBladeAuth.php');

        $this->publishStub('blank/Welcome.php.stub', $livewireDir . '/Welcome.php');
        $this->publishStub('blank/welcome.blade.php.stub', $viewsDir . '/welcome.blade.php');
        $this->publishStub('blank/routes.php.stub', base_path('routes/web.php'));

        $logo = NativeBladeServiceProvider::packagePath('logo_nb.png');
        if (file_exists($logo)) {
            copy($logo, public_path('logo_nb.png'));
        }

        @unlink(resource_path('views/welcome.blade.php'));

        $this->line("  <fg=green>✓</> Blank template published (Welcome with counter)");
    }

    private function patchTailwindSources(): void
    {
        $cssPath = resource_path('css/app.css');
        if (!file_exists($cssPath)) return;

        $content = file_get_contents($cssPath);

        if (str_contains($content, 'nativeblade')) {
            $this->line("  <fg=yellow>→</> Tailwind sources already patched, skipped");
            return;
        }

        $sources = "@source '../../vendor/nativeblade/nativeblade/src/**/*.php';\n"
            . "@source '../../vendor/nativeblade/nativeblade/resources/**/*.blade.php';\n"
            . "@source '../../nativeblade-components/**/*.blade.php';";

        $content = preg_replace(
            "/(@source\s+'\.\.\/\*\*\/\*\.blade\.php';)/",
            "$1\n" . $sources,
            $content,
            1
        );

        if (!str_contains($content, 'animate.min.css')) {
            $content = str_replace("@import 'tailwindcss';", "@import 'tailwindcss';\n@import './animate.min.css';\n@import './nb-animations.css';", $content);
        }

        $safelist = file_get_contents(NativeBladeServiceProvider::packagePath('stubs/tailwind-safelist.css'));
        if ($safelist) {
            $content .= "\n" . $safelist;
        }

        file_put_contents($cssPath, $content);
        $this->line("  <fg=green>✓</> Tailwind sources patched");
    }

    private function patchDatabaseConfig(): void
    {
        $path = config_path('database.php');
        if (!file_exists($path)) return;

        $content = file_get_contents($path);

        if (str_contains($content, 'nativeblade-db')) {
            $this->line("  <fg=yellow>→</> database.php already patched, skipped");
            return;
        }

        $content = str_replace(
            "'database' => env('DB_DATABASE', database_path('database.sqlite')),",
            "'database' => database_path('database.sqlite'),",
            $content
        );

        $nativeConnection = <<<'PHP'

        'native' => [
            'driver' => 'nativeblade-db',
            'native_driver' => 'mysql',
            'host' => env('NB_DB_HOST', '127.0.0.1'),
            'port' => env('NB_DB_PORT', '3306'),
            'database' => env('NB_DB_DATABASE', 'myapp'),
            'username' => env('NB_DB_USERNAME', 'root'),
            'password' => env('NB_DB_PASSWORD', ''),
            'prefix' => '',
        ],

PHP;

        $content = str_replace(
            "'connections' => [\n",
            "'connections' => [\n" . $nativeConnection,
            $content
        );

        file_put_contents($path, $content);
        $this->line("  <fg=green>✓</> database.php patched (sqlite fixed, native_mysql added)");
    }

    private function patchFilesystemsConfig(): void
    {
        $path = config_path('filesystems.php');
        if (!file_exists($path)) {
            $this->line("  <fg=yellow>→</> config/filesystems.php not found, skipped");
            return;
        }

        $content = file_get_contents($path);

        if (str_contains($content, "'driver' => 'nativeblade'")) {
            $this->line("  <fg=yellow>→</> filesystems.php already patched, skipped");
            return;
        }

        $nativeDisk = <<<'PHP'

        'native' => [
            'driver' => 'nativeblade',
        ],

PHP;

        $patched = preg_replace(
            "/('disks'\s*=>\s*\[\s*\n)/",
            "$1" . $nativeDisk,
            $content,
            1
        );

        if ($patched === null || $patched === $content) {
            $this->line("  <fg=yellow>→</> Could not auto-patch filesystems.php — add the 'native' disk manually");
            return;
        }

        file_put_contents($path, $patched);
        $this->line("  <fg=green>✓</> filesystems.php patched (native disk added)");
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

        $langDir = base_path('lang');
        if (!is_dir($langDir)) mkdir($langDir, 0755, true);

        $langSource = NativeBladeServiceProvider::packagePath('stubs/lang');
        if (is_dir($langSource)) {
            foreach (scandir($langSource) as $file) {
                if ($file === '.' || $file === '..') continue;
                copy("{$langSource}/{$file}", "{$langDir}/{$file}");
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
        $words = preg_split('/[^a-zA-Z0-9]+/', $this->appName, -1, PREG_SPLIT_NO_EMPTY);
        $crateName = strtolower(implode('_', $words));

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
