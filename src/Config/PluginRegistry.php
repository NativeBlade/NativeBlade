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

            Plugin::IN_APP_REVIEW => [
                'feature' => 'in_app_review',
                'feature_crate' => 'tauri-plugin-nativeblade-review',
                'rust_init' => 'tauri_plugin_nativeblade_review::init()',
                'mobile_only' => true,
                'mobile_capabilities' => ['nativeblade-review:default'],
            ],

            Plugin::NATIVE_NAV => [
                'feature' => 'native_nav',
                'feature_crate' => 'tauri-plugin-nativeblade-native-nav',
                'rust_init' => 'tauri_plugin_nativeblade_native_nav::init()',
                'mobile_only' => true,
                'mobile_capabilities' => ['nativeblade-native-nav:default'],
            ],

            Plugin::SECURE_STORAGE => [
                'feature' => 'secure_storage',
                'feature_crate' => 'tauri-plugin-nativeblade-secure-storage',
                'rust_init' => 'tauri_plugin_nativeblade_secure_storage::init()',
                'mobile_only' => true,
                'mobile_capabilities' => ['nativeblade-secure-storage:default'],
            ],

            Plugin::SHARING => [
                'feature' => 'sharing',
                'feature_crate' => 'tauri-plugin-nativeblade-sharing',
                'rust_init' => 'tauri_plugin_nativeblade_sharing::init()',
                'mobile_only' => true,
                'mobile_capabilities' => ['nativeblade-sharing:default'],
            ],

            Plugin::ANALYTICS => [
                'feature' => 'analytics',
                'feature_crate' => 'tauri-plugin-nativeblade-analytics',
                'rust_init' => 'tauri_plugin_nativeblade_analytics::init()',
                'mobile_only' => true,
                'mobile_capabilities' => ['nativeblade-analytics:default'],
            ],

            Plugin::ADMOB => [
                'feature' => 'admob',
                'feature_crate' => 'tauri-plugin-nativeblade-admob',
                'rust_init' => 'tauri_plugin_nativeblade_admob::init()',
                'mobile_only' => true,
                'mobile_capabilities' => ['nativeblade-admob:default'],
            ],

            Plugin::PAYMENTS => [
                'feature' => 'payments',
                'feature_crate' => 'tauri-plugin-nativeblade-payments',
                'rust_init' => 'tauri_plugin_nativeblade_payments::init()',
                'mobile_only' => true,
                'mobile_capabilities' => ['nativeblade-payments:default'],
            ],

            Plugin::NETWORK => [
                'feature' => 'network',
                'feature_crate' => 'tauri-plugin-nativeblade-network',
                'rust_init' => 'tauri_plugin_nativeblade_network::init()',
                'mobile_only' => true,
                'mobile_capabilities' => ['nativeblade-network:default'],
            ],

            Plugin::TASK_MANAGER => [
                'feature' => 'task_manager',
                'feature_crate' => 'tauri-plugin-nativeblade-tasks',
                'rust_init' => 'tauri_plugin_nativeblade_tasks::init()',
                'capabilities' => ['nativeblade-tasks:default'],
            ],

            Plugin::SENSORS => [
                'feature' => 'sensors',
                'feature_crate' => 'tauri-plugin-nativeblade-sensors',
                'rust_init' => 'tauri_plugin_nativeblade_sensors::init()',
                'mobile_only' => true,
                'mobile_capabilities' => ['nativeblade-sensors:default'],
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
                 'capabilities' => [
                    [
                        'identifier' => 'http:default',
                        'allow' => [
                            ['url' => 'http://*:*/*'],
                            ['url' => 'https://*:*/*'],
                        ],
                    ],
                ],
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
                // The permissions enable the command TYPES; Tauri still requires a
                // command scope naming which programs `Command.create(name)` may
                // launch, or it errors "Scoped command <name> not found". Tauri
                // validates each IPC against ITS OWN permission's scope, so the
                // allowlist goes on BOTH allow-execute (captured) and allow-spawn
                // (streamed — what the Studio uses). See shellScope().
                'capabilities' => [
                    'shell:allow-open',
                    ['identifier' => 'shell:allow-execute', 'allow' => self::shellScope()],
                    ['identifier' => 'shell:allow-spawn', 'allow' => self::shellScope()],
                    'shell:allow-stdin-write',
                    'shell:allow-kill',
                ],
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

    /**
     * Shell command scope — the programs `Command.create(name)` may launch.
     * Attached to both allow-execute and allow-spawn because Tauri validates
     * each IPC against its own permission's scope. `args: true` allows any
     * arguments since the whole command line rides as an arg to cmd/sh; the
     * remaining entries are the terminals used by openTerminal.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function shellScope(): array
    {
        return [
            ['name' => 'cmd', 'cmd' => 'cmd', 'args' => true],
            ['name' => 'sh', 'cmd' => 'sh', 'args' => true],
            ['name' => 'bash', 'cmd' => 'bash', 'args' => true],
            ['name' => 'cmd.exe', 'cmd' => 'cmd.exe', 'args' => true],
            ['name' => 'wt.exe', 'cmd' => 'wt.exe', 'args' => true],
            ['name' => 'powershell.exe', 'cmd' => 'powershell.exe', 'args' => true],
            ['name' => 'osascript', 'cmd' => 'osascript', 'args' => true],
            ['name' => 'gnome-terminal', 'cmd' => 'gnome-terminal', 'args' => true],
            ['name' => 'konsole', 'cmd' => 'konsole', 'args' => true],
            ['name' => 'xfce4-terminal', 'cmd' => 'xfce4-terminal', 'args' => true],
            ['name' => 'xterm', 'cmd' => 'xterm', 'args' => true],
        ];
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
