<?php

namespace NativeBlade\Config;

use Closure;
use NativeBlade\Config\Push\IosPushNotificationConfig;

/**
 * Fluent builder for the iOS shell.
 *
 * ```php
 * NativeBladeConfig::ios(function (IosConfig $config) {
 *     $config->identifier('com.myapp.app')
 *         ->version('1.0.0', 1)
 *         ->minIosVersion('15.0')
 *         ->orientation('portrait')
 *         ->statusBar(style: 'dark')
 *         ->permissions([
 *             Permission::CAMERA => 'Take photos for your profile',
 *         ])
 *         ->privacyManifest([
 *             PrivacyApi::USER_DEFAULTS => PrivacyApi::USER_DEFAULTS_APP,
 *         ]);
 * });
 * ```
 */
class IosConfig
{
    /** @var array<string, mixed> */
    private array $config = [];

    /** Bundle id in reverse-domain format (e.g. `com.mycompany.myapp`). */
    public function identifier(string $identifier): static
    {
        $this->config['identifier'] = $identifier;
        return $this;
    }

    /** App version. `$version` is `CFBundleShortVersionString`, `$buildNumber` is `CFBundleVersion`. */
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
     * Configure the status bar style.
     *
     * @param  string  $style  `'dark'` for dark content on light bg, `'light'` for light content on dark bg.
     */
    public function statusBar(string $style = 'dark'): static
    {
        $this->config['statusBar'] = ['style' => $style];
        return $this;
    }

    /** Hide the status bar (true fullscreen mode). */
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

    /**
     * Apple PrivacyInfo.xcprivacy API access reasons (required since 2024).
     *
     * Map each `PrivacyApi::*` API the app uses to its `PrivacyApi::*_*` reason.
     *
     * @param  array<string, string>  $apiReasons  Keyed by API name, value is the reason code.
     */
    public function privacyManifest(array $apiReasons): static
    {
        $this->config['privacyManifest'] = $apiReasons;
        return $this;
    }

    /** Minimum supported iOS version (e.g. `'15.0'`). */
    public function minIosVersion(string $version): static
    {
        $this->config['minIosVersion'] = $version;
        return $this;
    }

    /** URL endpoint for in-app update checks. See UPDATES.md. */
    public function updateUrl(string $url): static
    {
        $this->config['updateUrl'] = $url;
        return $this;
    }

    /** App Store listing URL for "rate this app" / fallback redirect. */
    public function storeUrl(string $url): static
    {
        $this->config['storeUrl'] = $url;
        return $this;
    }

    /**
     * Declare NSUsageDescription strings shown in OS permission prompts.
     *
     * @param  array<string, string>  $permissions  Keyed by `Permission::*` value, value is the description.
     */
    public function permissions(array $permissions): static
    {
        $this->config['permissions'] = $permissions;
        return $this;
    }

    /**
     * Merge arbitrary keys into the generated Info.plist.
     *
     * Escape hatch for plist keys NativeBlade does not model with a dedicated
     * method, e.g. `ITSAppUsesNonExemptEncryption`, `LSApplicationQueriesSchemes`,
     * `UIBackgroundModes`. Most apps never need this: the built-in plugins write
     * the keys they require automatically. Use it only for app-specific needs.
     *
     * Values may be strings, booleans, integers, floats, and nested arrays
     * (lists become `<array>`, associative arrays become `<dict>`). Keys that
     * NativeBlade already manages (orientation, status bar, version, app name)
     * are ignored with a build warning; use their dedicated methods instead.
     *
     * @param  array<string, mixed>  $entries
     */
    public function infoPlist(array $entries): static
    {
        $this->config['infoPlist'] = array_merge($this->config['infoPlist'] ?? [], $entries);
        return $this;
    }

    /**
     * Configure iOS push notifications (APNS) via a fluent builder.
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

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->config;
    }
}
