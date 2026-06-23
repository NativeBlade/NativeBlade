<?php

namespace NativeBlade\Commands;

use Illuminate\Console\Command;

class PhpVersionCommand extends Command
{
    protected $signature = 'nativeblade:php {version? : PHP version (e.g. 8.3, 8.4, 8.5)}';
    protected $description = 'Set the PHP WASM version';

    public function handle(): int
    {
        $version = $this->argument('version') ?? PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;

        if (!preg_match('/^\d+\.\d+$/', $version)) {
            $this->error("  Invalid version: {$version}. Use format: 8.3, 8.4, 8.5");
            return self::FAILURE;
        }

        $pkg = str_replace('.', '-', $version);

        $loaderPath = resource_path('js/php-loader.js');
        file_put_contents($loaderPath, "export { getPHPLoaderModule } from '@php-wasm/web-{$pkg}';\n");

        $this->line("  <fg=green>✓</> PHP WASM version set to {$version}");
        $this->line('');
        $this->line("  Run: npm install @php-wasm/web-{$pkg}@^3.1.41");
        $this->line("  Then: npm run build");

        return self::SUCCESS;
    }
}
