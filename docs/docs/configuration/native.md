---
title: "Custom Native Config"
description: "Escape hatches: raw Info.plist, AndroidManifest, and custom Tauri plugins."
---

# Custom Native Config

## Custom native config (escape hatch)

NativeBlade models the common platform keys with dedicated methods (orientation, status bar, permissions, and so on). For anything it does not model, two escape hatches let you write raw native config from PHP without opening Xcode or Android Studio.

> Use these only for app-specific needs. The built-in plugins already write the native keys they require, so reach for these methods only when you need a key that no plugin covers.

### iOS: `infoPlist(array)`

Merges arbitrary keys into the generated `Info.plist`. Values may be strings, booleans, integers, floats, and nested arrays (lists become `<array>`, associative arrays become `<dict>`).

```php
NativeBladeConfig::ios(function (IosConfig $config) {
    $config->infoPlist([
        'ITSAppUsesNonExemptEncryption' => false,
        'LSApplicationQueriesSchemes' => ['whatsapp', 'tel'],
        'UIBackgroundModes' => ['audio'],
    ]);
});
```

Keys NativeBlade already manages (orientation, status bar, version, app name, plus the AdMob-managed `GADApplicationIdentifier` and `NSUserTrackingUsageDescription`) are ignored with a build warning. Use their dedicated methods instead.

**`SKAdNetworkItems` is the exception:** it is additive, not single-value. When AdMob is configured NativeBlade always includes Google's network, and anything you add here is **merged** in (deduped by identifier) rather than ignored, so you can declare the SKAdNetwork ids of other attribution / ads SDKs. Both the array-of-dicts and the plain-string forms are accepted:

```php
$config->infoPlist([
    'SKAdNetworkItems' => [
        ['SKAdNetworkIdentifier' => 'v9wttpbfk9.skadnetwork'],
        'n38lu8286q.skadnetwork',
    ],
]);
```

This works whether or not AdMob is enabled (without AdMob, only your ids are written).

### Android: `manifestMetaData(array)`

Adds `<meta-data>` entries to the `<application>` element of `AndroidManifest.xml`. Booleans are written as `"true"` / `"false"`.

```php
NativeBladeConfig::android(function (AndroidConfig $config) {
    $config->manifestMetaData([
        'com.google.android.gms.ads.APPLICATION_ID' => 'ca-app-pub-xxxxxxxx~yyyyyyyy',
    ]);
});
```

Both are written inside NativeBlade's config markers, so running `php artisan nativeblade:config` again replaces them cleanly and removing the call removes the entries. Anything you add manually outside the markers is preserved.


## Custom Tauri plugins

`customPlugins()` wires a third-party Tauri 2 plugin into the binary without hand-editing `Cargo.toml`, `lib.rs`, or the capability files. You still author a normal Tauri plugin crate; this only declares how NativeBlade should plug it in. Full guide: [Plugins → declarative wiring](/core/plugins/).

```php
use NativeBlade\Config\CustomPlugin;

NativeBladeConfig::customPlugins([
    CustomPlugin::init(
        feature: 'fingerprint',
        feature_crate: 'tauri-plugin-fingerprint',
        rust_init: 'tauri_plugin_fingerprint::init()',
        version: '0.1',                       // or path: '../plugins/fingerprint' (local/vendor crate)
        capabilities: ['fingerprint:default'],
        android_permissions: ['USE_BIOMETRIC'],
        ios_plist: ['NSFaceIDUsageDescription'],
        mobile_only: false,
    ),
]);
```

| Field | Required | Description |
|---|---|---|
| `feature` | yes | Cargo feature name. Must not collide with a built-in feature, or `nativeblade:config` throws. |
| `feature_crate` | yes | Crate name (used for the dependency and `dep:<crate>`). |
| `rust_init` | yes | Expression added to the `.plugin(...)` chain in `lib.rs`. |
| `version` / `path` | one of | `version` for a crates.io crate; `path` for a local or `vendor/` crate. |
| `mobile_only` | no | When true, gates the crate and its init to Android/iOS. |
| `capabilities` / `mobile_capabilities` | no | Tauri permissions added to `default.json` / `mobile.json`. |
| `android_permissions` / `ios_plist` | no | `uses-permission` entries / Info.plist usage-description keys. |
| `npm` | no | Guest-JS package(s), `name => version`. |

Everything is written inside NativeBlade's plugin markers and the build enables the feature via `--features` automatically. Because it changes the native binary, a custom plugin ships through a store release, not [bundle push](/guides/updates/). Call it from PHP with `NativeBlade::tauriInvoke(...)`.

`ios_plist` only declares which Info.plist keys the plugin needs (each gets a default string). To set the exact usage-description wording, use `infoPlist()` above (e.g. `infoPlist(['NSFaceIDUsageDescription' => 'Use Face ID to unlock'])`); declare a key in one place, not both, so it isn't written twice.


