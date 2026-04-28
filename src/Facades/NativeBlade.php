<?php

namespace NativeBlade\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * Native action builders — return a chainable NativeResponse.
 *
 * @method static \NativeBlade\NativeResponse alert(\Closure $callback)
 * @method static \NativeBlade\NativeResponse confirm(\Closure $callback)
 * @method static \NativeBlade\NativeResponse notification(\Closure $callback)
 * @method static \NativeBlade\NativeResponse clipboardWrite(string $text)
 * @method static \NativeBlade\NativeResponse clipboardRead(?\Closure $callback = null)
 * @method static \NativeBlade\NativeResponse geolocation(?\Closure $callback = null)
 * @method static \NativeBlade\NativeResponse vibrate(int $duration = 100)
 * @method static \NativeBlade\NativeResponse impact(string $style = 'medium')
 * @method static \NativeBlade\NativeResponse selection()
 * @method static \NativeBlade\NativeResponse biometric(\Closure $callback)
 * @method static \NativeBlade\NativeResponse scan(?\Closure $callback = null)
 * @method static \NativeBlade\NativeResponse nfcRead(?\Closure $callback = null)
 * @method static \NativeBlade\NativeResponse openUrl(string $url)
 * @method static \NativeBlade\NativeResponse openFile(string $path)
 * @method static \NativeBlade\NativeResponse osInfo()
 * @method static \NativeBlade\NativeResponse camera(?\Closure $callback = null)
 * @method static \NativeBlade\NativeResponse gallery(?\Closure $callback = null)
 * @method static \NativeBlade\NativeResponse pickCamera(?\Closure $callback = null)
 * @method static \NativeBlade\NativeResponse pickGallery(?\Closure $callback = null)
 * @method static \NativeBlade\NativeResponse pickVideo(?\Closure $callback = null)
 * @method static \NativeBlade\NativeResponse navigate(string $path, bool $replace = false)
 * @method static \NativeBlade\NativeResponse showModal()
 * @method static \NativeBlade\NativeResponse hideModal()
 * @method static \NativeBlade\NativeResponse shell(\Closure $callback)
 * @method static \NativeBlade\NativeResponse exit()
 * @method static \NativeBlade\NativeResponse response()
 * @method static void log(string $message, array $context = [], string $level = 'info')
 *
 * State management.
 *
 * @method static void setState(string $key, mixed $value, string $scope = 'persistent')
 * @method static mixed getState(string $key, mixed $default = null)
 * @method static array state(?string $scope = null)
 * @method static void forget(string $key)
 * @method static void flush(?string $scope = null)
 * @method static array pool(callable $callback)
 *
 * Platform detection.
 *
 * @method static string platform()
 * @method static bool isDesktop()
 * @method static bool isMobile()
 * @method static bool isAndroid()
 * @method static bool isIos()
 * @method static bool isWindows()
 * @method static bool isMacos()
 * @method static bool isLinux()
 * @method static bool isWeb()
 */
class NativeBlade extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'nativeblade';
    }
}
