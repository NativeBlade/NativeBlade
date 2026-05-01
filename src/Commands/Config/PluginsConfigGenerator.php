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
 * - AndroidManifest.xml: <uses-permission> entries between markers
 * - Info.plist: usage description keys between markers
 *
 * Marker convention: NativeBlade owns ONLY the region between
 * `nativeblade:plugins:start` and `nativeblade:plugins:end`. Anything
 * outside the markers is preserved as-is — including manual additions
 * for third-party Tauri plugins, custom permissions, or app-specific
 * configuration.
 *
 * Optional plugin crates live in the user's Cargo.toml as `optional = true`
 * deps. A Cargo feature gates each one. When the feature is off, Cargo
 * doesn't pull in or compile the crate, so the binary contains zero
 * traces of unused plugin APIs — what App Store / Play Store reviewers scan for.
 */
class PluginsConfigGenerator
{
    private const START_HASH = '# nativeblade:plugins:start';
    private const END_HASH = '# nativeblade:plugins:end';
    private const START_SLASH = '// nativeblade:plugins:start';
    private const END_SLASH = '// nativeblade:plugins:end';
    private const START_XML = '<!-- nativeblade:plugins:start -->';
    private const END_XML = '<!-- nativeblade:plugins:end -->';

    private const ANDROID_PERMISSION_MAP = [
        'camera' => 'CAMERA',
        'location' => 'ACCESS_FINE_LOCATION',
        'location_coarse' => 'ACCESS_COARSE_LOCATION',
        'microphone' => 'RECORD_AUDIO',
        'storage' => 'READ_EXTERNAL_STORAGE',
        'storage_write' => 'WRITE_EXTERNAL_STORAGE',
        'notifications' => 'POST_NOTIFICATIONS',
        'vibrate' => 'VIBRATE',
        'biometric' => 'USE_BIOMETRIC',
        'nfc' => 'NFC',
        'bluetooth' => 'BLUETOOTH_CONNECT',
    ];

    private const IOS_PERMISSION_MAP = [
        'camera' => 'NSCameraUsageDescription',
        'location' => 'NSLocationWhenInUseUsageDescription',
        'location_always' => 'NSLocationAlwaysUsageDescription',
        'microphone' => 'NSMicrophoneUsageDescription',
        'photos' => 'NSPhotoLibraryUsageDescription',
        'photos_add' => 'NSPhotoLibraryAddUsageDescription',
        'biometric' => 'NSFaceIDUsageDescription',
        'nfc' => 'NFCReaderUsageDescription',
        'contacts' => 'NSContactsUsageDescription',
        'calendar' => 'NSCalendarsUsageDescription',
        'bluetooth' => 'NSBluetoothAlwaysUsageDescription',
    ];

    public function __construct(private Command $cmd) {}

