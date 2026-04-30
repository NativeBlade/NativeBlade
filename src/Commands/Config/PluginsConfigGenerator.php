<?php

namespace NativeBlade\Commands\Config;

use Illuminate\Console\Command;
use NativeBlade\Config\Plugin;
use NativeBlade\Config\PluginRegistry;

/**
 * Rewrites every file that depends on the declared plugin set:
 * - src-tauri/Cargo.toml: [features] section between markers
 * - src-tauri/src/lib.rs: optional plugin init chain between markers
 * - src-tauri/capabilities/default.json + mobile.json: permissions arrays
 * - package.json: only @tauri-apps/plugin-* deps that are actually used
 *
 * Optional plugin crates live in the user's Cargo.toml as `optional = true`
 * deps. A Cargo feature gates each one. When the feature is off, Cargo
 * doesn't pull in or compile the crate, so the binary contains zero
 * traces of unused plugin APIs — what App Store / Play Store reviewers scan for.
 *
 * AndroidManifest.xml and Info.plist are handled by their existing
 * platform generators — they own permission text from
 * AndroidConfig::permissions() / IosConfig::permissions().
 */
class PluginsConfigGenerator
{
    private const START_HASH = '# nativeblade:plugins:start';
    private const END_HASH = '# nativeblade:plugins:end';
    private const START_SLASH = '// nativeblade:plugins:start';
    private const END_SLASH = '// nativeblade:plugins:end';

    public function __construct(private Command $cmd) {}

    /**
     * @param  Plugin[]  $plugins
     */
    public function generate(array $plugins): void
    {
        $this->generateCargoToml($plugins);
        $this->generateLibRs($plugins);
        $this->generateCapabilities($plugins);
        $this->generatePackageJson($plugins);
    }

    /**
     * @param  Plugin[]  $plugins
     */
    private function generateCargoToml(array $plugins): void
    {
        $path = base_path('src-tauri/Cargo.toml');
        if (!file_exists($path)) return;

        $content = file_get_contents($path);

        $featureLines = [];
        foreach ($plugins as $plugin) {
            $d = PluginRegistry::descriptor($plugin);
            if (!isset($d['feature'])) continue;
            $crate = $d['feature_crate'] ?? null;
            if ($crate === null) continue;
            $featureLines[$d['feature']] = "{$d['feature']} = [\"dep:{$crate}\"]";
        }
        ksort($featureLines);

        $lines = [
            self::START_HASH,
            '[features]',
            'default = ["custom-protocol"]',
            'custom-protocol = ["tauri/custom-protocol"]',
        ];
        foreach ($featureLines as $line) $lines[] = $line;
        $lines[] = self::END_HASH;

        $newBlock = implode("\n", $lines);

        $content = $this->replaceBlock($content, self::START_HASH, self::END_HASH, $newBlock);

        file_put_contents($path, $content);
        $this->cmd->line("  <fg=green>✓</> Cargo.toml features: " . (empty($featureLines) ? '(none)' : implode(', ', array_keys($featureLines))));
    }

    /**
     * @param  Plugin[]  $plugins
     */
    private function generateLibRs(array $plugins): void
    {
        $path = base_path('src-tauri/src/lib.rs');
        if (!file_exists($path)) return;

        $content = file_get_contents($path);

        $blocks = [];
        foreach ($plugins as $plugin) {
            $d = PluginRegistry::descriptor($plugin);
            if (!isset($d['rust_init']) || !isset($d['feature'])) continue;
            $cfg = $d['mobile_only'] ?? false
                ? "#[cfg(all(any(target_os = \"android\", target_os = \"ios\"), feature = \"{$d['feature']}\"))]"
                : "#[cfg(feature = \"{$d['feature']}\")]";
            $blocks[] = "    {$cfg}\n    let builder = builder.plugin({$d['rust_init']});";
        }

        $body = empty($blocks) ? '' : "\n\n" . implode("\n\n", $blocks);
        $newBlock = "    " . self::START_SLASH . $body . "\n    " . self::END_SLASH;

        $content = $this->replaceBlock($content, self::START_SLASH, self::END_SLASH, $newBlock);

        file_put_contents($path, $content);
        $this->cmd->line("  <fg=green>✓</> src-tauri/src/lib.rs");
    }

