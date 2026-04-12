<?php

namespace NativeBlade\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * Native action builders — return a chainable NativeResponse.
 *
 * @method static \NativeBlade\NativeResponse alert(string $message)
 * @method static \NativeBlade\NativeResponse confirm(string $message)
 * @method static \NativeBlade\NativeResponse notification(string $body)
 * @method static \NativeBlade\NativeResponse clipboardWrite(string $text)
 * @method static \NativeBlade\NativeResponse clipboardRead()
 * @method static \NativeBlade\NativeResponse geolocation()
 * @method static \NativeBlade\NativeResponse vibrate(int $duration = 100)
 * @method static \NativeBlade\NativeResponse impact(string $style = 'medium')
 * @method static \NativeBlade\NativeResponse selection()
 * @method static \NativeBlade\NativeResponse biometric(string $reason = 'Authenticate')
 * @method static \NativeBlade\NativeResponse scan(array $formats = [])
 * @method static \NativeBlade\NativeResponse nfcRead()
 * @method static \NativeBlade\NativeResponse openUrl(string $url)
 * @method static \NativeBlade\NativeResponse openFile(string $path)
 * @method static \NativeBlade\NativeResponse osInfo()
 * @method static \NativeBlade\NativeResponse camera(array $options = [])
 * @method static \NativeBlade\NativeResponse gallery(array $options = [])
 * @method static \NativeBlade\NativeResponse navigate(string $path, bool $replace = false)
 * @method static \NativeBlade\NativeResponse showModal()
 * @method static \NativeBlade\NativeResponse hideModal()
 * @method static \NativeBlade\NativeResponse exit()
 * @method static \NativeBlade\NativeResponse response()
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