    /**
     * @param  Plugin[]              $plugins
     * @param  array<string, mixed>  $androidConfig  AndroidConfig::toArray() output
     * @param  array<string, mixed>  $iosConfig      IosConfig::toArray() output
     */
    public function generate(array $plugins, array $androidConfig = [], array $iosConfig = []): void
    {
        $this->generateCargoToml($plugins);
        $this->generateLibRs($plugins);
        $this->generateCapabilities($plugins);
        $this->generatePackageJson($plugins);
        $this->generateAndroidManifest($plugins, $androidConfig);
        $this->generateInfoPlist($plugins, $iosConfig);
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

    /**
     * @param  Plugin[]  $plugins
     */
    private function generateAndroidManifest(array $plugins, array $androidConfig): void
    {
        $path = base_path('src-tauri/gen/android/app/src/main/AndroidManifest.xml');
        if (!file_exists($path)) return;

        $perms = [];
        foreach ($plugins as $plugin) {
            $d = PluginRegistry::descriptor($plugin);
            foreach ($d['android_permissions'] ?? [] as $perm) {
                $perms[$perm] = true;
            }
        }

        foreach (array_keys($androidConfig['permissions'] ?? []) as $key) {
            $mapped = self::ANDROID_PERMISSION_MAP[$key] ?? null;
            if ($mapped) $perms[$mapped] = true;
        }

        $perms['INTERNET'] = true;
        $perms['ACCESS_NETWORK_STATE'] = true;

        ksort($perms);

        $lines = ['    ' . self::START_XML];
        foreach (array_keys($perms) as $perm) {
            $lines[] = '    <uses-permission android:name="android.permission.' . $perm . '" />';
        }
        $lines[] = '    ' . self::END_XML;

        $newBlock = implode("\n", $lines);
        $manifest = file_get_contents($path);
        $manifest = $this->replaceXmlBlockBeforeApplication($manifest, $newBlock);

        file_put_contents($path, $manifest);
        $this->cmd->line("  <fg=green>✓</> AndroidManifest.xml: " . count($perms) . " permissions");
    }

    /**
     * @param  Plugin[]  $plugins
     */
    private function generateInfoPlist(array $plugins, array $iosConfig): void
    {
        $path = $this->findInfoPlist();
        if (!$path) return;

        $userTexts = [];
        foreach ($iosConfig['permissions'] ?? [] as $key => $description) {
            $plistKey = self::IOS_PERMISSION_MAP[$key] ?? null;
            if ($plistKey && is_string($description)) $userTexts[$plistKey] = $description;
        }

        $keys = [];
        foreach ($plugins as $plugin) {
            $d = PluginRegistry::descriptor($plugin);
            foreach ($d['ios_plist'] ?? [] as $key) {
                $keys[$key] = $userTexts[$key] ?? $this->defaultPlistText($key);
            }
        }
        foreach ($userTexts as $key => $text) {
            $keys[$key] = $text;
        }

        ksort($keys);

        $lines = ['    ' . self::START_XML];
        foreach ($keys as $key => $text) {
            $escaped = htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $lines[] = "    <key>{$key}</key>";
            $lines[] = "    <string>{$escaped}</string>";
        }
        $lines[] = '    ' . self::END_XML;
        $newBlock = implode("\n", $lines);

        $plist = file_get_contents($path);
        $plist = $this->replacePlistBlock($plist, $newBlock);

        file_put_contents($path, $plist);
        $this->cmd->line("  <fg=green>✓</> Info.plist: " . count($keys) . " usage descriptions");
    }

    private function defaultPlistText(string $key): string
    {
        return match ($key) {
            'NSCameraUsageDescription' => 'Take photos',
            'NSPhotoLibraryUsageDescription' => 'Access your photo library',
            'NSPhotoLibraryAddUsageDescription' => 'Save photos to your library',
            'NSMicrophoneUsageDescription' => 'Record audio',
            'NSLocationWhenInUseUsageDescription' => 'Use your location',
            'NSLocationAlwaysUsageDescription' => 'Use your location in the background',
            'NSFaceIDUsageDescription' => 'Authenticate using Face ID',
            'NFCReaderUsageDescription' => 'Read NFC tags',
            'NSContactsUsageDescription' => 'Access your contacts',
            'NSCalendarsUsageDescription' => 'Access your calendar',
            'NSBluetoothAlwaysUsageDescription' => 'Connect to Bluetooth devices',
            default => 'Required by app feature',
        };
    }

    private function replaceXmlBlockBeforeApplication(string $manifest, string $newBlock): string
    {
        $startQ = preg_quote(self::START_XML, '/');
        $endQ = preg_quote(self::END_XML, '/');
        $pattern = "/[ \t]*{$startQ}.*?{$endQ}/s";

        if (preg_match($pattern, $manifest)) {
            return preg_replace($pattern, $newBlock, $manifest);
        }

        $stripped = preg_replace('/\s*<uses-permission[^>]*\/?>/', '', $manifest);
        return preg_replace('/(\s*)(<application)/', "\n\n{$newBlock}\n\n    $2", $stripped, 1);
    }

    private function replacePlistBlock(string $plist, string $newBlock): string
    {
        $startQ = preg_quote(self::START_XML, '/');
        $endQ = preg_quote(self::END_XML, '/');
        $pattern = "/[ \t]*{$startQ}.*?{$endQ}/s";

        if (preg_match($pattern, $plist)) {
            return preg_replace($pattern, $newBlock, $plist);
        }

        return preg_replace('/(\s*)(<\/dict>)/', "\n{$newBlock}\n$2", $plist, 1);
    }

    private function findInfoPlist(): ?string
    {
        $dir = base_path('src-tauri/gen/apple');
        if (!is_dir($dir)) return null;

        $found = glob($dir . '/*/Info.plist');
        return $found[0] ?? null;
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
