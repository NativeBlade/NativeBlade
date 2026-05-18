<?php

namespace NativeBlade\Mcp\Tools;

use NativeBlade\Config\Plugin;
use NativeBlade\Facades\NativeBladeConfig;
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
        return 'Inspect the current NativeBlade configuration of the project: declared plugins, per-platform configs (android, ios, desktop), permissions, default page transition, framework version. Call this to know what is actually installed before suggesting code that depends on a plugin or platform.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => new \stdClass(),
        ];
    }

    public function run(array $args): string
    {
        $declared = ShellConfig::getDeclaredPlugins();
        $allCases = array_map(fn (Plugin $p) => $p->value, Plugin::cases());

        $payload = [
            'nativeblade_version' => $this->packageVersion(),
            'plugins' => [
                'declared' => $declared,
                'all_available' => $allCases,
                'mode' => $declared === null
                    ? 'all-included (no plugins() call in AppServiceProvider)'
                    : 'explicit (only declared plugins ship in the binary)',
            ],
            'transition' => $this->safe(fn () => NativeBladeConfig::getTransition()),
            'app_configs' => $this->safe(fn () => ShellConfig::getAppConfigs()) ?? [],
            'versions' => $this->collectVersions(),
        ];

        return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return array<string, mixed>
     */
    private function collectVersions(): array
    {
        $out = [];
        foreach (['desktop', 'android', 'ios'] as $platform) {
            $out[$platform] = $this->safe(fn () => ShellConfig::getVersion($platform));
        }
        return $out;
    }

    private function safe(\Closure $fn): mixed
    {
        try {
            return $fn();
        } catch (\Throwable) {
            return null;
        }
    }

    private function packageVersion(): string
    {
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
