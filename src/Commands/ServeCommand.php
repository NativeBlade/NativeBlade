<?php

namespace NativeBlade\Commands;

use Illuminate\Console\Command;
use NativeBlade\NativeBladeServiceProvider;
use NativeBlade\Support\LanIp;
use Symfony\Component\Process\Process;

class ServeCommand extends Command
{
    protected $signature = 'nativeblade:serve
        {--host= : Host the dev server advertises for HMR (auto-detected LAN IP if empty)}
        {--port=1420 : Port to serve on}';

    protected $description = 'Serve the Vite dev server + live Laravel bundle (no QR), for preview/dev-client builds to connect to';

    private array $processes = [];

    public function handle(): int
    {
        $host = $this->option('host') ?: LanIp::detect();
        $port = $this->option('port') ?: '1420';
        $url = "http://{$host}:{$port}";

        $this->call('nativeblade:config');

        if (!$this->ensureNpmDeps()) {
            return self::FAILURE;
        }

        $this->info('');
        $this->line('  <fg=magenta;options=bold>NativeBlade Serve</>');
        $this->line("  URL:  <info>{$url}</info>");
        $this->line('  <fg=yellow>Device must be on the same network</>');
        $this->info('');

        $this->line('  Building Laravel bundle...');
        $bundleScript = NativeBladeServiceProvider::packagePath('js/scripts/bundle-laravel.js');
        $this->exec('node ' . escapeshellarg($bundleScript) . ' ' . escapeshellarg(base_path()));

        $watchScript = NativeBladeServiceProvider::packagePath('js/scripts/watch-bundle.js');
        $watcher = $this->background("node {$watchScript} " . base_path());

        try {
            $this->exec(
                "npx vite --config vite.wasm.config.js --host 0.0.0.0 --port {$port}",
                ['NATIVEBLADE_HOST' => $host]
            );
        } finally {
            $watcher->stop(0);
        }

        return self::SUCCESS;
    }

    private function ensureNpmDeps(): bool
    {
        if (is_dir(base_path('node_modules/vite'))) {
            return true;
        }

        if (!file_exists(base_path('package.json'))) {
            $this->error('package.json is missing. Run `php artisan nativeblade:install` first.');
            return false;
        }

        $this->warn('Vite not found in node_modules. Running `npm install`...');
        passthru('cd ' . escapeshellarg(base_path()) . ' && npm install 2>&1', $code);

        if ($code !== 0 || !is_dir(base_path('node_modules/vite'))) {
            $this->error('`npm install` failed or Vite still missing. Run it manually and try again.');
            return false;
        }

        return true;
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
}