    /**
     * @param  Plugin[]  $plugins
     */
    private function generateCapabilities(array $plugins): void
    {
        $defaultPath = base_path('src-tauri/capabilities/default.json');
        $mobilePath = base_path('src-tauri/capabilities/mobile.json');

        $desktopPerms = ['core:default', 'core:event:default'];
        $mobilePerms = [];
        $allowedPrefixes = ['core', 'fs'];

        foreach ($plugins as $plugin) {
            $d = PluginRegistry::descriptor($plugin);
            foreach ($d['capabilities'] ?? [] as $perm) {
                $desktopPerms[] = $perm;
                $prefix = strtok($perm, ':');
                if ($prefix !== false && !in_array($prefix, $allowedPrefixes, true)) {
                    $allowedPrefixes[] = $prefix;
                }
            }
            foreach ($d['mobile_capabilities'] ?? [] as $perm) $mobilePerms[] = $perm;
        }

        if (file_exists($defaultPath)) {
            $cap = json_decode(file_get_contents($defaultPath), true);
            $extras = $this->filterNonStringPerms($cap['permissions'] ?? [], $allowedPrefixes);
            $cap['permissions'] = array_values(array_unique([
                ...array_filter($desktopPerms),
                ...$extras,
            ], SORT_REGULAR));
            file_put_contents($defaultPath, json_encode($cap, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $this->cmd->line("  <fg=green>✓</> capabilities/default.json");
        }

        if (file_exists($mobilePath)) {
            $cap = json_decode(file_get_contents($mobilePath), true);
            $cap['permissions'] = array_values(array_unique($mobilePerms));
            file_put_contents($mobilePath, json_encode($cap, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $this->cmd->line("  <fg=green>✓</> capabilities/mobile.json");
        }
    }

    /**
     * Keep only non-string permissions (custom scoped objects like fs:scope)
     * whose plugin prefix matches a declared plugin. Drops orphaned ones
     * (e.g. `shell:allow-execute` when shell plugin isn't declared).
     */
    private function filterNonStringPerms(array $permissions, array $allowedPrefixes): array
    {
        return array_values(array_filter($permissions, function ($p) use ($allowedPrefixes) {
            if (is_string($p)) return false;
            $id = $p['identifier'] ?? null;
            if (!is_string($id)) return false;
            $prefix = strtok($id, ':');
            return $prefix !== false && in_array($prefix, $allowedPrefixes, true);
        }));
    }

    /**
     * @param  Plugin[]  $plugins
     */
    private function generatePackageJson(array $plugins): void
    {
        $path = base_path('package.json');
        if (!file_exists($path)) return;

        $pkg = json_decode(file_get_contents($path), true);
        if (!isset($pkg['dependencies'])) return;

        $required = [
            '@tauri-apps/api' => '^2',
            '@tauri-apps/cli' => '^2',
        ];
        foreach ($plugins as $plugin) {
            $d = PluginRegistry::descriptor($plugin);
            foreach ($d['npm'] ?? [] as $pkgName => $version) {
                $required[$pkgName] = $version;
            }
        }

        $newDeps = [];
        foreach ($pkg['dependencies'] as $name => $version) {
            if (!str_starts_with($name, '@tauri-apps/')) {
                $newDeps[$name] = $version;
            }
        }
        foreach ($required as $name => $version) {
            $newDeps[$name] = $version;
        }
        ksort($newDeps);
        $pkg['dependencies'] = $newDeps;

        file_put_contents($path, json_encode($pkg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        $this->cmd->line("  <fg=green>✓</> package.json deps");
    }

    private function replaceBlock(string $content, string $start, string $end, string $newBlock): string
    {
        $startQ = preg_quote($start, '/');
        $endQ = preg_quote($end, '/');
        $pattern = "/[ \t]*{$startQ}.*?{$endQ}/s";

        if (preg_match($pattern, $content)) {
            return preg_replace($pattern, $newBlock, $content);
        }

        return rtrim($content) . "\n\n" . $newBlock . "\n";
    }
}
