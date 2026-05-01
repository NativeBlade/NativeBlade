<?php

namespace NativeBlade\Commands;

use Illuminate\Console\Command;
use NativeBlade\NativeBladeServiceProvider;

class BundleCommand extends Command
{
    protected $signature = 'nativeblade:bundle
        {--tag= : Tag the output with a version (writes laravel-bundle-{tag}.json.gz alongside the canonical name)}
        {--no-dev : Run composer install --no-dev before bundling (default: true)}';

    protected $description = 'Build only the Laravel bundle (laravel-bundle.json.gz) — for OTA bundle push without rebuilding the native shell';

    public function handle(): int
    {
        $tag = $this->option('tag');
        $noDev = $this->option('no-dev') !== false;

        $this->line('');
        $this->info('  Building Laravel bundle...');
        $this->line('');

        if ($noDev) {
            $this->line('  Running composer install --no-dev...');
            $this->runShell('composer install --no-dev --optimize-autoloader 2>&1');
        }

        $bundleScript = NativeBladeServiceProvider::packagePath('js/scripts/bundle-laravel.js');
        $this->runShell("node {$bundleScript} " . base_path());

        $bundleGz = base_path('public/laravel-bundle.json.gz');
        if (!file_exists($bundleGz)) {
            $this->error('  Bundle output not found at public/laravel-bundle.json.gz');
            return self::FAILURE;
        }

        $size = filesize($bundleGz);
        $sizeMb = round($size / 1024 / 1024, 2);

        $this->line('');
        $this->line("  <fg=green>✓</> public/laravel-bundle.json.gz ({$sizeMb} MB)");

        if ($tag) {
            $tagged = base_path("public/laravel-bundle-{$tag}.json.gz");
            copy($bundleGz, $tagged);
            $this->line("  <fg=green>✓</> public/laravel-bundle-{$tag}.json.gz");
        }

        $this->line('');
        $this->info('  Bundle built. Upload it to your CDN and update version.json:');
        $this->line('');
        $this->line('    {');
        $this->line('      "bundle": {');
        $this->line("        \"version\": \"" . ($tag ?: '1.0.0') . "\",");
        $this->line("        \"url\": \"https://releases.myapp.com/laravel-bundle-" . ($tag ?: '1.0.0') . ".json.gz\"");
        $this->line('      }');
        $this->line('    }');
        $this->line('');

        return self::SUCCESS;
    }

    private function runShell(string $cmd): void
    {
        $cwd = base_path();
        $process = proc_open(
            $cmd,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $cwd
        );

        if (!is_resource($process)) {
            $this->error("  Failed to run: {$cmd}");
            return;
        }

        while (!feof($pipes[1])) {
            $line = fgets($pipes[1]);
            if ($line !== false) $this->getOutput()->write($line);
        }
        while (!feof($pipes[2])) {
            $line = fgets($pipes[2]);
            if ($line !== false) $this->getOutput()->write($line);
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
    }
}
