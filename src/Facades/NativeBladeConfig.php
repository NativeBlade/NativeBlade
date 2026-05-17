<?php

namespace NativeBlade\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * App-level configuration builder, typically called from a service provider.
 *
 * Configures per-platform shells (window size, identifier, permissions),
 * declares which Tauri plugins ship in the binary, sets the default page
 * transition, and registers OTA update sources.
 *
 * @method static void desktop(callable $callback) Configure desktop window via a `DesktopConfig` builder.
 * @method static void android(callable $callback) Configure Android shell via an `AndroidConfig` builder.
 * @method static void ios(callable $callback) Configure iOS shell via an `IosConfig` builder.
 * @method static void plugins(array $plugins) Declare which `Plugin::*` cases ship in the binary. Omit to include every plugin (looser binary, easier dev).
 * @method static void bottomNav(array $items) Configure the global bottom-navigation bar. Items are `{label, icon, route}` arrays.
 * @method static void topBar(array $options) Configure the global top app bar (title, leading/trailing actions).
 * @method static static bundlePush(string $url, bool $autoApply = true) Enable OTA Laravel-bundle updates from the given URL. When `$autoApply` is true, downloaded bundles are activated on next launch.
 * @method static static onBoot(callable $callback) Run a callback the first time the shell hands control to PHP after boot.
 * @method static static transition(string $type = 'fade') Default page transition for `NativeBlade::navigate()`. One of: `'fade'`, `'slide'`, `'zoom'`, `'flip'`, `'bounce'`, `'blur'`.
 * @method static array getAppConfigs() Returns the resolved per-platform configs (used by build commands).
 * @method static array getVersion(string $platform) Returns `['version' => string, 'buildNumber' => int]` for the given platform.
 * @method static string getTransition() Returns the currently configured default transition.
 */
class NativeBladeConfig extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'nativeblade';
    }
}
