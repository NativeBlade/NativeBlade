<?php

namespace NativeBlade\Commands\Config;

use Illuminate\Console\Command;

class IosConfigGenerator
{
    private const START_XML = '<!-- nativeblade:config:start -->';
    private const END_XML = '<!-- nativeblade:config:end -->';

    /** Keys NativeBlade owns; infoPlist() may not override them. */
    private const RESERVED_KEYS = [
        'UISupportedInterfaceOrientations',
        'UIStatusBarStyle',
        'UIViewControllerBasedStatusBarAppearance',
        'UIStatusBarHidden',
        'MinimumOSVersion',
        'FIREBASE_ANALYTICS_COLLECTION_ENABLED',
        'GADApplicationIdentifier',
        'NSUserTrackingUsageDescription',
        'SKAdNetworkItems',
        'CFBundleName',
        'CFBundleDisplayName',
        'CFBundleShortVersionString',
        'CFBundleVersion',
    ];

    public function __construct(private Command $cmd) {}

    public function generate(array $config): void
    {
        $this->generateAppName();
        $this->generatePlistConfig($config);
        $this->generateVersion($config);
        $this->generatePrivacyManifest($config);
        $this->generateSplash($config);
        $this->generateFirebase();
    }

    /**
     * Copy GoogleService-Info.plist into the iOS project root so Firebase
     * (Analytics) can find it in the app bundle. Unlike Android, iOS has no
     * auto-init plugin: the file must also be referenced by the Xcode project
     * to ship in the .app, and FirebaseApp.configure() must run at launch
     * (handled lazily inside the analytics plugin's Swift).
     */
    private function generateFirebase(): void
    {
        $plist = \NativeBlade\ShellConfig::getAppConfigs()['firebase']['plist'] ?? null;
        if (!$plist) return;

        if (!file_exists($plist)) {
            $this->cmd->line("  <fg=yellow>→</> GoogleService-Info.plist not found at {$plist}");
            return;
        }

        $appleDir = base_path('src-tauri/gen/apple');
        if (!is_dir($appleDir)) {
            $this->cmd->line("  <fg=yellow>→</> src-tauri/gen/apple missing — run 'nativeblade:add ios' first");
            return;
        }

        copy($plist, $appleDir . '/GoogleService-Info.plist');
        $this->cmd->line("  <fg=green>✓</> GoogleService-Info.plist copied to iOS project root");

        $this->registerPlistInXcodeBundle($appleDir);
    }

    /**
     * Add GoogleService-Info.plist to the app target's Copy Bundle Resources.
     *
     * Android finds google-services.json by convention; iOS has none, so the
     * plist only ships in the .app when the Xcode project references it. The
     * pbxproj format is brittle to edit by hand, so this drives the xcodeproj
     * Ruby gem (bundled with CocoaPods, present in every Tauri iOS toolchain).
     * Idempotent: re-runs reuse the existing reference. On any failure it
     * degrades to a manual hint instead of breaking the config run.
     */
    private function registerPlistInXcodeBundle(string $appleDir): void
    {
        $projects = glob($appleDir . '/*.xcodeproj');
        if (empty($projects)) {
            $this->cmd->line("  <fg=yellow>→</> No .xcodeproj found — skipping Firebase resource registration");
            return;
        }

        $ruby = <<<'RUBY'
        require 'xcodeproj'
        project = Xcodeproj::Project.open(ARGV[0])
        target  = project.targets.find { |t| t.name.end_with?('_iOS') } || project.targets.first
        name = 'GoogleService-Info.plist'
        ref  = project.files.find { |f| f.path == name } || project.main_group.new_file(name)
        target.resources_build_phase.add_file_reference(ref, true)
        project.save
        RUBY;

        $scriptPath = $appleDir . '/.nativeblade-firebase-resource.rb';
        file_put_contents($scriptPath, $ruby);

        exec(
            sprintf('ruby %s %s 2>&1', escapeshellarg($scriptPath), escapeshellarg($projects[0])),
            $output,
            $code
        );
        @unlink($scriptPath);

        if ($code === 0) {
            $this->cmd->line("  <fg=green>✓</> GoogleService-Info.plist registered in Xcode bundle resources");
            return;
        }

        $this->cmd->line("  <fg=yellow>→</> Could not auto-register GoogleService-Info.plist: " . trim(implode(' ', $output)));
        $this->cmd->line("  <fg=yellow>→</> Add it to the app target's 'Copy Bundle Resources' in Xcode manually");
    }

