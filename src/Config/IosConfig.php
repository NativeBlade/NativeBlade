<?php

namespace NativeBlade\Config;

use Closure;
use NativeBlade\Config\Push\IosPushNotificationConfig;

class IosConfig
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

    public function statusBar(string $style = 'dark'): static
    {
        $this->config['statusBar'] = ['style' => $style];
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

    public function privacyManifest(array $apiReasons): static
    {
        $this->config['privacyManifest'] = $apiReasons;
        return $this;
    }

    public function minIosVersion(string $version): static
    {
        $this->config['minIosVersion'] = $version;
        return $this;
    }

    public function updateUrl(string $url): static
    {
        $this->config['updateUrl'] = $url;
        return $this;
    }

    public function storeUrl(string $url): static
    {
        $this->config['storeUrl'] = $url;
        return $this;
    }

    public function permissions(array $permissions): static
    {
        $this->config['permissions'] = $permissions;
        return $this;
    }

    /**
     * Configure iOS push notifications (APNS) via a fluent builder.
     *
     * ```
     * $config->notification(function (IosPushNotificationConfig $push) {
     *     $push
     *         ->environment('production')
     *         ->badge(true)
     *         ->sound('default')
     *         ->onReceive(fn ($payload) => ...);
     * });
     * ```
     *
     * @param  Closure(IosPushNotificationConfig): void  $callback
     */
    public function notification(Closure $callback): static
    {
        $push = new IosPushNotificationConfig();
        $callback($push);
        $this->config['notification'] = $push->toArray();
        return $this;
    }

    public function toArray(): array
    {
        return $this->config;
    }
}
