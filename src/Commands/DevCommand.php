<?php

namespace NativeBlade\Commands;

use Illuminate\Console\Command;
use NativeBlade\NativeBladeServiceProvider;
use Symfony\Component\Process\Process;

class DevCommand extends Command
{
    protected $signature = 'nativeblade:dev
        {--platform=desktop : Platform to run (desktop, android, ios, portal)}
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

        $this->call('nativeblade:config');

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

        match ($platform) {
            'desktop' => $build ? $this->runBuiltDesktop() : $this->runDesktop($port),
            'android' => $build ? $this->runBuiltAndroid() : $this->runAndroid($host, $port),
            'ios' => $build ? $this->runBuiltIos() : $this->runIos($host, $port),
            'portal' => $this->runPortal($host, $port),
            default => $this->error("Unknown platform: {$platform}"),
        };

        return self::SUCCESS;
    }

    private function runBuiltDesktop(): void
    {
        $this->info('Starting Tauri desktop dev (built assets, no HMR)...');
        $this->exec('npx tauri dev --config ' . $this->builtConfigArg());
    }

    private function runBuiltAndroid(): void
    {
        $this->info('Starting Tauri Android dev (built assets, no HMR)...');
        $this->exec("npx tauri android dev --config " . $this->builtConfigArg(), $this->androidEnv());
    }

    private function runBuiltIos(): void
    {
        $this->info('Starting Tauri iOS dev (built assets, no HMR)...');
        $this->exec('npx tauri ios dev --config ' . $this->builtConfigArg());
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
        $this->info('Starting Tauri desktop dev...');
        $this->exec("npx tauri dev", [
            'TAURI_CONFIG' => json_encode(['build' => ['devUrl' => "http://localhost:{$port}"]]),
        ]);
    }

    private function runAndroid(string $host, string $port): void
    {
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
        $this->exec("npx tauri android dev --config {$escaped}", $this->androidEnv());

        $vite->stop(0);
    }

    private function runIos(string $host, string $port): void
    {
        $this->info("Starting Vite dev server at http://{$host}:{$port} ...");
        $vite = $this->background(
            "npx vite --config vite.wasm.config.js --host --port {$port}",
            ['NATIVEBLADE_HOST' => $host]
        );

        sleep(3);

        $this->info('Starting Tauri iOS dev...');
        $this->exec("npx tauri ios dev --config " . escapeshellarg(json_encode([
            'build' => [
                'devUrl' => "http://{$host}:{$port}",
                'beforeDevCommand' => '',
            ],
        ])));

        $vite->stop(0);
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
        if (PHP_OS_FAMILY === 'Windows') {
            return $this->detectIPWindows();
        }

        $output = [];
        exec("hostname -I 2>/dev/null", $output);
        if (!empty($output[0])) {
            $ips = array_values(array_filter(explode(' ', trim($output[0])), fn($ip) => $this->isUsableLanIp($ip)));
            foreach ($ips as $ip) {
                if (str_starts_with($ip, '192.168.')) return $ip;
            }
            return $ips[0] ?? '127.0.0.1';
        }
        return '127.0.0.1';
    }

    private function detectIPWindows(): string
    {
        $output = [];
        exec('ipconfig', $output);

        $skip = false;
        $candidates = [];

        foreach ($output as $line) {
            if (preg_match('/^(Ethernet|Wireless LAN|Wireless|Unknown) adapter (.+):$/', $line, $m)) {
                $adapter = $m[2];
                $skip = stripos($adapter, 'VirtualBox') !== false
                     || stripos($adapter, 'VMware') !== false
                     || stripos($adapter, 'vEthernet') !== false
                     || stripos($adapter, 'Loopback') !== false
                     || stripos($adapter, 'WSL') !== false
                     || stripos($adapter, 'Hyper-V') !== false;
                continue;
            }

            if ($skip) continue;

            if (preg_match('/IPv4.*?:\s*(\d+\.\d+\.\d+\.\d+)/', $line, $m) && $this->isUsableLanIp($m[1])) {
                $candidates[] = $m[1];
            }
        }

        foreach ($candidates as $ip) {
            if (str_starts_with($ip, '192.168.')) return $ip;
        }
        foreach ($candidates as $ip) {
            if (str_starts_with($ip, '10.')) return $ip;
        }
        return $candidates[0] ?? '127.0.0.1';
    }

    private function isUsableLanIp(string $ip): bool
    {
        if (!preg_match('/^\d+\.\d+\.\d+\.\d+$/', $ip)) return false;
        if (str_starts_with($ip, '127.')) return false;
        if (str_starts_with($ip, '169.254.')) return false;
        // VirtualBox default host-only
        if (str_starts_with($ip, '192.168.56.')) return false;
        // VirtualBox alternate default
        if (str_starts_with($ip, '192.168.99.')) return false;
        return true;
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

            if ($platform !== 'desktop') {
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