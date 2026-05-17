<?php

namespace NativeBlade\Config;

use Closure;

/**
 * Fluent builder for the desktop system tray icon and behavior.
 *
 * Constructed through a closure passed to `DesktopConfig::tray()`. The
 * tray is desktop-only and disabled by default; configuring it via the
 * closure both enables it and supplies its icon, tooltip, context menu,
 * and the "hide window to tray on close" behavior.
 */
class Tray
{
    private string $icon = '';
    private string $tooltip = '';

    /** @var array<int, array<string, mixed>> */
    private array $menu = [];

    private bool $hideOnClose = false;

    /** Path to a 22x22 (Win/Linux) or 18x18 (macOS) PNG icon, relative to project root. */
    public function icon(string $path): static
    {
        $this->icon = $path;
        return $this;
    }

    /** Hover text shown when the user mouses over the tray icon. */
    public function tooltip(string $text): static
    {
        $this->tooltip = $text;
        return $this;
    }

    /**
     * @param  Closure(Menu): void  $callback
     */
    public function menu(Closure $callback): static
    {
        $menu = new Menu();
        $callback($menu);
        $this->menu = $menu->toArray();
        return $this;
    }

    /**
     * Keep the app alive in the tray when the user clicks the window's
     * close button. Without this the close button quits the process.
     */
    public function hideOnClose(bool $value = true): static
    {
        $this->hideOnClose = $value;
        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'icon' => $this->icon,
            'tooltip' => $this->tooltip,
            'menu' => $this->menu,
            'hideOnClose' => $this->hideOnClose,
        ];
    }
}
