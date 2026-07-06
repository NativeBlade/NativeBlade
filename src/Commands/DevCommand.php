<?php

namespace NativeBlade\Commands;

use Illuminate\Console\Command;
use NativeBlade\Config\PluginRegistry;
use NativeBlade\NativeBladeServiceProvider;
use NativeBlade\ShellConfig;
use Symfony\Component\Process\Process;

class DevCommand extends Command
{
    protected $signature = 'nativeblade:dev
        {--platform=desktop : Platform to run (desktop, android, ios, portal, browser)}
        {--host= : IP address for mobile dev (auto-detected if empty)}
        {--port=1420 : Vite dev server port}
        {--build : Use built assets instead of Vite dev server (no HMR)}';

    protected $description = 'Start NativeBlade development server with hot reload';

    private array $processes = [];

    public function handle(): int
    {
        $platform = $this->option('platform');
        $port = $this->option('port');
        $host = $this->option('host') ?: $this->detectIP();
        $build = (bool) $this->option('build');

        // Browser mode never touches Tauri/Rust: no config regeneration (it
        // rewrites Cargo/tauri files) and no @tauri-apps/cli requirement.
        if ($platform !== 'browser') {
            $this->call('nativeblade:config');
        }

        if (!$this->ensureNpmDeps(requireTauri: $platform !== 'browser')) {
            return self::FAILURE;
        }

        $this->printBanner($platform, $host, $port, $build);

        // Ensure dist-wasm exists (Tauri checks frontendDist at compile time)
        $distDir = base_path('dist-wasm');
        if (!is_dir($distDir)) {
            mkdir($distDir, 0755, true);
            file_put_contents($distDir . '/index.html', '<!-- dev mode placeholder -->');
        }

        $synced = $this->syncPackageComponents();

        $this->info('Building Laravel bundle...');
        $bundleScript = NativeBladeServiceProvider::packagePath('js/scripts/bundle-laravel.js');
        $this->exec("node {$bundleScript} " . base_path());

        if ($build) {
            $this->info('Building frontend bundle...');
            $this->exec('npx vite build --config vite.wasm.config.js');
        }

        // Background watcher: rebuilds the bundle on PHP/Blade changes so
        // cold restarts pick up edits, not just HMR sessions.
        $watcher = null;
        if (!$build) {
            $watchScript = NativeBladeServiceProvider::packagePath('js/scripts/watch-bundle.js');
            $watcher = $this->background("node {$watchScript} " . base_path());
        }

        try {
            match ($platform) {
                'desktop' => $build ? $this->runBuiltDesktop() : $this->runDesktop($port),
                'android' => $build ? $this->runBuiltAndroid() : $this->runAndroid($host, $port),
                'ios' => $build ? $this->runBuiltIos() : $this->runIos($host, $port),
                'portal' => $this->runPortal($host, $port),
                'browser' => $this->runBrowser($port, $build),
                default => $this->error("Unknown platform: {$platform}"),
            };
        } finally {
            if ($watcher) $watcher->stop(0);
        }

        return self::SUCCESS;
    }

    /**
     * Guard against the "npm error could not determine executable to run"
     * failure mode: every `nativeblade:dev` path eventually shells out to
     * `npx tauri ...`, and npx can only find that binary if @tauri-apps/cli
     * was installed into node_modules. The common ways this gets out of sync:
     *
     *   - Fresh clone, dev forgot `npm install`.
     *   - `nativeblade:install` ran but the npm step failed silently (yellow
     *     warning the dev missed in a long install log).
     *   - A new Tauri plugin was added to the app, `nativeblade:config`
     *     updated package.json with a new dep, but node_modules was never
     *     refreshed.
     *
     * Auto-run `npm install` when the Tauri CLI is missing. It is a no-op
     * when deps are already in sync, so the cost of always checking is tiny.
     */
    private function ensureNpmDeps(bool $requireTauri = true): bool
    {
        // Browser mode only shells out to vite, so that's the binary to probe.
        $marker = $requireTauri
            ? base_path('node_modules/@tauri-apps/cli/package.json')
            : base_path('node_modules/vite/package.json');
        $missingLabel = $requireTauri ? 'Tauri CLI' : 'Vite';

        if (file_exists($marker)) {
            return true;
        }

        if (!file_exists(base_path('package.json'))) {
            $this->error('package.json is missing. Run `php artisan nativeblade:install` first.');
            return false;
        }

        $this->warn("{$missingLabel} not found in node_modules. Running `npm install`...");
        $cmd = 'cd ' . escapeshellarg(base_path()) . ' && npm install 2>&1';
        passthru($cmd, $code);

        if ($code !== 0) {
            $this->error('`npm install` failed. Run it manually and try again.');
            return false;
        }

        if (!file_exists($marker)) {
            $this->error("{$missingLabel} still missing after `npm install`. Check package.json and your registry access.");
            return false;
        }

        $this->info('npm dependencies installed.');
        return true;
    }

