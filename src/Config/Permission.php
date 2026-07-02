<?php

namespace NativeBlade\Config;

/**
 * Cross-platform permission constants used in `AndroidConfig::permissions()`
 * and `IosConfig::permissions()`.
 *
 * Each constant maps to the platform-native permission identifier
 * (Android: `android.permission.*`, iOS: `NS*UsageDescription` in
 * `Info.plist`). The value you pass alongside the constant becomes the
 * user-facing rationale string shown by the OS at runtime.
 *
 * ```php
 * $config->permissions([
 *     Permission::CAMERA   => 'Take photos for your profile',
 *     Permission::LOCATION => 'Show nearby content',
 * ]);
 * ```
 */
class Permission
{
    /** Android `CAMERA` / iOS `NSCameraUsageDescription`. Required for `camera()`, `pickCamera()`, `scan()`. */
    const CAMERA = 'camera';

    /** Android `ACCESS_FINE_LOCATION` / iOS `NSLocationWhenInUseUsageDescription`. Required for `geolocation()`. */
    const LOCATION = 'location';

    /** iOS only: `NSLocationAlwaysUsageDescription` for background location updates. */
    const LOCATION_ALWAYS = 'location_always';

    /**
     * Android only: `ACCESS_BACKGROUND_LOCATION` — location collected with the
     * app closed (e.g. a background task with `withLocation()`). Requires
     * `LOCATION` too, and Google Play requires a declaration form + demo video
     * justifying it. iOS covers this with `LOCATION_ALWAYS`.
     */
    const BACKGROUND_LOCATION = 'background_location';

    /** Android only: `ACCESS_COARSE_LOCATION` (network/cell-based, less precise than `LOCATION`). */
    const LOCATION_COARSE = 'location_coarse';

    /** Android `RECORD_AUDIO` / iOS `NSMicrophoneUsageDescription`. Required for video recording with audio. */
    const MICROPHONE = 'microphone';

    /** Android `READ_EXTERNAL_STORAGE` (legacy, pre-Android 13). */
    const STORAGE = 'storage';

    /** Android `WRITE_EXTERNAL_STORAGE` (legacy, pre-Android 10 scoped storage). */
    const STORAGE_WRITE = 'storage_write';

    /** iOS only: `NSPhotoLibraryUsageDescription`. Required for `pickGallery()` and `gallery()` on iOS. */
    const PHOTOS = 'photos';

    /** iOS only: `NSPhotoLibraryAddUsageDescription`. Required for save-to-library operations. */
    const PHOTOS_ADD = 'photos_add';

    /** Android `POST_NOTIFICATIONS` (Android 13+). iOS handles this via the push opt-in prompt. */
    const NOTIFICATIONS = 'notifications';

    /**
     * Android `SCHEDULE_EXACT_ALARM` + `USE_EXACT_ALARM`. Opt-in: only declare it
     * if your app's purpose is reminders/alarms — it lets `scheduleNotification()`
     * fire on the exact second even in Doze. Google Play scrutinizes it, so the
     * push plugin does NOT add it by default. No iOS equivalent (already exact).
     */
    const EXACT_ALARM = 'exact_alarm';

    /** Android `VIBRATE`. Required for haptic feedback on Android. iOS has no equivalent permission. */
    const VIBRATE = 'vibrate';

    /** Android `USE_BIOMETRIC` / iOS `NSFaceIDUsageDescription`. Required for `biometric()` prompts. */
    const BIOMETRIC = 'biometric';

    /** Android `NFC` / iOS `NFCReaderUsageDescription`. Required for `nfcRead()`. */
    const NFC = 'nfc';

    /** iOS only: `NSContactsUsageDescription`. */
    const CONTACTS = 'contacts';

    /** iOS only: `NSCalendarsUsageDescription`. */
    const CALENDAR = 'calendar';

    /** Android `BLUETOOTH_CONNECT` / iOS `NSBluetoothAlwaysUsageDescription`. */
    const BLUETOOTH = 'bluetooth';

    /**
     * Android only: `ACTIVITY_RECOGNITION` (Android 10+) — step counting /
     * physical activity detection (the future pedometer). Play scrutinizes it.
     */
    const ACTIVITY_RECOGNITION = 'activity_recognition';

    /**
     * iOS only: `NSMotionUsageDescription` — CMPedometer / device-motion
     * activity APIs. Raw sensor reads (`NativeBlade::sensors()`) do NOT need
     * it; only step counting / activity detection will.
     */
    const MOTION = 'motion';
}
