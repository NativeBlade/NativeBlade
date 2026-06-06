<?php

namespace NativeBlade\Commands;

use Illuminate\Console\Command;
use NativeBlade\Commands\Config\AndroidConfigGenerator;
use NativeBlade\Commands\Config\DesktopConfigGenerator;
use NativeBlade\Commands\Config\IosConfigGenerator;
use NativeBlade\Commands\Config\PluginsConfigGenerator;
use NativeBlade\Config\PluginRegistry;
use NativeBlade\ShellConfig;

class ConfigCommand extends Command
{
    protected $signature = 'nativeblade:config';
    protected $description = 'Generate Tauri config from NativeBlade PHP config';

    public function handle(): int
    {
        app()->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

        $configs = ShellConfig::getAppConfigs();
        $plugins = PluginRegistry::resolve(ShellConfig::getDeclaredPlugins());

        (new PluginsConfigGenerator($this))->generate(
            $plugins,
            $configs['android'] ?? [],
            $configs['ios'] ?? []
        );
        (new DesktopConfigGenerator($this))->generate($configs['desktop'] ?? []);
        (new AndroidConfigGenerator($this))->generate($configs['android'] ?? []);
        (new IosConfigGenerator($this))->generate($configs['ios'] ?? []);
        $this->writeRuntimeConfig($configs);

        $this->info('  Config generated from PHP.');
        return self::SUCCESS;
    }

    /**
     * Write runtime config that the JS side reads at boot. Currently used
     * by the bundle-push module — published to public/nativeblade-config.json
     * so it ships alongside laravel-bundle.json.gz.
     */
    private function writeRuntimeConfig(array $configs): void
    {
        $runtime = [];
        if (isset($configs['bundlePush'])) {
            $runtime['bundlePush'] = $configs['bundlePush'];
        }
        if (isset($configs['analytics'])) {
            $runtime['analytics'] = ['autoScreenTracking' => (bool) ($configs['analytics']['autoScreenTracking'] ?? false)];
        }

        $path = base_path('public/nativeblade-config.json');
        if (empty($runtime)) {
            if (file_exists($path)) unlink($path);
            return;
        }

        @mkdir(dirname($path), 0755, true);
        file_put_contents($path, json_encode($runtime, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->line("  <fg=green>✓</> public/nativeblade-config.json");
    }
}
