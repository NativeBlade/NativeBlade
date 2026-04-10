<?php

namespace NativeBlade\Config;

class AndroidConfig
{
    private array $config = [];

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

    public function fullscreen(bool $value = true): static
    {
        $this->config['fullscreen'] = $value;
        return $this;
    }

    public function splashBackground(string $color = '#0a0a0a'): static
    {
        $this->config['splashBackground'] = $color;
        return $this;
    }

    public function minSdk(int $version): static
    {
        $this->config['minSdk'] = $version;
        return $this;
    }

    public function targetSdk(int $version): static
    {
        $this->config['targetSdk'] = $version;
        return $this;
    }

    public function permissions(array $permissions): static
    {
        $this->config['permissions'] = $permissions;
        return $this;
    }

    public function toArray(): array
    {
        return $this->config;
    }
}