    private function generateAppName(): void
    {
        $name = \NativeBlade\ShellConfig::getName();
        if ($name === null) return;

        $plistPath = $this->findPlist();
        if (!$plistPath) return;

        $plist = file_get_contents($plistPath);
        $escaped = htmlspecialchars($name, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        $plist = $this->setPlistValue($plist, 'CFBundleName', $escaped);
        $plist = $this->setPlistValue($plist, 'CFBundleDisplayName', $escaped);

        file_put_contents($plistPath, $plist);
        $this->cmd->line("  <fg=green>✓</> iOS app name: {$name}");
    }

    /**
     * Wraps every key NativeBlade owns in Info.plist (orientation, status
     * bar, fullscreen, minimum version) inside `<!-- nativeblade:config -->`
     * markers. Keys are regenerated from the config every run, so removing
     * a config entry in PHP cleanly removes it from the plist.
     */
    private function generatePlistConfig(array $config): void
    {
        $plistPath = $this->findPlist();
        if (!$plistPath) return;

        $entries = [];

        if (isset($config['orientation'])) {
            $orientations = match ($config['orientation']) {
                'portrait' => ['UIInterfaceOrientationPortrait'],
                'landscape' => ['UIInterfaceOrientationLandscapeLeft', 'UIInterfaceOrientationLandscapeRight'],
                default => ['UIInterfaceOrientationPortrait', 'UIInterfaceOrientationLandscapeLeft', 'UIInterfaceOrientationLandscapeRight'],
            };
            $items = implode("\n", array_map(fn($o) => "        <string>{$o}</string>", $orientations));
            $entries[] = "    <key>UISupportedInterfaceOrientations</key>";
            $entries[] = "    <array>";
            $entries[] = $items;
            $entries[] = "    </array>";
        }

        if (isset($config['statusBar'])) {
            $style = ($config['statusBar']['style'] ?? 'dark') === 'light'
                ? 'UIStatusBarStyleLightContent'
                : 'UIStatusBarStyleDefault';
            $entries[] = "    <key>UIStatusBarStyle</key>";
            $entries[] = "    <string>{$style}</string>";
            $entries[] = "    <key>UIViewControllerBasedStatusBarAppearance</key>";
            $entries[] = "    <false/>";
        }

        if (isset($config['fullscreen'])) {
            $value = $config['fullscreen'] ? 'true' : 'false';
            $entries[] = "    <key>UIStatusBarHidden</key>";
            $entries[] = "    <{$value}/>";
        }

        if (isset($config['minIosVersion'])) {
            $entries[] = "    <key>MinimumOSVersion</key>";
            $entries[] = "    <string>{$config['minIosVersion']}</string>";
        }

        $analytics = \NativeBlade\ShellConfig::getAppConfigs()['analytics'] ?? null;
        if ($analytics !== null) {
            $value = ($analytics['collectionEnabledByDefault'] ?? true) ? 'true' : 'false';
            $entries[] = "    <key>FIREBASE_ANALYTICS_COLLECTION_ENABLED</key>";
            $entries[] = "    <{$value}/>";
        }

        $admob = \NativeBlade\ShellConfig::getAppConfigs()['admob'] ?? null;
        if ($admob !== null && !empty($admob['iosAppId'])) {
            $appId = htmlspecialchars((string) $admob['iosAppId'], ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $tracking = htmlspecialchars(
                (string) ($admob['trackingDescription'] ?? 'Your data will be used to deliver personalized ads.'),
                ENT_XML1 | ENT_QUOTES,
                'UTF-8'
            );
            $entries[] = "    <key>GADApplicationIdentifier</key>";
            $entries[] = "    <string>{$appId}</string>";
            $entries[] = "    <key>NSUserTrackingUsageDescription</key>";
            $entries[] = "    <string>{$tracking}</string>";
            // The Google network is required for SKAdNetwork attribution; the
            // SDK contributes the rest via its own bundled Info.plist.
            $entries[] = "    <key>SKAdNetworkItems</key>";
            $entries[] = "    <array>";
            $entries[] = "        <dict>";
            $entries[] = "            <key>SKAdNetworkIdentifier</key>";
            $entries[] = "            <string>cstr6suwn9.skadnetwork</string>";
            $entries[] = "        </dict>";
            $entries[] = "    </array>";
        }

        $customApplied = 0;
        foreach ($config['infoPlist'] ?? [] as $key => $value) {
            if (in_array($key, self::RESERVED_KEYS, true)) {
                $this->cmd->line("  <fg=yellow>→</> iOS Info.plist: ignoring '{$key}' (managed by NativeBlade — use the dedicated config method)");
                continue;
            }
            $escapedKey = htmlspecialchars((string) $key, ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $entries[] = "    <key>{$escapedKey}</key>";
            $entries[] = $this->plistValue($value, 1);
            $customApplied++;
        }

        $body = empty($entries) ? '' : "\n" . implode("\n", $entries);
        $newBlock = "    " . self::START_XML . $body . "\n    " . self::END_XML;

        $plist = file_get_contents($plistPath);
        $plist = $this->stripLegacyKeys($plist);
        $plist = $this->upsertConfigBlock($plist, $newBlock);

        file_put_contents($plistPath, $plist);

        $count = count(array_filter([
            isset($config['orientation']),
            isset($config['statusBar']),
            isset($config['fullscreen']),
            isset($config['minIosVersion']),
        ]));
        $suffix = $customApplied > 0 ? " (+{$customApplied} custom)" : '';
        $this->cmd->line("  <fg=green>✓</> iOS Info.plist: {$count} config entries{$suffix}");
    }

    /**
     * Serialize an arbitrary PHP value into indented plist XML. Supports
     * strings, booleans, integers, floats, lists (`<array>`) and associative
     * arrays (`<dict>`). `$depth` is measured in 4-space units.
     */
    private function plistValue(mixed $value, int $depth): string
    {
        $pad = str_repeat('    ', $depth);

        if (is_bool($value)) {
            return $pad . ($value ? '<true/>' : '<false/>');
        }
        if (is_int($value)) {
            return $pad . "<integer>{$value}</integer>";
        }
        if (is_float($value)) {
            return $pad . "<real>{$value}</real>";
        }
        if (is_array($value)) {
            $lines = [];
            if (array_is_list($value)) {
                $lines[] = $pad . '<array>';
                foreach ($value as $item) {
                    $lines[] = $this->plistValue($item, $depth + 1);
                }
                $lines[] = $pad . '</array>';
            } else {
                $lines[] = $pad . '<dict>';
                foreach ($value as $k => $v) {
                    $key = htmlspecialchars((string) $k, ENT_XML1 | ENT_QUOTES, 'UTF-8');
                    $lines[] = $pad . '    <key>' . $key . '</key>';
                    $lines[] = $this->plistValue($v, $depth + 1);
                }
                $lines[] = $pad . '</dict>';
            }
            return implode("\n", $lines);
        }

        $escaped = htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        return $pad . "<string>{$escaped}</string>";
    }

    private function stripLegacyKeys(string $plist): string
    {
        $keys = [
            'UISupportedInterfaceOrientations',
            'UIStatusBarStyle',
            'UIViewControllerBasedStatusBarAppearance',
            'UIStatusBarHidden',
            'MinimumOSVersion',
        ];

        $startQ = preg_quote(self::START_XML, '/');
        $endQ = preg_quote(self::END_XML, '/');
        if (preg_match("/{$startQ}.*?{$endQ}/s", $plist)) {
            return $plist;
        }

        foreach ($keys as $key) {
            $plist = preg_replace(
                "/\\s*<key>{$key}<\\/key>\\s*<(?:string>[^<]*<\\/string|true\\/|false\\/|array>.*?<\\/array)>/s",
                '',
                $plist
            );
        }
        return $plist;
    }

    private function upsertConfigBlock(string $plist, string $newBlock): string
    {
        $startQ = preg_quote(self::START_XML, '/');
        $endQ = preg_quote(self::END_XML, '/');
        $pattern = "/[ \t]*{$startQ}.*?{$endQ}/s";

        if (preg_match($pattern, $plist)) {
            return preg_replace($pattern, $newBlock, $plist);
        }

        return preg_replace('/(\s*)(<\/dict>)/', "\n{$newBlock}\n$2", $plist, 1);
    }

    private function generateVersion(array $config): void
    {
        if (!isset($config['version']) || !isset($config['buildNumber'])) return;

        $plistPath = $this->findPlist();
        if (!$plistPath) return;

        $plist = file_get_contents($plistPath);
        $plist = $this->setPlistValue($plist, 'CFBundleShortVersionString', $config['version']);
        $plist = $this->setPlistValue($plist, 'CFBundleVersion', (string) $config['buildNumber']);
        file_put_contents($plistPath, $plist);

        $this->cmd->line("  <fg=green>✓</> iOS version: {$config['version']} ({$config['buildNumber']})");
    }

    private function generatePrivacyManifest(array $config): void
    {
        $apis = $config['privacyManifest'] ?? [];
        if (empty($apis)) return;

        $plistPath = $this->findPlist();
        if (!$plistPath) return;

        $appDir = dirname($plistPath);
        $apiEntries = '';

        foreach ($apis as $apiType => $reasons) {
            if (!is_array($reasons)) $reasons = [$reasons];
            $reasonEntries = '';
            foreach ($reasons as $reason) {
                $reasonEntries .= "\t\t\t\t<dict>\n\t\t\t\t\t<key>NSPrivacyAccessedAPITypeReasons</key>\n\t\t\t\t\t<string>{$reason}</string>\n\t\t\t\t</dict>\n";
            }
            $apiEntries .= <<<XML
            <dict>
                <key>NSPrivacyAccessedAPIType</key>
                <string>{$apiType}</string>
                <key>NSPrivacyAccessedAPITypeReasons</key>
                <array>
{$reasonEntries}            </array>
            </dict>

XML;
        }

        $manifest = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
    <key>NSPrivacyTracking</key>
    <false/>
    <key>NSPrivacyTrackingDomains</key>
    <array/>
    <key>NSPrivacyCollectedDataTypes</key>
    <array/>
    <key>NSPrivacyAccessedAPITypes</key>
    <array>
{$apiEntries}    </array>
</dict>
</plist>
XML;

        file_put_contents($appDir . '/PrivacyInfo.xcprivacy', $manifest);
        $this->cmd->line("  <fg=green>✓</> iOS PrivacyInfo.xcprivacy (" . count($apis) . " APIs)");
    }

    private function generateSplash(array $config): void
    {
        $color = $config['splashBackground'] ?? null;
        if (!$color) return;

        $plistPath = $this->findPlist();
        if (!$plistPath) return;

        $storyboardPath = dirname($plistPath) . '/Base.lproj/LaunchScreen.storyboard';
        if (!file_exists($storyboardPath)) return;

        $hex = ltrim($color, '#');
        $r = round(hexdec(substr($hex, 0, 2)) / 255, 4);
        $g = round(hexdec(substr($hex, 2, 2)) / 255, 4);
        $b = round(hexdec(substr($hex, 4, 2)) / 255, 4);

        $storyboard = file_get_contents($storyboardPath);

        if (preg_match('/<color key="backgroundColor"/', $storyboard)) {
            $storyboard = preg_replace(
                '/<color key="backgroundColor"[^\/]*\/>/',
                '<color key="backgroundColor" red="' . $r . '" green="' . $g . '" blue="' . $b . '" alpha="1" colorSpace="custom" customColorSpace="sRGB"/>',
                $storyboard
            );
        }

        file_put_contents($storyboardPath, $storyboard);
        $this->cmd->line("  <fg=green>✓</> iOS splash: {$color}");
    }

    private function findPlist(): ?string
    {
        $plistDir = base_path('src-tauri/gen/apple');
        if (!is_dir($plistDir)) return null;

        $found = glob($plistDir . '/*/Info.plist');
        return $found[0] ?? null;
    }

    private function setPlistValue(string $plist, string $key, string $value): string
    {
        if (str_contains($plist, "<key>{$key}</key>")) {
            return preg_replace(
                "/<key>{$key}<\\/key>\\s*<string>[^<]*<\\/string>/",
                "<key>{$key}</key>\n\t<string>{$value}</string>",
                $plist
            );
        }

        return str_replace('</dict>', "<key>{$key}</key>\n\t<string>{$value}</string>\n</dict>", $plist);
    }
}
