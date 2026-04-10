<?php

namespace NativeBlade\Commands;

use Illuminate\Console\Command;
use NativeBlade\Commands\Config\AndroidConfigGenerator;
use NativeBlade\Commands\Config\DesktopConfigGenerator;
use NativeBlade\Commands\Config\IosConfigGenerator;
use NativeBlade\ShellConfig;

class ConfigCommand extends Command
{
    protected $signature = 'nativeblade:config';
    protected $description = 'Generate Tauri config from NativeBlade PHP config';

    public function handle(): int
    {
        app()->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

        $configs = ShellConfig::getAppConfigs();

        (new DesktopConfigGenerator($this))->generate($configs['desktop'] ?? []);
        (new AndroidConfigGenerator($this))->generate($configs['android'] ?? []);
        (new IosConfigGenerator($this))->generate($configs['ios'] ?? []);

        $this->info('  Config generated from PHP.');
        return 0;
    }
}
