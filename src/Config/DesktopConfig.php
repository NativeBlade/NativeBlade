<?php

namespace NativeBlade\Config;

/**
 * Fluent builder for desktop window configuration.
 *
 * ```php
 * NativeBladeConfig::desktop(function (DesktopConfig $config) {
 *     $config->title('My App')
 *         ->identifier('com.myapp.desktop')
 *         ->version('1.0.0', 1)
 *         ->size(1200, 800)
 *         ->minSize(800, 600)
 *         ->resizable()
 *         ->center();
 * });
 * ```
 */
class DesktopConfig
{
    private array $config = [];

    /** Window title shown in the title bar and taskbar. */
    public function title(string $title): static
    {
        $this->config['title'] = $title;
        return $this;
    }

    /** Unique app identifier in reverse-domain format (e.g. `com.mycompany.myapp`). */
    public function identifier(string $identifier): static
    {
        $this->config['identifier'] = $identifier;
        return $this;
    }

    /** Semantic version and integer build number for the app bundle. */
    public function version(string $version, int $buildNumber): static
    {
        $this->config['version'] = $version;
        $this->config['buildNumber'] = $buildNumber;
        return $this;
    }

    /** Path to the source icon (relative to project root). NativeBlade generates all required sizes. */
    public function icon(string $path): static
    {
        $this->config['icon'] = $path;
        return $this;
    }

    /** Initial window dimensions in pixels. */
    public function size(int $width, int $height): static
    {
        $this->config['width'] = $width;
        $this->config['height'] = $height;
        return $this;
    }

    /** Minimum window dimensions — the user cannot resize below this. */
    public function minSize(int $width, int $height): static
    {
        $this->config['minWidth'] = $width;
        $this->config['minHeight'] = $height;
        return $this;
    }

    /** Allow the user to resize the window. Enabled by default. */
    public function resizable(bool $value = true): static
    {
        $this->config['resizable'] = $value;
        return $this;
    }

    /** Launch the app in fullscreen mode. */
    public function fullscreen(bool $value = true): static
    {
        $this->config['fullscreen'] = $value;
        return $this;
    }

    /**
     * Hide the window instead of quitting when the user clicks the close button.
     * Useful with `tray()` — the app stays in the system tray.
     */
    public function hideOnClose(bool $value = true): static
    {
        $this->config['hideOnClose'] = $value;
        return $this;
    }

    /**
     * Enable the system tray icon.
     *
     * @param  string  $icon     Path to a PNG icon for the tray (relative to project root).
     * @param  string  $tooltip  Tooltip text shown on hover.
     * @param  array   $menu     Tray context menu items (`['Label' => 'action', '---' => '---']`).
     */
    public function tray(string $icon = '', string $tooltip = '', array $menu = []): static
    {
        $this->config['tray'] = ['icon' => $icon, 'tooltip' => $tooltip, 'menu' => $menu];
        return $this;
    }

    /**
     * Native application menu bar (macOS menu bar, Windows/Linux top menu).
     *
     * ```php
     * ->menu([
     *     'File' => [
     *         'New'  => '/new',
     *         '---'  => '---',
     *         'Quit' => 'exit',
     *     ],
     * ])
     * ```
     */
    public function menu(array $items): static
    {
        $this->config['menu'] = $items;
        return $this;
    }

    /** URL endpoint for auto-update checks. See AUTO-UPDATE.md. */
    public function updateUrl(string $url): static
    {
        $this->config['updateUrl'] = $url;
        return $this;
    }

    /**
     * Show or hide the native window decorations (title bar, minimize, maximize, close buttons).
     *
     * Pass `false` to remove all native chrome. Combine with `transparent()` to build
     * a fully custom title bar in HTML. Add `data-tauri-drag-region` to your custom
     * header element so the user can drag the window.
     *
     * ```php
     * $config->decorations(false)->transparent();
     * ```
     *
     * ```blade
     * <div data-tauri-drag-region class="h-10 flex items-center px-4 bg-black">
     *     <span>My App</span>
     * </div>
     * ```
     */
    public function decorations(bool $value = true): static
    {
        $this->config['decorations'] = $value;
        return $this;
    }

    /**
     * Make the window background transparent.
     *
     * Requires `decorations(false)`. Your HTML background becomes the window
     * background — use `bg-transparent` or a semi-transparent color to see through.
     */
    public function transparent(bool $value = true): static
    {
        $this->config['transparent'] = $value;
        return $this;
    }

    /** Keep the window above all other windows. Useful for widgets, overlays, or POS kiosks. */
    public function alwaysOnTop(bool $value = true): static
    {
        $this->config['alwaysOnTop'] = $value;
        return $this;
    }

    /** Start the window maximized. */
    public function maximized(bool $value = true): static
    {
        $this->config['maximized'] = $value;
        return $this;
    }

    /** Center the window on screen when the app opens. */
    public function center(bool $value = true): static
    {
        $this->config['center'] = $value;
        return $this;
    }

    /** Background color for the splash screen while the app loads. Hex format (e.g. `#0a0a0a`). */
    public function splashBackground(string $color = '#0a0a0a'): static
    {
        $this->config['splashBackground'] = $color;
        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->config;
    }
}
