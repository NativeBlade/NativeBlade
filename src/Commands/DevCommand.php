<?php

namespace NativeBlade\Commands;

use Illuminate\Console\Command;
use NativeBlade\NativeBladeServiceProvider;
use Symfony\Component\Process\Process;

class DevCommand extends Command
{
    protected $signature = 'nativeblade:dev
        {--platform=desktop : Platform to run (desktop, android, ios)}
        {--host= : IP address for mobile dev (auto-detected if empty)}
        {--port=1420 : Vite dev server port}';

    protected $description = 'Start NativeBlade development server with hot reload';

    private array $processes = [];

    public function handle(): int
    {
        $platform = $this->option('platform');
        $port = $this->option('port');
        $host = $this->option('host') ?: $this->detectIP();

        $this->call('nativeblade:config');

        $this->printBanner($platform, $host, $port);

        $this->info('Building Laravel bundle...');
        $bundleScript = NativeBladeServiceProvider::packagePath('js/scripts/bundle-laravel.js');
        $this->exec("node {$bundleScript} " . base_path());

        match ($platform) {
            'desktop' => $this->runDesktop($port),
            'android' => $this->runAndroid($host, $port),
            'ios' => $this->runIos($host, $port),
            default => $this->error("Unknown platform: {$platform}"),
        };

        return 0;
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
        $this->info('Starting Vite dev server...');
        $vite = $this->background("npx vite --config vite.wasm.config.js");

        sleep(3);

        $this->info('Starting Tauri Android dev...');
        $configJson = json_encode(['build' => ['devUrl' => "http://{$host}:{$port}"]]);
        $escaped = PHP_OS_FAMILY === 'Windows'
            ? '"' . str_replace('"', '\\"', $configJson) . '"'
            : escapeshellarg($configJson);
        $this->exec("npx tauri android dev --config {$escaped}", $this->androidEnv());

        $vite->stop(0);
    }

    private function runIos(string $host, string $port): void
    {
        $this->info('Starting Vite dev server...');
        $vite = $this->background("npx vite --config vite.wasm.config.js");

        sleep(3);

        $this->info('Starting Tauri iOS dev...');
        $this->exec("npx tauri ios dev --config " . escapeshellarg(json_encode([
            'build' => ['devUrl' => "http://{$host}:{$port}"],
        ])));

        $vite->stop(0);
    }

    private function detectIP(): string
    {
        $output = [];
        if (PHP_OS_FAMILY === 'Windows') {
            exec('ipconfig', $output);
            foreach ($output as $line) {
                if (preg_match('/IPv4.*?:\s*(192\.168\.\d+\.\d+)/', $line, $m)) {
                    return $m[1];
                }
            }
        } else {
            exec("hostname -I 2>/dev/null", $output);
            if (!empty($output[0])) {
                $ips = explode(' ', trim($output[0]));
                foreach ($ips as $ip) {
                    if (str_starts_with($ip, '192.168.')) return $ip;
                }
                return $ips[0];
            }
        }
        return '127.0.0.1';
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

    private function background(string $command): Process
    {
        $process = Process::fromShellCommandline($command, base_path());
        $process->setTimeout(null);
        $process->start(function ($type, $buffer) {
            $this->output->write($buffer);
        });
        $this->processes[] = $process;
        return $process;
    }

    private function printBanner(string $platform, string $host, string $port): void
    {
        $this->newLine();
        $this->line('  <fg=magenta;options=bold>NativeBlade Dev</>');
        $this->line("  Platform:  <info>{$platform}</info>");
        $this->line("  Host:      <info>{$host}</info>");
        $this->line("  Port:      <info>{$port}</info>");

        if ($platform !== 'desktop') {
            $this->line("  Dev URL:   <info>http://{$host}:{$port}</info>");
            $this->line('  <fg=yellow>Device must be on the same WiFi network</>');
        }

        $this->newLine();
    }
}
