<?php

namespace NativeBlade\Commands\Config;

use Illuminate\Console\Command;

class IosConfigGenerator
{
    private const PERMISSIONS = [
        'camera' => 'NSCameraUsageDescription',
        'location' => 'NSLocationWhenInUseUsageDescription',
        'location_always' => 'NSLocationAlwaysUsageDescription',
        'microphone' => 'NSMicrophoneUsageDescription',
        'photos' => 'NSPhotoLibraryUsageDescription',
        'photos_add' => 'NSPhotoLibraryAddUsageDescription',
        'notifications' => 'NSUserNotificationsUsageDescription',
        'biometric' => 'NSFaceIDUsageDescription',
        'nfc' => 'NFCReaderUsageDescription',
        'contacts' => 'NSContactsUsageDescription',
        'calendar' => 'NSCalendarsUsageDescription',
        'bluetooth' => 'NSBluetoothAlwaysUsageDescription',
    ];

    public function __construct(private Command $cmd) {}

    public function generate(array $config): void
    {
        $this->generateOrientation($config);
        $this->generateVersion($config);
        $this->generateStatusBar($config);
        $this->generateFullscreen($config);
        $this->generatePermissions($config);
        $this->generateMinVersion($config);
        $this->generatePrivacyManifest($config);
        $this->generateSplash($config);
    }

    private function generateOrientation(array $config): void
    {
        $orientation = $config['orientation'] ?? null;
        if (!$orientation) return;

        $plistPath = $this->findPlist();
        if (!$plistPath) return;

        $orientations = match ($orientation) {
            'portrait' => ['UIInterfaceOrientationPortrait'],
            'landscape' => ['UIInterfaceOrientationLandscapeLeft', 'UIInterfaceOrientationLandscapeRight'],
            default => ['UIInterfaceOrientationPortrait', 'UIInterfaceOrientationLandscapeLeft', 'UIInterfaceOrientationLandscapeRight'],
        };

        $plist = file_get_contents($plistPath);
        $orientationXml = "<array>\n" . implode("\n", array_map(fn($o) => "\t\t<string>{$o}</string>", $orientations)) . "\n\t</array>";

        if (str_contains($plist, 'UISupportedInterfaceOrientations')) {
            $plist = preg_replace(
                '/<key>UISupportedInterfaceOrientations<\/key>\s*<array>.*?<\/array>/s',
                "<key>UISupportedInterfaceOrientations</key>\n\t{$orientationXml}",
                $plist
            );
        } else {
            $plist = str_replace('</dict>', "<key>UISupportedInterfaceOrientations</key>\n\t{$orientationXml}\n</dict>", $plist);
        }

        file_put_contents($plistPath, $plist);
        $this->cmd->line("  <fg=green>✓</> iOS orientation: {$orientation}");
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

    private function generateStatusBar(array $config): void
    {
        $statusBar = $config['statusBar'] ?? null;
        if (!$statusBar) return;

        $plistPath = $this->findPlist();
        if (!$plistPath) return;

        $plist = file_get_contents($plistPath);
        $style = ($statusBar['style'] ?? 'dark') === 'light' ? 'UIStatusBarStyleLightContent' : 'UIStatusBarStyleDefault';

        $plist = $this->setPlistValue($plist, 'UIStatusBarStyle', $style);

        if (!str_contains($plist, 'UIViewControllerBasedStatusBarAppearance')) {
            $plist = str_replace('</dict>', "<key>UIViewControllerBasedStatusBarAppearance</key>\n\t<false/>\n</dict>", $plist);
        }

        file_put_contents($plistPath, $plist);
        $this->cmd->line("  <fg=green>✓</> iOS statusBar: {$statusBar['style']}");
    }

    private function generateFullscreen(array $config): void
    {
        if (!isset($config['fullscreen'])) return;

        $plistPath = $this->findPlist();
        if (!$plistPath) return;

        $plist = file_get_contents($plistPath);
        $value = $config['fullscreen'] ? 'true' : 'false';

        if (str_contains($plist, 'UIStatusBarHidden')) {
            $plist = preg_replace('/<key>UIStatusBarHidden<\/key>\s*<(true|false)\/>/', "<key>UIStatusBarHidden</key>\n\t<{$value}/>", $plist);
        } else {
            $plist = str_replace('</dict>', "<key>UIStatusBarHidden</key>\n\t<{$value}/>\n</dict>", $plist);
        }

        file_put_contents($plistPath, $plist);
        $this->cmd->line("  <fg=green>✓</> iOS fullscreen: {$value}");
    }

    private function generatePermissions(array $config): void
    {
        $permissions = $config['permissions'] ?? [];
        if (empty($permissions)) return;

        $plistPath = $this->findPlist();
        if (!$plistPath) return;

        $plist = file_get_contents($plistPath);

        foreach ($permissions as $key => $description) {
            $iosKey = self::PERMISSIONS[$key] ?? null;
            if (!$iosKey || !$description) continue;
            $plist = $this->setPlistValue($plist, $iosKey, $description);
        }

        file_put_contents($plistPath, $plist);
        $this->cmd->line("  <fg=green>✓</> iOS permissions: " . implode(', ', array_keys($permissions)));
    }

    private function generateMinVersion(array $config): void
    {
        if (!isset($config['minIosVersion'])) return;

        $plistPath = $this->findPlist();
        if (!$plistPath) return;

        $plist = file_get_contents($plistPath);
        $plist = $this->setPlistValue($plist, 'MinimumOSVersion', $config['minIosVersion']);
        file_put_contents($plistPath, $plist);

        $this->cmd->line("  <fg=green>✓</> iOS minimum version: {$config['minIosVersion']}");
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
