<?php

namespace NativeBlade\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * Native action builders — return a chainable NativeResponse.
 *
 * Dialogs.
 *
 * @method static \NativeBlade\NativeResponse alert(\Closure $callback)
 * @method static \NativeBlade\NativeResponse confirm(\Closure $callback)
 *
 * Notifications.
 *
 * @method static \NativeBlade\NativeResponse notification(\Closure $callback)
 * @method static \NativeBlade\NativeResponse scheduleNotification(\Closure $callback)
 * @method static \NativeBlade\NativeResponse cancelNotification(string $id)
 * @method static \NativeBlade\NativeResponse cancelAllNotifications()
 *
 * Clipboard.
 *
 * @method static \NativeBlade\NativeResponse clipboardWrite(string $text)
 * @method static \NativeBlade\NativeResponse clipboardRead(?\Closure $callback = null)
 *
 * Geolocation.
 *
 * @method static \NativeBlade\NativeResponse geolocation(?\Closure $callback = null)
 *
 * Haptics.
 *
 * @method static \NativeBlade\NativeResponse vibrate(int $duration = 100)
 * @method static \NativeBlade\NativeResponse impact(string $style = 'medium')
 * @method static \NativeBlade\NativeResponse selection()
 *
 * Biometric, scanner, NFC.
 *
 * @method static \NativeBlade\NativeResponse biometric(\Closure $callback)
 * @method static \NativeBlade\NativeResponse scan(?\Closure $callback = null)
 * @method static \NativeBlade\NativeResponse nfcRead(?\Closure $callback = null)
 *
 * Opener.
 *
 * @method static \NativeBlade\NativeResponse openUrl(string $url)
 * @method static \NativeBlade\NativeResponse openFile(string $path)
 * @method static \NativeBlade\NativeResponse requestReview()
 * @method static \NativeBlade\NativeResponse setSecure(string $key, string $value)
 * @method static \NativeBlade\NativeResponse getSecure(string $key, ?string $id = null)
 * @method static \NativeBlade\NativeResponse forgetSecure(string $key)
 * @method static \NativeBlade\NativeResponse share(?string $text = null, ?string $url = null)
 * @method static \NativeBlade\NativeResponse analytics(\Closure $callback)
 * @method static \NativeBlade\NativeResponse requestAdConsent(array $testDeviceIds = [])
 * @method static \NativeBlade\NativeResponse rewardedAd(\Closure $callback)
 * @method static \NativeBlade\NativeResponse interstitialAd(\Closure $callback)
 * @method static \NativeBlade\NativeResponse bannerAd(\Closure $callback)
 * @method static \NativeBlade\NativeResponse hideBannerAd()
 * @method static \NativeBlade\NativeResponse products(array $productIds, ?string $id = null)
 * @method static \NativeBlade\NativeResponse purchase(\Closure $callback)
 * @method static \NativeBlade\NativeResponse restorePurchases(?string $id = null)
 * @method static \NativeBlade\NativeResponse subscriptionStatus(array $productIds = [], ?string $id = null)
 * @method static \NativeBlade\NativeResponse networkStatus(?string $id = null)
 *
 * OS info.
 *
 * @method static \NativeBlade\NativeResponse osInfo()
 *
 * Camera & gallery (JS-canvas resize, all platforms).
 *
 * @method static \NativeBlade\NativeResponse camera(?\Closure $callback = null)
 * @method static \NativeBlade\NativeResponse gallery(?\Closure $callback = null)
 *
 * Media (native resize, mobile preferred).
 *
 * @method static \NativeBlade\NativeResponse pickCamera(?\Closure $callback = null)
 * @method static \NativeBlade\NativeResponse pickGallery(?\Closure $callback = null)
 * @method static \NativeBlade\NativeResponse pickVideo(?\Closure $callback = null)
 *
 * File picker and file operations.
 *
 * @method static \NativeBlade\NativeResponse filePicker(?\Closure $callback = null)
 * @method static \NativeBlade\NativeResponse fileSave(string $defaultName, ?\Closure $callback = null)
 * @method static \NativeBlade\NativeResponse copyFile(string $from, string $to, string $purpose = 'app')
 * @method static \NativeBlade\NativeResponse moveFile(string $from, string $to, string $purpose = 'app')
 *
 * Upload.
 *
 * @method static \NativeBlade\NativeResponse upload(string $path, string $url, ?\Closure $callback = null)
 *
 * Navigation.
 *
 * @method static \NativeBlade\NativeResponse navigate(string $path, bool $replace = false)
 *
 * Custom Tauri command invocation.
 *
 * @method static \NativeBlade\NativeResponse tauriInvoke(string $command, array $args = [], ?string $emit = null)
 *
 * Modal.
 *
 * @method static \NativeBlade\NativeResponse showModal()
 * @method static \NativeBlade\NativeResponse hideModal()
 *
 * Shell.
 *
 * @method static \NativeBlade\NativeResponse shell(\Closure $callback)
 *
 * Window / process control (desktop only).
 *
 * @method static \NativeBlade\NativeResponse exit()
 * @method static \NativeBlade\NativeResponse minimize()
 * @method static \NativeBlade\NativeResponse maximize()
 * @method static \NativeBlade\NativeResponse unmaximize()
 * @method static \NativeBlade\NativeResponse toggleMaximize()
 * @method static \NativeBlade\NativeResponse hide()
 * @method static \NativeBlade\NativeResponse show()
 *
 * Bundle updates (OTA).
 *
 * @method static \NativeBlade\NativeResponse checkUpdate()
 * @method static \NativeBlade\NativeResponse forceUpdate()
 *
 * Response factory and logging.
 *
 * @method static \NativeBlade\NativeResponse response()
 * @method static void log(string $message, array $context = [], string $level = 'info')
 *
 * State management.
 *
 * @method static void setState(string $key, mixed $value, string $scope = 'persistent')
 * @method static mixed getState(string $key, mixed $default = null)
 * @method static array state(?string $scope = null)
 * @method static void forget(string $key)
 *
 * Language.
 *
 * @method static void setLanguage(string $locale)
 * @method static string currentLanguage()
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
 * @method static string version() Human-readable app version for the current platform (e.g. "1.0.0"). Returns "dev" in web mode.
 * @method static int buildNumber() Integer build number for the current platform (Android versionCode, iOS CFBundleVersion, desktop buildNumber). Returns 0 in web mode.
 */
class NativeBlade extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'nativeblade';
    }
}
