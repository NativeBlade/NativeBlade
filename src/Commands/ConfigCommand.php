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
        $declared = ShellConfig::getDeclaredPlugins();
        $plugins = PluginRegistry::resolve($declared);

        if ($declared === null) {
            $this->line('  <fg=yellow>→</> No NativeBladeConfig::plugins([...]) declared — enabling every plugin (prototyping default). Declare the set you use before shipping.');
        }

        (new PluginsConfigGenerator($this))->generate(
            $plugins,
            $configs['android'] ?? [],
            $configs['ios'] ?? [],
            ShellConfig::getCustomPlugins()
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

            $version = $this->detectAppVersion($configs);
            if ($version !== null) {
                $runtime['bundlePush']['shellVersion'] = $version;
                $runtime['bundlePush']['bundleVersion'] = $version;
            }
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

    /**
     * Resolve the declared app version. Devs usually set the same version on
     * every platform, so we take the first one present. Null when no platform
     * declared a version (the runtime then keeps its 0.0.0 fallback).
     */
    private function detectAppVersion(array $configs): ?string
    {
        foreach (['desktop', 'android', 'ios'] as $platform) {
            $version = $configs[$platform]['version'] ?? null;
            if (!empty($version)) {
                return (string) $version;
            }
        }
        return null;
    }
}
