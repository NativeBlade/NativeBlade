<?php

namespace NativeBlade\Commands;

use Illuminate\Console\Command;
use NativeBlade\NativeBladeServiceProvider;
use Symfony\Component\Process\Process;

class AddCommand extends Command
{
    protected $signature = 'nativeblade:add
        {platform : Platform to add (android or ios)}';

    protected $description = 'Add a mobile platform scaffold to an existing NativeBlade project';

    public function handle(): int
    {
        $platform = strtolower((string) $this->argument('platform'));

        if (!in_array($platform, ['android', 'ios'], true)) {
            $this->error("Unsupported platform: {$platform}. Use android or ios.");
            return self::FAILURE;
        }

        if (!is_dir(base_path('src-tauri'))) {
            $this->error('src-tauri/ not found. Run php artisan nativeblade:install first.');
            return self::FAILURE;
        }

        return match ($platform) {
            'android' => $this->addAndroid(),
            'ios' => $this->addIos(),
        };
    }

    private function addAndroid(): int
    {
        $this->info('  Adding Android platform...');

        $cargoDir = base_path('src-tauri/.cargo');
        if (!is_dir($cargoDir)) {
            mkdir($cargoDir, 0755, true);
        }

        $this->publishStub('.cargo/config.toml.stub', $cargoDir . '/config.toml', [
            '{{NDK_HOME}}' => str_replace('\\', '/', env('NDK_HOME', env('ANDROID_NDK_HOME', ''))),
        ]);

        if (!is_dir(base_path('src-tauri/gen/android'))) {
            $this->exec($this->tauriCliCommand('tauri android init --ci'));
            $this->line('  <fg=green>✓</> Android scaffold generated');
        } else {
            $this->line('  <fg=yellow>→</> Android scaffold already exists, skipped');
        }

        $this->call('nativeblade:config');

        $this->info('');
        $this->info('  Android support is ready.');
        return self::SUCCESS;
    }

    private function addIos(): int
    {
        if (PHP_OS_FAMILY !== 'Darwin') {
            $this->error('iOS scaffold must be generated on macOS.');
            return self::FAILURE;
        }

        $this->info('  Adding iOS platform...');

        $this->exec($this->tauriCliCommand('tauri ios init --ci'));
        $this->line('  <fg=green>✓</> iOS scaffold generated');

        $this->call('nativeblade:config');

        $this->info('');
        $this->info('  iOS support is ready.');
        return self::SUCCESS;
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

    private function tauriCliCommand(string $args): string
    {
        return PHP_OS_FAMILY === 'Windows'
            ? "npx.cmd {$args}"
            : "npx {$args}";
    }

    private function publishStub(string $stub, string $dest, array $extra = []): void
    {
        $content = file_get_contents($this->stubPath($stub));
        $content = str_replace(array_keys($extra), array_values($extra), $content);
        file_put_contents($dest, $content);
    }

    private function stubPath(string $name): string
    {
        return NativeBladeServiceProvider::packagePath("stubs/{$name}");
    }
}
