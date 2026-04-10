<?php

namespace NativeBlade\Config;

class DesktopConfig
{
    private array $config = [];

    public function title(string $title): static
    {
        $this->config['title'] = $title;
        return $this;
    }

    public function identifier(string $identifier): static
    {
        $this->config['identifier'] = $identifier;
        return $this;
    }

    public function version(string $version, int $buildNumber): static
    {
        $this->config['version'] = $version;
        $this->config['buildNumber'] = $buildNumber;
        return $this;
    }

    public function icon(string $path): static
    {
        $this->config['icon'] = $path;
        return $this;
    }

    public function size(int $width, int $height): static
    {
        $this->config['width'] = $width;
        $this->config['height'] = $height;
        return $this;
    }

    public function minSize(int $width, int $height): static
    {
        $this->config['minWidth'] = $width;
        $this->config['minHeight'] = $height;
        return $this;
    }

    public function resizable(bool $value = true): static
    {
        $this->config['resizable'] = $value;
        return $this;
    }

    public function fullscreen(bool $value = true): static
    {
        $this->config['fullscreen'] = $value;
        return $this;
    }

    public function hideOnClose(bool $value = true): static
    {
        $this->config['hideOnClose'] = $value;
        return $this;
    }

    public function tray(string $icon = '', string $tooltip = '', array $menu = []): static
    {
        $this->config['tray'] = ['icon' => $icon, 'tooltip' => $tooltip, 'menu' => $menu];
        return $this;
    }

    public function menu(array $items): static
    {
        $this->config['menu'] = $items;
        return $this;
    }

    public function splashBackground(string $color = '#0a0a0a'): static
    {
        $this->config['splashBackground'] = $color;
        return $this;
    }

    public function toArray(): array
    {
        return $this->config;
    }
}
