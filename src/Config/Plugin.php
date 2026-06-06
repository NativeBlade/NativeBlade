<?php

namespace NativeBlade\Config;

/**
 * Available Tauri plugins that can be opted into in the AppServiceProvider.
 *
 * If `NativeBladeConfig::plugins([...])` is not called, all plugins are
 * included by default. Declaring plugins explicitly trims the build:
 * only the declared plugins ship in the binary, the AndroidManifest, the
 * iOS Info.plist, the capabilities, and `package.json`. This avoids
 * App Store / Play Store reviews flagging unused permissions.
 */
enum Plugin: string
{
    /** Camera, gallery, and video picker (NativeBlade native plugin with on-device resizing). */
    case MEDIA = 'media';

    /** Push notifications via FCM (Android) and APNS (iOS) plus local/scheduled notifications. NativeBlade native plugin. */
    case PUSH = 'push';

    /** Native in-app review prompt (StoreKit on iOS, Play In-App Review on Android). Powers `NativeBlade::requestReview()`. NativeBlade native plugin. */
    case IN_APP_REVIEW = 'in_app_review';

    /** Encrypted key-value storage (Keychain on iOS, Tink AEAD sealed by the Android Keystore). Powers `NativeBlade::setSecure()`/`getSecure()`. NativeBlade native plugin. */
    case SECURE_STORAGE = 'secure_storage';

    /** Native share sheet (UIActivityViewController on iOS, Intent.ACTION_SEND on Android). Powers `NativeBlade::share()`. NativeBlade native plugin. */
    case SHARING = 'sharing';

    /** Firebase Analytics via the native SDK (events, screens, user properties, consent). Powers `NativeBlade::analytics()`. Needs `NativeBladeConfig::firebase(...)`. NativeBlade native plugin. */
    case ANALYTICS = 'analytics';

    /** Device GPS / network-based location. Powers `NativeBlade::geolocation()`. */
    case GEOLOCATION = 'geolocation';

    /** Fingerprint, Face ID, Touch ID prompts. Powers `NativeBlade::biometric()` on mobile. */
    case BIOMETRIC = 'biometric';

    /** Barcode/QR scanner with the device camera. Powers `NativeBlade::scan()` on mobile. */
    case BARCODE_SCANNER = 'barcode_scanner';

    /** NFC tag reading. Powers `NativeBlade::nfcRead()` on mobile. */
    case NFC = 'nfc';

    /** Vibration and haptic feedback. Powers `NativeBlade::vibrate()`, `impact()`, `selection()`. */
    case HAPTICS = 'haptics';

    /** System clipboard read/write. Powers `NativeBlade::clipboardWrite()` and `clipboardRead()`. */
    case CLIPBOARD = 'clipboard';

    /** HTTP upload with progress reporting. Powers `NativeBlade::upload()`. */
    case UPLOAD = 'upload';

    /** Native dialogs (alert/confirm/file picker). Powers `NativeBlade::alert()`, `confirm()`, `filePicker()`, `fileSave()`. */
    case DIALOG = 'dialog';

    /** Open URLs in the system browser and files in their default app. Powers `NativeBlade::openUrl()`, `openFile()`. */
    case OPENER = 'opener';

    /** Persistent key-value store backing `NativeBlade::setState()`/`getState()` across launches. */
    case STORE = 'store';

    /** Filesystem read/write APIs used by `copyFile()`, `moveFile()`, and the bundle-push update flow. */
    case FS = 'fs';

    /** Outbound HTTP client running on the native side (bypasses WebView CORS). */
    case HTTP = 'http';

    /** Deep link / custom URL scheme handling for opening the app from URLs. */
    case DEEP_LINK = 'deep_link';

    /** OS metadata (platform, version, hostname). Powers `NativeBlade::osInfo()`. */
    case OS = 'os';

    /** Process control: app exit, restart. Powers `NativeBlade::exit()` and the window controls. */
    case PROCESS = 'process';

    /** Execute shell commands (desktop only). Powers `NativeBlade::shell()`. */
    case SHELL = 'shell';
}