    private function runBuiltDesktop(): void
    {
        $this->info('Starting Tauri desktop dev (built assets, no HMR)...');
        $this->exec('npx tauri dev ' . $this->cargoFeaturesArg() . ' --config ' . $this->builtConfigArg());
    }

    private function runBuiltAndroid(): void
    {
        if (!$this->waitForAndroidDevice()) {
            return;
        }
        $this->info('Starting Tauri Android dev (built assets, no HMR)...');
        $this->exec("npx tauri android dev " . $this->cargoFeaturesArg() . " --config " . $this->builtConfigArg(), $this->androidEnv());
    }

    private function runBuiltIos(): void
    {
        if (!$this->waitForIosDevice()) {
            return;
        }
        $this->info('Starting Tauri iOS dev (built assets, no HMR)...');
        $this->exec('npx tauri ios dev ' . $this->cargoFeaturesArg() . ' --config ' . $this->builtConfigArg());
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

    private function builtConfigArg(): string
    {
        $configJson = json_encode([
            'build' => [
                'devUrl' => null,
                'beforeDevCommand' => null,
                'frontendDist' => '../dist-wasm',
            ],
        ]);
        return PHP_OS_FAMILY === 'Windows'
            ? '"' . str_replace('"', '\\"', $configJson) . '"'
            : escapeshellarg($configJson);
    }

    private function runDesktop(string $port): void
    {
        $this->info("Starting Vite dev server at http://localhost:{$port} ...");
        $vite = $this->background("npx vite --config vite.wasm.config.js --port {$port}");

        sleep(3);

        $this->info('Starting Tauri desktop dev...');
        $configJson = json_encode([
            'build' => [
                'devUrl' => "http://localhost:{$port}",
                'beforeDevCommand' => '',
            ],
        ]);
        $escaped = PHP_OS_FAMILY === 'Windows'
            ? '"' . str_replace('"', '\\"', $configJson) . '"'
            : escapeshellarg($configJson);
        $this->exec("npx tauri dev " . $this->cargoFeaturesArg() . " --config {$escaped}");

        $vite->stop(0);
    }

    private function runAndroid(string $host, string $port): void
    {
        if (!$this->waitForAndroidDevice()) {
            return;
        }

        $this->info("Starting Vite dev server at http://{$host}:{$port} ...");
        $vite = $this->background(
            "npx vite --config vite.wasm.config.js --host --port {$port}",
            ['NATIVEBLADE_HOST' => $host]
        );

        sleep(3);

        $this->info('Starting Tauri Android dev...');
        $configJson = json_encode([
            'build' => [
                'devUrl' => "http://{$host}:{$port}",
                'beforeDevCommand' => '',
            ],
        ]);
        $escaped = PHP_OS_FAMILY === 'Windows'
            ? '"' . str_replace('"', '\\"', $configJson) . '"'
            : escapeshellarg($configJson);
        $this->exec("npx tauri android dev " . $this->cargoFeaturesArg() . " --config {$escaped}", $this->androidEnv());

        $vite->stop(0);
    }

    /**
     * Block until at least one Android device or running emulator is detected
     * via `adb devices`. Polls forever until found or until the user aborts
     * with Ctrl+C. Without this, Tauri's android dev command opens Android
     * Studio whenever no target is connected.
     */
    private function waitForAndroidDevice(): bool
    {
        [$devices, $raw] = $this->listAndroidDevices();
        if (!empty($devices)) {
            $this->line("  <fg=green>✓</> Android device ready: <info>" . $devices[0] . "</info>");
            return true;
        }

        $this->newLine();
        $this->line('  <fg=yellow>Waiting for an Android device or emulator...</>');
        $this->line('  Connect via USB (with USB debugging enabled) or start an emulator.');
        $this->line('  <fg=gray>Press Ctrl+C to abort.</>');
        if ($raw !== '') {
            $this->newLine();
            $this->line('  <fg=gray>adb devices output:</>');
            foreach (preg_split('/\r?\n/', trim($raw)) as $line) {
                $this->line('    <fg=gray>' . $line . '</>');
            }
        }
        $this->newLine();

        $spinner = ['⠋', '⠙', '⠹', '⠸', '⠼', '⠴', '⠦', '⠧', '⠇', '⠏'];
        $i = 0;
        while (true) {
            [$devices] = $this->listAndroidDevices();
            if (!empty($devices)) {
                $this->output->write("\r\033[K");
                $this->line("  <fg=green>✓</> Android device ready: <info>" . $devices[0] . "</info>");
                $this->newLine();
                return true;
            }
            $this->output->write("\r  " . $spinner[$i % count($spinner)] . ' polling adb...');
            $i++;
            sleep(1);
        }
    }

    /**
     * Run `adb devices` and parse the output.
     *
     * @return array{0: string[], 1: string} [device serials in "device" state, raw stdout for debugging]
     */
    private function listAndroidDevices(): array
    {
        $adb = $this->resolveAdbPath();
        $cmd = $adb !== null ? escapeshellarg($adb) . ' devices' : 'adb devices';

        $process = Process::fromShellCommandline($cmd, base_path());
        $process->setTimeout(10);
        try {
            $process->run();
        } catch (\Throwable $e) {
            return [[], ''];
        }
        $raw = $process->getOutput() . $process->getErrorOutput();

        $devices = [];
        foreach (preg_split('/\r?\n/', $raw) as $line) {
            $line = trim($line);
            if ($line === '') continue;
            if (str_starts_with($line, 'List of devices')) continue;
            if (str_starts_with($line, '*')) continue; // daemon messages
            if (preg_match('/^(\S+)\s+device\b/', $line, $m)) {
                $devices[] = $m[1];
            }
        }
        return [$devices, $raw];
    }

    /**
     * Resolve the absolute path to adb. PHP's PATH typically does not match
     * the user's shell PATH — Tauri picks up adb because Cargo's build
     * environment is enriched with ANDROID_HOME, but our `exec`/`Process`
     * calls aren't. Use the system env (NOT Laravel's `env()`, which only
     * reads .env files) and fall back to the standard SDK install location.
     */
    private function resolveAdbPath(): ?string
    {
        $bin = PHP_OS_FAMILY === 'Windows' ? 'adb.exe' : 'adb';

        $home = getenv('ANDROID_HOME') ?: getenv('ANDROID_SDK_ROOT');
        if ($home && is_file($home . DIRECTORY_SEPARATOR . 'platform-tools' . DIRECTORY_SEPARATOR . $bin)) {
            return $home . DIRECTORY_SEPARATOR . 'platform-tools' . DIRECTORY_SEPARATOR . $bin;
        }

        $defaults = PHP_OS_FAMILY === 'Windows'
            ? [(getenv('LOCALAPPDATA') ?: '') . '\\Android\\Sdk\\platform-tools\\adb.exe']
            : [
                (getenv('HOME') ?: '') . '/Library/Android/sdk/platform-tools/adb',
                (getenv('HOME') ?: '') . '/Android/Sdk/platform-tools/adb',
            ];
        foreach ($defaults as $candidate) {
            if (is_file($candidate)) return $candidate;
        }

        return null;
    }

    private function detectAndroidTarget(): ?string
    {
        $adb = $this->resolveAdbPath();
        $cmd = $adb !== null ? escapeshellarg($adb) : 'adb';

        $output = [];
        @exec("$cmd shell getprop ro.product.cpu.abi 2>&1", $output, $code);
        if ($code !== 0 || empty($output)) return null;

        $abi = trim($output[0]);
        return match ($abi) {
            'arm64-v8a' => 'aarch64',
            'armeabi-v7a' => 'armv7',
            'x86_64' => 'x86_64',
            'x86' => 'i686',
            default => null,
        };
    }

    private function runIos(string $host, string $port): void
    {
        if (!$this->waitForIosDevice()) {
            return;
        }

        $this->info("Starting Vite dev server at http://{$host}:{$port} ...");
        $vite = $this->background(
            "npx vite --config vite.wasm.config.js --host --port {$port}",
            ['NATIVEBLADE_HOST' => $host]
        );

        sleep(3);

        $this->info('Starting Tauri iOS dev...');
        $this->exec("npx tauri ios dev " . $this->cargoFeaturesArg() . " --config " . escapeshellarg(json_encode([
            'build' => [
                'devUrl' => "http://{$host}:{$port}",
                'beforeDevCommand' => '',
            ],
        ])));

        $vite->stop(0);
    }

    /**
     * Block until at least one iOS simulator is booted or a physical device is
     * connected. Without this, `tauri ios dev` opens Xcode and waits there.
     */
    private function waitForIosDevice(): bool
    {
        $found = $this->detectIosDevice();
        if ($found !== null) {
            $this->line("  <fg=green>✓</> iOS target ready: <info>{$found}</info>");
            return true;
        }

        $this->newLine();
        $this->line('  <fg=yellow>Waiting for an iOS simulator or device...</>');
        $this->line('  Boot a simulator (open Simulator.app) or plug in a device.');
        $this->line('  <fg=gray>Press Ctrl+C to abort.</>');
        $this->newLine();

        $spinner = ['⠋', '⠙', '⠹', '⠸', '⠼', '⠴', '⠦', '⠧', '⠇', '⠏'];
        $i = 0;
        while (true) {
            $found = $this->detectIosDevice();
            if ($found !== null) {
                $this->output->write("\r\033[K");
                $this->line("  <fg=green>✓</> iOS target ready: <info>{$found}</info>");
                $this->newLine();
                return true;
            }
            $this->output->write("\r  " . $spinner[$i % count($spinner)] . ' polling xcrun...');
            $i++;
            sleep(1);
        }
    }

    /**
     * Returns a human-readable label for the first available iOS target,
     * or null if none is booted/connected.
     */
    private function detectIosDevice(): ?string
    {
        $output = [];
        @exec('xcrun simctl list devices booted 2>&1', $output);
        foreach ($output as $line) {
            if (preg_match('/^\s+(.+?)\s+\([A-F0-9-]+\)\s+\(Booted\)/', $line, $m)) {
                return trim($m[1]) . ' (simulator)';
            }
        }

        $output = [];
        @exec('xcrun devicectl list devices 2>&1', $output, $code);
        if ($code === 0) {
            foreach ($output as $line) {
                if (preg_match('/connected/i', $line) && preg_match('/iPhone|iPad/i', $line)) {
                    return trim($line);
                }
            }
        }

        return null;
    }

    private function runPortal(string $host, string $port): void
    {
        $url = "http://{$host}:{$port}";

        $this->newLine();
        $this->line('  <fg=magenta;options=bold>NativeBlade Portal</>');
        $this->line("  Bundle URL: <info>{$url}</info>");
        $this->newLine();

        $this->printQR($url);

        $this->line('  Open the NativeBlade Portal app and paste:');
        $this->line("    <fg=cyan;options=bold>{$url}</>");
        $this->newLine();
        $this->line('  <fg=yellow>Device must be on the same WiFi network</>');
        $this->newLine();

        $this->exec(
            "npx vite --config vite.wasm.config.js --host 0.0.0.0 --port {$port}",
            ['NATIVEBLADE_HOST' => $host]
        );
    }

    /**
     * Browser mode: no Tauri shell, no Rust build — just the WASM bundle on
     * the Vite server, opened in the default browser. Native plugin actions
     * are silent no-ops outside Tauri (bridge.js isTauri guard), so this is
     * for iterating on UI/Livewire/WASM behavior, not native features.
     */
    private function runBrowser(string $port, bool $build): void
    {
        $url = "http://localhost:{$port}";

        $this->newLine();
        $this->line('  <fg=magenta;options=bold>NativeBlade Browser</>');
        $this->line("  URL: <info>{$url}</info>");
        $this->newLine();
        $this->line('  <fg=yellow>Native plugins are no-ops in a plain browser — UI/Livewire/WASM only.</>');
        $this->newLine();

        if ($build) {
            // handle() already ran `vite build`; preview serves dist-wasm as-is.
            $this->exec("npx vite preview --config vite.wasm.config.js --port {$port} --open");
            return;
        }

        $this->exec("npx vite --config vite.wasm.config.js --port {$port} --open");
    }

    private function printQR(string $content): void
    {
        try {
            foreach (\NativeBlade\Support\TerminalQrCode::render($content) as $line) {
                $this->line('  ' . $line);
            }
            $this->newLine();
        } catch (\Throwable $e) {
            $this->warn('  QR rendering unavailable: ' . $e->getMessage());
            $this->newLine();
        }
    }

    private function detectIP(): string
    {
        return \NativeBlade\Support\LanIp::detect();
    }

    private function androidEnv(): array
    {
        return array_filter([
            'ANDROID_HOME' => env('ANDROID_HOME'),
            'NDK_HOME' => env('NDK_HOME'),
            'JAVA_HOME' => env('JAVA_HOME'),
        ]);
    }

    private function exec(string $command, array $env = []): void
    {
        $process = Process::fromShellCommandline($command, base_path());
        $process->setTimeout(null);
        $process->setEnv(array_merge($_ENV, $env));
        $process->setTty(Process::isTtySupported());

        $process->run(function ($type, $buffer) {
            $this->output->write($buffer);
        });
    }

    private function background(string $command, array $env = []): Process
    {
        $process = Process::fromShellCommandline($command, base_path());
        $process->setTimeout(null);
        $process->setEnv(array_merge($_ENV, $env));
        $process->start(function ($type, $buffer) {
            $this->output->write($buffer);
        });
        $this->processes[] = $process;
        return $process;
    }

    private function printBanner(string $platform, string $host, string $port, bool $build = false): void
    {
        $this->newLine();
        $this->line('  <fg=magenta;options=bold>NativeBlade Dev</>');
        $this->line("  Platform:  <info>{$platform}</info>");
        $this->line('  Mode:      <info>' . ($build ? 'built (no HMR)' : 'dev (HMR)') . '</info>');

        if (!$build) {
            $this->line("  Host:      <info>{$host}</info>");
            $this->line("  Port:      <info>{$port}</info>");

            if (!in_array($platform, ['desktop', 'browser'], true)) {
                $this->line("  Dev URL:   <info>http://{$host}:{$port}</info>");
                $this->line('  <fg=yellow>Device must be on the same WiFi network</>');
            }
        }

        $this->newLine();
    }

    private function syncPackageComponents(): array
    {
        $installedPath = base_path('vendor/composer/installed.json');
        if (!file_exists($installedPath)) return [];

        $data = json_decode(file_get_contents($installedPath), true);
        $packages = $data['packages'] ?? $data;
        $names = [];

        foreach ($packages as $package) {
            $nb = $package['extra']['nativeblade'] ?? null;
            if (!$nb || empty($nb['components'])) continue;

            $pkgPath = base_path('vendor/' . ($package['name'] ?? ''));

            foreach ($nb['components'] as $name => $folder) {
                $srcDir = $pkgPath . '/' . ltrim($folder, '/');
                if (!is_dir($srcDir)) continue;

                $destDir = base_path("nativeblade-components/{$name}");

                if (is_dir($destDir)) {
                    foreach (scandir($destDir) as $old) {
                        if ($old === '.' || $old === '..') continue;
                        @unlink("{$destDir}/{$old}");
                    }
                } else {
                    mkdir($destDir, 0755, true);
                }

                foreach (scandir($srcDir) as $file) {
                    if ($file === '.' || $file === '..') continue;
                    copy("{$srcDir}/{$file}", "{$destDir}/{$file}");
                }

                $names[] = $name;
            }
        }

        if (!empty($names)) {
            $this->newLine();
            $this->line('  <fg=cyan;options=bold>External Components</>');
            foreach ($names as $name) {
                $this->line("  <fg=green>✓</> {$name}");
            }
            $this->newLine();
        }

        return $names;
    }

    private function cleanupPackageComponents(array $names): void
    {
        foreach ($names as $name) {
            $dir = base_path("nativeblade-components/{$name}");
            if (!is_dir($dir)) continue;

            foreach (scandir($dir) as $file) {
                if ($file === '.' || $file === '..') continue;
                @unlink("{$dir}/{$file}");
            }
            @rmdir($dir);
        }
    }
}