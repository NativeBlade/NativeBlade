<?php

namespace NativeBlade\Config;

use Closure;
use NativeBlade\Config\Push\AndroidPushNotificationConfig;

/**
 * Fluent builder for the Android shell.
 *
 * ```php
 * NativeBladeConfig::android(function (AndroidConfig $config) {
 *     $config->identifier('com.myapp.app')
 *         ->version('1.0.0', 1)
 *         ->minSdk(28)
 *         ->targetSdk(35)
 *         ->orientation('portrait')
 *         ->statusBar(style: 'dark')
 *         ->permissions([
 *             Permission::CAMERA => 'Take photos for your profile',
 *         ]);
 * });
 * ```
 */
class AndroidConfig
{
    /** @var array<string, mixed> */
    private array $config = [];

    /** Application id in reverse-domain format (e.g. `com.mycompany.myapp`). */
    public function identifier(string $identifier): static
    {
        $this->config['identifier'] = $identifier;
        return $this;
    }

    /** App version. `$version` is the `versionName`, `$buildNumber` the `versionCode`. */
    public function version(string $version, int $buildNumber): static
    {
        $this->config['version'] = $version;
        $this->config['buildNumber'] = $buildNumber;
        return $this;
    }

    /**
     * Lock the app's screen orientation.
     *
     * @param  string  $mode  One of `'portrait'`, `'landscape'`, `'auto'`.
     */
    public function orientation(string $mode): static
    {
        $this->config['orientation'] = $mode;
        return $this;
    }

    /**
     * Configure the status bar icon style.
     *
     * Only the icon style (light vs dark) is configurable. NativeBlade forces
     * edge-to-edge layout, which makes the system bars transparent — so the
     * background you see under the status bar comes from your WebView content
     * (paint it via CSS with `env(safe-area-inset-top)`), not from a theme color.
     *
     * The navigation bar inherits the same style automatically.
     *
     * @param  string  $style  `'dark'` for dark icons on light bg, `'light'` for light icons on dark bg.
     */
    public function statusBar(string $style = 'dark'): static
    {
        $this->config['statusBar'] = ['style' => $style];
        return $this;
    }

    /** Hide both status bar and navigation bar (immersive mode). */
    public function fullscreen(bool $value = true): static
    {
        $this->config['fullscreen'] = $value;
        return $this;
    }

    /** Splash screen background color (hex). Shown during the native cold start before PHP boots. */
    public function splashBackground(string $color = '#0a0a0a'): static
    {
        $this->config['splashBackground'] = $color;
        return $this;
    }

    /** Minimum supported Android SDK level. Default 28 (Android 9). */
    public function minSdk(int $version): static
    {
        $this->config['minSdk'] = $version;
        return $this;
    }

    /** Target Android SDK level. Default 35 (Android 15). */
    public function targetSdk(int $version): static
    {
        $this->config['targetSdk'] = $version;
        return $this;
    }

    /** URL endpoint for in-app update checks. See UPDATES.md. */
    public function updateUrl(string $url): static
    {
        $this->config['updateUrl'] = $url;
        return $this;
    }

    /** Play Store listing URL for "rate this app" / fallback redirect. */
    public function storeUrl(string $url): static
    {
        $this->config['storeUrl'] = $url;
        return $this;
    }

    /**
     * Declare runtime permissions and their user-facing rationale.
     *
     * Map of `Permission::*` enum case to the explanation shown when the
     * OS prompts the user.
     *
     * @param  array<string, string>  $permissions  Keyed by `Permission::*` value, value is the description.
     */
    public function permissions(array $permissions): static
    {
        $this->config['permissions'] = $permissions;
        return $this;
    }

    /**
     * Configure Android push notifications (FCM) via a fluent builder.
     *
     * @param  Closure(AndroidPushNotificationConfig): void  $callback
     */
    public function notification(Closure $callback): static
    {
        $push = new AndroidPushNotificationConfig();
        $callback($push);
        $this->config['notification'] = $push->toArray();
        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->config;
    }
}
