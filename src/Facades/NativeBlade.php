<?php

namespace NativeBlade\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static static bottomNav(array $items)
 * @method static static topBar(array $options)
 * @method static static fab(array $options)
 * @method static array get()
 * @method static void desktop(callable $callback)
 * @method static void mobile(callable $callback)
 * @method static void android(callable $callback)
 * @method static void ios(callable $callback)
 * @method static string platform()
 * @method static bool isDesktop()
 * @method static bool isMobile()
 * @method static bool isAndroid()
 * @method static bool isIos()
 * @method static bool isWindows()
 * @method static bool isMacos()
 * @method static bool isLinux()
 * @method static bool isWeb()
 * @method static \NativeBlade\NativeResponse alert(string $message)
 * @method static \NativeBlade\NativeResponse notification(string $body)
 * @method static \NativeBlade\NativeResponse navigate(string $path)
 * @method static \NativeBlade\NativeResponse response()
 * @method static void setState(string $key, mixed $value, string $scope = 'persistent')
 * @method static mixed getState(string $key, mixed $default = null)
 * @method static array state(?string $scope = null)
 * @method static void forget(string $key)
 * @method static void flush(?string $scope = null)
 */
class NativeBlade extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'nativeblade';
    }
}
