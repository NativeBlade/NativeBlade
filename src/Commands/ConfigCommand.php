<?php

namespace NativeBlade\Commands;

use Illuminate\Console\Command;
use NativeBlade\NativeBladeServiceProvider;
use NativeBlade\ShellConfig;
use Symfony\Component\Process\Process;

class ConfigCommand extends Command
{
    protected $signature = 'nativeblade:config';
    protected $description = 'Generate Tauri config from NativeBlade PHP config';

    public function handle(): int
    {
        app()->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

        $configs = ShellConfig::getAppConfigs();
        $desktop = $configs['desktop'] ?? [];
        $mobile = $configs['mobile'] ?? [];
        $android = $configs['android'] ?? [];
        $ios = $configs['ios'] ?? [];

        $this->generateTauriConf($desktop);
        $this->generateMenu($desktop);
        $this->generateTray($desktop);
        $this->generateAndroidTheme(array_merge($mobile, $android));
        $this->generateSplash($desktop, $mobile);

        $this->info('  Config generated from PHP.');
        return 0;
    }

    private function generateTauriConf(array $desktop): void
    {
        $confPath = base_path('src-tauri/tauri.conf.json');
        if (!file_exists($confPath)) return;

        $conf = json_decode(file_get_contents($confPath), true);

        if (isset($desktop['title'])) {
            $conf['productName'] = $desktop['title'];
            $conf['app']['windows'][0]['title'] = $desktop['title'];
        }
        if (isset($desktop['version'])) $conf['version'] = $desktop['version'];
        if (isset($desktop['identifier'])) $conf['identifier'] = $desktop['identifier'];
        if (isset($desktop['icon'])) {
            $iconSrc = base_path($desktop['icon']);
            if (file_exists($iconSrc)) {
                $conf['bundle']['icon'] = [
                    'icons/32x32.png',
                    'icons/128x128.png',
                    'icons/128x128@2x.png',
                    'icons/icon.icns',
                    'icons/icon.ico',
                ];
            }
        }
        if (isset($desktop['width'])) $conf['app']['windows'][0]['width'] = $desktop['width'];
        if (isset($desktop['height'])) $conf['app']['windows'][0]['height'] = $desktop['height'];
        if (isset($desktop['minWidth'])) $conf['app']['windows'][0]['minWidth'] = $desktop['minWidth'];
        if (isset($desktop['minHeight'])) $conf['app']['windows'][0]['minHeight'] = $desktop['minHeight'];
        if (isset($desktop['resizable'])) $conf['app']['windows'][0]['resizable'] = $desktop['resizable'];
        if (isset($desktop['fullscreen'])) $conf['app']['windows'][0]['fullscreen'] = $desktop['fullscreen'];

        file_put_contents($confPath, json_encode($conf, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->line("  <fg=green>✓</> tauri.conf.json updated");
    }

    private function generateMenu(array $desktop): void
    {
        $menu = $desktop['menu'] ?? [];
        if (empty($menu)) return;

        $path = base_path('src-tauri/menu.json');
        file_put_contents($path, json_encode($this->buildMenuItems($menu), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->line("  <fg=green>✓</> menu.json generated");
    }

    private function buildMenuItems(array $menu): array
    {
        $result = [];
        foreach ($menu as $label => $value) {
            if ($value === '---') {
                $result[] = ['separator' => true];
            } elseif (is_array($value)) {
                $result[] = ['label' => $label, 'items' => $this->buildMenuItems($value)];
            } else {
                $result[] = ['label' => $label, 'action' => $value];
            }
        }
        return $result;
    }

    private function generateTray(array $desktop): void
    {
        $tray = $desktop['tray'] ?? null;
        $hideOnClose = $desktop['hideOnClose'] ?? false;
        $trayMenu = $tray['menu'] ?? [];

        $hasIcon = false;
        $iconSrc = $tray['icon'] ?? '';
        if ($iconSrc) {
            $fullPath = base_path($iconSrc);
            if (file_exists($fullPath)) {
                copy($fullPath, base_path('src-tauri/icons/tray.png'));
                $hasIcon = true;
                $this->line("  <fg=green>✓</> Tray icon copied");
            }
        }

        $config = [
            'enabled' => $tray !== null,
            'tooltip' => $tray['tooltip'] ?? 'NativeBlade',
            'hideOnClose' => $hideOnClose,
            'customIcon' => $hasIcon,
            'menu' => !empty($trayMenu) ? $this->buildMenuItems($trayMenu) : [],
        ];

        $path = base_path('src-tauri/tray.json');
        file_put_contents($path, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->line("  <fg=green>✓</> tray.json generated");
    }

    private function generateAndroidTheme(array $config): void
    {
        $themePath = base_path('src-tauri/gen/android/app/src/main/res/values/themes.xml');
        if (!file_exists($themePath)) return;

        $themeName = $this->detectAndroidThemeName();
        $statusColor = $config['statusBar']['color'] ?? '#FF0A0A0A';
        $navColor = $config['navigationBar']['color'] ?? '#FF0A0A0A';
        $lightStatus = ($config['statusBar']['style'] ?? 'dark') === 'light' ? 'true' : 'false';

        if (!str_starts_with($statusColor, '#FF')) $statusColor = '#FF' . ltrim($statusColor, '#');
        if (!str_starts_with($navColor, '#FF')) $navColor = '#FF' . ltrim($navColor, '#');

        $xml = <<<XML
<resources xmlns:tools="http://schemas.android.com/tools">
    <style name="{$themeName}" parent="Theme.MaterialComponents.DayNight.NoActionBar">
        <item name="android:statusBarColor">{$statusColor}</item>
        <item name="android:navigationBarColor">{$navColor}</item>
        <item name="android:windowLightStatusBar" tools:targetApi="23">{$lightStatus}</item>
    </style>
</resources>
XML;

        file_put_contents($themePath, $xml);

        $nightPath = str_replace('/values/', '/values-night/', $themePath);
        if (file_exists($nightPath)) file_put_contents($nightPath, $xml);

        $this->line("  <fg=green>✓</> Android theme updated");
    }

    private function detectAndroidThemeName(): string
    {
        $manifestPath = base_path('src-tauri/gen/android/app/src/main/AndroidManifest.xml');
        if (!file_exists($manifestPath)) return 'Theme.nativeblade';

        $manifest = file_get_contents($manifestPath);
        if (preg_match('/android:theme="@style\/([^"]+)"/', $manifest, $matches)) {
            return $matches[1];
        }

        return 'Theme.nativeblade';
    }

    private function generateSplash(array $desktop, array $mobile): void
    {
        $bg = $mobile['splash']['bg'] ?? $desktop['splash']['bg'] ?? '#0a0a0a';

        $jsBase = NativeBladeServiceProvider::packagePath('js/wasm-app');
        $splashPath = $jsBase . '/index.html';
        if (!file_exists($splashPath)) return;

        $html = file_get_contents($splashPath);
        $html = preg_replace('/background:\s*#[0-9a-fA-F]+/', "background: {$bg}", $html);
        file_put_contents($splashPath, $html);

        $this->line("  <fg=green>✓</> Splash updated");
    }

    private function tauriCliCommand(string $args): string
    {
        return PHP_OS_FAMILY === 'Windows'
            ? "npx.cmd {$args}"
            : "npx {$args}";
    }
}
