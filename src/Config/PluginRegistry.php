<?php

namespace NativeBlade\Config;

/**
 * Single source of truth for what each plugin touches.
 *
 * Each descriptor lists every file/permission that needs to be added or
 * removed when the plugin is enabled/disabled. The config generators
 * read these descriptors and rewrite the affected files.
 *
 * Descriptor shape (optional plugins):
 * - feature:             Cargo feature name in user's src-tauri/Cargo.toml
 * - feature_crate:       crate name to include via `dep:<name>` in [features]
 * - rust_init:           expression added to .plugin(...) in user's lib.rs
 * - mobile_only:         when true, the cfg also gates on target_os = android/ios
 * - capabilities:        permissions added to default.json
 * - mobile_capabilities: permissions added to mobile.json
 * - npm:                 package.json dep entries (key => version)
 * - android_permissions: AndroidManifest.xml uses-permission entries
 * - ios_plist:           Info.plist usage description keys
 *
 * Always-on plugins skip the feature/rust_init fields — they're compiled
 * unconditionally into the framework crate.
 */
class PluginRegistry
{
    /**
     * @return array<string, mixed>
     */
    public static function descriptor(Plugin $plugin): array
    {
        return match ($plugin) {
            Plugin::MEDIA => [
                'feature' => 'media',
                'feature_crate' => 'tauri-plugin-nativeblade-media',
                'rust_init' => 'tauri_plugin_nativeblade_media::init()',
                'mobile_only' => true,
                'mobile_capabilities' => ['nativeblade-media:default'],
                'android_permissions' => ['CAMERA'],
                'ios_plist' => ['NSCameraUsageDescription', 'NSPhotoLibraryUsageDescription'],
            ],

            Plugin::PUSH => [
                'feature' => 'push',
                'feature_crate' => 'tauri-plugin-nativeblade-push',
                'rust_init' => 'tauri_plugin_nativeblade_push::init()',
                'mobile_only' => true,
                'mobile_capabilities' => ['nativeblade-push:default'],
                'android_permissions' => ['POST_NOTIFICATIONS', 'INTERNET', 'WAKE_LOCK'],
                'ios_plist' => [],
            ],

            Plugin::GEOLOCATION => [
                'feature' => 'geolocation',
                'feature_crate' => 'tauri-plugin-geolocation',
                'rust_init' => 'tauri_plugin_geolocation::init()',
                'capabilities' => [
                    'geolocation:allow-check-permissions',
                    'geolocation:allow-request-permissions',
                    'geolocation:allow-get-current-position',
                    'geolocation:allow-watch-position',
                ],
                'npm' => ['@tauri-apps/plugin-geolocation' => '^2'],
                'android_permissions' => ['ACCESS_FINE_LOCATION', 'ACCESS_COARSE_LOCATION'],
                'ios_plist' => ['NSLocationWhenInUseUsageDescription'],
            ],

            Plugin::BIOMETRIC => [
                'feature' => 'biometric',
                'feature_crate' => 'tauri-plugin-biometric',
                'rust_init' => 'tauri_plugin_biometric::init()',
                'mobile_only' => true,
                'mobile_capabilities' => ['biometric:default'],
                'npm' => ['@tauri-apps/plugin-biometric' => '^2'],
                'android_permissions' => ['USE_BIOMETRIC'],
                'ios_plist' => ['NSFaceIDUsageDescription'],
            ],

            Plugin::BARCODE_SCANNER => [
                'feature' => 'barcode_scanner',
                'feature_crate' => 'tauri-plugin-barcode-scanner',
                'rust_init' => 'tauri_plugin_barcode_scanner::init()',
                'mobile_only' => true,
                'mobile_capabilities' => [
                    'barcode-scanner:allow-scan',
                    'barcode-scanner:allow-cancel',
                    'barcode-scanner:allow-check-permissions',
                    'barcode-scanner:allow-request-permissions',
                    'barcode-scanner:allow-vibrate',
                    'barcode-scanner:allow-open-app-settings',
                ],
                'npm' => ['@tauri-apps/plugin-barcode-scanner' => '^2'],
                'android_permissions' => ['CAMERA'],
                'ios_plist' => ['NSCameraUsageDescription'],
            ],

            Plugin::NFC => [
                'feature' => 'nfc',
                'feature_crate' => 'tauri-plugin-nfc',
                'rust_init' => 'tauri_plugin_nfc::init()',
                'mobile_only' => true,
                'mobile_capabilities' => ['nfc:default'],
                'npm' => ['@tauri-apps/plugin-nfc' => '^2'],
                'android_permissions' => ['NFC'],
                'ios_plist' => ['NFCReaderUsageDescription'],
            ],

            Plugin::HAPTICS => [
                'feature' => 'haptics',
                'feature_crate' => 'tauri-plugin-haptics',
                'rust_init' => 'tauri_plugin_haptics::init()',
                'capabilities' => [
                    'haptics:allow-impact-feedback',
                    'haptics:allow-notification-feedback',
                    'haptics:allow-selection-feedback',
                    'haptics:allow-vibrate',
                ],
                'npm' => ['@tauri-apps/plugin-haptics' => '^2'],
                'android_permissions' => ['VIBRATE'],
            ],

            Plugin::CLIPBOARD => [
                'feature' => 'clipboard',
                'feature_crate' => 'tauri-plugin-clipboard-manager',
                'rust_init' => 'tauri_plugin_clipboard_manager::init()',
                'capabilities' => ['clipboard-manager:allow-read-text', 'clipboard-manager:allow-write-text'],
                'npm' => ['@tauri-apps/plugin-clipboard-manager' => '^2'],
            ],

            Plugin::UPLOAD => [
                'feature' => 'upload',
                'feature_crate' => 'tauri-plugin-upload',
                'rust_init' => 'tauri_plugin_upload::init()',
                'capabilities' => ['upload:default'],
                'npm' => ['@tauri-apps/plugin-upload' => '^2'],
                'android_permissions' => ['INTERNET'],
            ],

            Plugin::HTTP => [
                'feature' => 'http',
                'feature_crate' => 'tauri-plugin-http',
                'rust_init' => 'tauri_plugin_http::init()',
                'capabilities' => ['http:default'],
                'npm' => ['@tauri-apps/plugin-http' => '^2'],
                'android_permissions' => ['INTERNET'],
            ],

            Plugin::DEEP_LINK => [
                'feature' => 'deep_link',
                'feature_crate' => 'tauri-plugin-deep-link',
                'rust_init' => 'tauri_plugin_deep_link::init()',
                'capabilities' => ['deep-link:default'],
                'npm' => ['@tauri-apps/plugin-deep-link' => '^2'],
            ],

            Plugin::SHELL => [
                'feature' => 'shell',
                'feature_crate' => 'tauri-plugin-shell',
                'rust_init' => 'tauri_plugin_shell::init()',
                'capabilities' => ['shell:allow-open'],
                'npm' => ['@tauri-apps/plugin-shell' => '^2'],
            ],

            // Always-on (always compiled in via the framework crate)
            Plugin::DIALOG => [
                'capabilities' => ['dialog:default'],
                'npm' => ['@tauri-apps/plugin-dialog' => '^2'],
            ],
            Plugin::OPENER => [
                'capabilities' => ['opener:default'],
                'npm' => ['@tauri-apps/plugin-opener' => '^2'],
            ],
            Plugin::STORE => [
                'capabilities' => ['store:default'],
                'npm' => ['@tauri-apps/plugin-store' => '^2'],
            ],
            Plugin::FS => [
                'capabilities' => [
                    'fs:default',
                    'fs:allow-read',
                    'fs:allow-write',
                    'fs:allow-exists',
                    'fs:allow-mkdir',
                    'fs:allow-remove',
                    'fs:allow-rename',
                    'fs:allow-copy-file',
                    'fs:allow-stat',
                    'fs:allow-read-dir',
                    'fs:allow-read-file',
                    'fs:allow-write-file',
                ],
                'npm' => ['@tauri-apps/plugin-fs' => '^2'],
            ],
            Plugin::OS => [
                'capabilities' => ['os:default'],
                'npm' => ['@tauri-apps/plugin-os' => '^2'],
            ],
            Plugin::PROCESS => [
                'capabilities' => ['process:default'],
                'npm' => ['@tauri-apps/plugin-process' => '^2'],
            ],
        };
    }

    /** @return Plugin[] */
    public static function alwaysOn(): array
    {
        return [
            Plugin::DIALOG,
            Plugin::OS,
            Plugin::PROCESS,
            Plugin::STORE,
            Plugin::FS,
            Plugin::OPENER,
        ];
    }

    /** @return Plugin[] */
    public static function all(): array
    {
        return Plugin::cases();
    }

    /**
     * @param  Plugin[]|null  $declared
     * @return Plugin[]
     */
    public static function resolve(?array $declared): array
    {
        $set = $declared === null
            ? self::all()
            : array_unique(array_merge($declared, self::alwaysOn()), SORT_REGULAR);

        return array_values($set);
    }
}
