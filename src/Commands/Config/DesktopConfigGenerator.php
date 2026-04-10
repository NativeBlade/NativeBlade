<?php

namespace NativeBlade\Commands\Config;

use Illuminate\Console\Command;

class DesktopConfigGenerator
{
    public function __construct(private Command $cmd) {}

    public function generate(array $config): void
    {
        $this->generateTauriConf($config);
        $this->generateMenu($config);
        $this->generateTray($config);
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
        $this->cmd->line("  <fg=green>✓</> tauri.conf.json updated");
    }

    private function generateMenu(array $desktop): void
    {
        $menu = $desktop['menu'] ?? [];
        if (empty($menu)) return;

        $path = base_path('src-tauri/menu.json');
        file_put_contents($path, json_encode($this->buildMenuItems($menu), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->cmd->line("  <fg=green>✓</> menu.json generated");
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
                $this->cmd->line("  <fg=green>✓</> Tray icon copied");
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
        $this->cmd->line("  <fg=green>✓</> tray.json generated");
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
}
