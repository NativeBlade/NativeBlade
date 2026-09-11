<?php

namespace NativeBlade\Mcp\Tools;

use Composer\InstalledVersions;
use NativeBlade\Config\Plugin;
use NativeBlade\Config\PluginRegistry;
use NativeBlade\Mcp\Tool;
use NativeBlade\ShellConfig;

class ProjectState implements Tool
{
    public function name(): string
    {
        return 'project_state';
    }

    public function description(): string
    {
        return 'Inspect NativeBlade configuration loaded by the host Laravel project: declared and effective plugins, custom plugins, platform configs, permissions, name, transition and installed framework version. This is the running MCP process configuration, not a fresh read of edited PHP files or proof of the compiled binary. Reconnect MCP after editing AppServiceProvider.';
    }

    public function inputSchema(): array
    {
        // No arguments: bare object schema — never embed an empty stdClass
        // (PHP cache serialization can corrupt it into __PHP_Incomplete_Class).
        return [
            'type' => 'object',
            'additionalProperties' => false,
        ];
    }

    public function run(array $args): string
    {
        if ($args !== []) {
            throw new \InvalidArgumentException('project_state does not accept arguments.');
        }
        $declared = ShellConfig::getDeclaredPlugins();
        $names = static fn (array $plugins): array => array_values(array_map(fn (Plugin $p) => $p->value, $plugins));
        $configs = ShellConfig::getAppConfigs();
        $versions = [];
        $platformStatus = [];
        foreach (['desktop', 'android', 'ios'] as $platform) {
            $config = $configs[$platform] ?? [];
            $missing = array_values(array_filter(['version', 'buildNumber'], fn ($key) => !isset($config[$key])));
            $versions[$platform] = $missing === [] ? ShellConfig::getVersion($platform) : null;
            $platformStatus[$platform] = [
                'configured' => array_key_exists($platform, $configs),
                'missing_version_fields' => $missing,
            ];
        }

        $payload = [
            'nativeblade_version' => $this->packageVersion(),
            'project' => [
                'base_path' => base_path(),
                'name' => ShellConfig::getName(),
            ],
            'configuration_source' => [
                'provider' => app_path('Providers/AppServiceProvider.php'),
                'scope' => 'ShellConfig values registered in the running Laravel process by application and package providers.',
                'reads_provider_from_disk_on_each_call' => false,
                'refresh' => 'Reconnect the MCP client after editing provider code or environment values. Clear Laravel config cache if applicable before reconnecting. Configuration guarded out of console execution will not be registered here.',
                'binary_verified' => false,
            ],
            'plugins' => [
                'declared' => $declared === null ? null : $names($declared),
                'effective' => $names(PluginRegistry::resolve($declared)),
                'always_on' => $names(PluginRegistry::alwaysOn()),
                'all_available' => $names(Plugin::cases()),
                'mode' => $declared === null
                    ? 'all-included (no plugins() call in AppServiceProvider)'
                    : 'explicit (declared plugins plus always-on core plugins)',
            ],
            'custom_plugins' => array_map(fn ($plugin) => [
                'feature' => $plugin->feature,
                'crate' => $plugin->feature_crate,
                'version' => $plugin->version,
                'path' => $plugin->path,
                'android_permissions' => $plugin->android_permissions,
                'ios_plist' => $plugin->ios_plist,
                'capabilities' => $plugin->capabilities,
                'mobile_capabilities' => $plugin->mobile_capabilities,
            ], ShellConfig::getCustomPlugins()),
            'transition' => ShellConfig::getTransition(),
            'app_configs' => $configs,
            'versions' => $versions,
            'platform_status' => $platformStatus,
            'diagnostics' => $configs === []
                ? ['No app configuration was registered. Verify the project base path and provider boot logic. With no plugins declaration, the framework includes all built-in plugins.']
                : [],
        ];

        return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function packageVersion(): string
    {
        if (InstalledVersions::isInstalled('nativeblade/nativeblade')) {
            $version = InstalledVersions::getPrettyVersion('nativeblade/nativeblade');
            if ($version !== null) {
                return $version;
            }
        }
        $composerJson = dirname(__DIR__, 3) . '/composer.json';
        if (is_file($composerJson)) {
            $data = json_decode((string) file_get_contents($composerJson), true);
            if (is_array($data) && isset($data['version']) && is_string($data['version'])) {
                return $data['version'];
            }
        }
        return 'dev';
    }
}
