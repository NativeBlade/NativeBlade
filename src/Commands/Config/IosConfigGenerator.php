<?php

namespace NativeBlade\Commands\Config;

use Illuminate\Console\Command;

class IosConfigGenerator
{
    private const START_XML = '<!-- nativeblade:config:start -->';
    private const END_XML = '<!-- nativeblade:config:end -->';

    public function __construct(private Command $cmd) {}

    public function generate(array $config): void
    {
        $this->generatePlistConfig($config);
        $this->generateVersion($config);
        $this->generatePrivacyManifest($config);
        $this->generateSplash($config);
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
        $this->cmd->line("  <fg=green>✓</> iOS Info.plist: {$count} config entries");
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
