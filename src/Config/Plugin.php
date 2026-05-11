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

    /** Push notifications via FCM (Android) and APNS (iOS) — NativeBlade native plugin. */
    case PUSH = 'push';

    case GEOLOCATION = 'geolocation';
    case BIOMETRIC = 'biometric';
    case BARCODE_SCANNER = 'barcode_scanner';
    case NFC = 'nfc';
    case HAPTICS = 'haptics';
    case CLIPBOARD = 'clipboard';
    case UPLOAD = 'upload';
    case DIALOG = 'dialog';
    case OPENER = 'opener';
    case STORE = 'store';
    case FS = 'fs';
    case HTTP = 'http';
    case DEEP_LINK = 'deep_link';
    case OS = 'os';
    case PROCESS = 'process';
    case SHELL = 'shell';
}
