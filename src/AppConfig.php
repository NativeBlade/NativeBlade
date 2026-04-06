<?php

namespace NativeBlade;

class AppConfig
{
    private array $config = [];

    public function title(string $title): static
    {
        $this->config['title'] = $title;
        return $this;
    }

    public function version(string $version): static
    {
        $this->config['version'] = $version;
        return $this;
    }

    public function identifier(string $identifier): static
    {
        $this->config['identifier'] = $identifier;
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

    public function singleInstance(bool $value = true): static
    {
        $this->config['singleInstance'] = $value;
        return $this;
    }

    public function hideOnClose(bool $value = true): static
    {
        $this->config['hideOnClose'] = $value;
        return $this;
    }

    public function orientation(string $mode): static
    {
        $this->config['orientation'] = $mode;
        return $this;
    }

    public function statusBar(string $style = 'dark', string $color = '#000000'): static
    {
        $this->config['statusBar'] = ['style' => $style, 'color' => $color];
        return $this;
    }

    public function navigationBar(string $color = '#000000'): static
    {
        $this->config['navigationBar'] = ['color' => $color];
        return $this;
    }

    public function safeArea(bool $value = true): static
    {
        $this->config['safeArea'] = $value;
        return $this;
    }

    public function swipeBack(bool $value = true): static
    {
        $this->config['swipeBack'] = $value;
        return $this;
    }

    public function backButton(bool $value = true): static
    {
        $this->config['backButton'] = $value;
        return $this;
    }

    public function splash(string $bg = '#0a0a0a'): static
    {
        $this->config['splash'] = ['bg' => $bg];
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

    public function toArray(): array
    {
        return $this->config;
    }
}
