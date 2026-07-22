# Configuration

All configuration is done in PHP via your `AppServiceProvider` using the `NativeBladeConfig` facade.

```php
use NativeBlade\Config\AndroidConfig;
use NativeBlade\Config\DesktopConfig;
use NativeBlade\Config\IosConfig;
use NativeBlade\Config\Permission;
use NativeBlade\Config\PrivacyApi;
use NativeBlade\Facades\NativeBladeConfig;

NativeBladeConfig::name('My App');

NativeBladeConfig::desktop(function (DesktopConfig $config) {
    $config->identifier('com.myapp.app')
        ->version('1.0.0', 1)
        ->size(1200, 800)
        ->minSize(800, 600)
        ->resizable()
        ->icon('src-tauri/icons/logo.png')
        ->splashBackground('#0a0a0a');
});

NativeBladeConfig::android(function (AndroidConfig $config) {
    $config->identifier('com.myapp.app')
        ->version('1.0.0', 1)
        ->minSdk(28)
        ->targetSdk(35)
        ->orientation('portrait')
        ->statusBar(style: 'dark')
        ->splashBackground('#0a0a0a')
        ->permissions([
            Permission::CAMERA => 'Take photos for your profile',
            Permission::LOCATION => 'Show nearby content',
            Permission::NOTIFICATIONS => 'Receive updates and reminders',
        ]);
});

NativeBladeConfig::ios(function (IosConfig $config) {
    $config->identifier('com.myapp.app')
        ->version('1.0.0', 1)
        ->minIosVersion('15.0')
        ->orientation('portrait')
        ->statusBar(style: 'dark')
        ->splashBackground('#0a0a0a')
        ->permissions([
            Permission::CAMERA => 'Take photos for your profile',
            Permission::LOCATION => 'Show nearby content',
            Permission::PHOTOS => 'Select images from your library',
        ])
        ->privacyManifest([
            PrivacyApi::USER_DEFAULTS => PrivacyApi::USER_DEFAULTS_APP,
            PrivacyApi::FILE_TIMESTAMP => PrivacyApi::FILE_TIMESTAMP_THIRD_PARTY,
            PrivacyApi::SYSTEM_BOOT_TIME => PrivacyApi::BOOT_TIME_ELAPSED,
            PrivacyApi::DISK_SPACE => PrivacyApi::DISK_SPACE_WRITE_CHECK,
        ]);
});

NativeBladeConfig::transition('slide');
```

After changing config, regenerate:

```bash
php artisan nativeblade:config
```

## Desktop Options

### Global

| Method | Description |
|--------|-------------|
| `NativeBladeConfig::name(string)` | Global app name. Becomes `productName` in `tauri.conf.json` and the default window title. Prefer this over `DesktopConfig::title()`. |

### Desktop

| Method | Description |
|--------|-------------|
| `title(string)` | **Deprecated.** Desktop-only window title override. Use `NativeBladeConfig::name()` unless the title bar truly needs different text from the app name. |
| `identifier(string)` | App identifier (com.example.app) |
| `version(string, int)` | Version string + build number |
| `icon(string)` | Path to app icon |
| `size(w, h)` | Default window size |
| `minSize(w, h)` | Minimum window size |
| `resizable(bool)` | Allow window resizing (default `true`) |
| `fullscreen(bool)` | Start in fullscreen (default `false`) |
| `decorations(bool)` | Show native title bar and window chrome (default `true`). Set to `false` for a frameless / custom-chrome look |
| `transparent(bool)` | Transparent window background (default `false`, requires `decorations(false)`) |
| `alwaysOnTop(bool)` | Window stays above other windows (default `false`) |
| `maximized(bool)` | Start maximized (default `false`) |
| `center(bool)` | Center window on screen at launch (default `false`) |
| `position(int $x, int $y)` | Open at a fixed top-left position in pixels. Overrides `center()` |
| `position(string $anchor)` | Open at a named anchor on the primary screen: `center`, `top-left/center/right`, `bottom-left/center/right`. Corner anchors resolve at launch from the monitor size |
| `tray(Closure)` | System tray icon and behavior — see [System Tray](#system-tray) |
| `menu(Closure)` | Native menu bar — see [Menu Builder](#menu-builder) |
| `updateUrl(string)` | URL returning the auto-update version JSON. See [UPDATES.md](./UPDATES.md) |
| `splashBackground(string)` | Splash screen background color |

> Boolean flags are always written to `tauri.conf.json` with their resolved value, even when you remove the method call. Removing `->decorations(false)` restores the default (`true`) on the next `nativeblade:config` run, so the window goes back to having native chrome without manual cleanup of the config file.

### System Tray

The tray is configured through a fluent closure. Calling `->tray(...)` enables the tray icon; omit it to disable.

```php
use NativeBlade\Config\Menu;
use NativeBlade\Config\Tray;

NativeBladeConfig::desktop(function (DesktopConfig $config) {
    $config->tray(function (Tray $t) {
            $t->icon('public/tray.png')
              ->tooltip('My App is running')
              ->menu(function (Menu $m) {
                  $m->item('Show', 'show');
                  $m->item('Hide', 'hide');
                  $m->separator();
                  $m->item('Quit', 'exit');
              })
              ->hideOnClose();
        });
});
```

| Method | Description |
|--------|-------------|
| `icon(string)` | Path to a PNG tray icon (relative to project root) |
| `tooltip(string)` | Tooltip shown on mouse hover |
| `menu(Closure)` | Context menu — receives a [`Menu`](#menu-builder) instance |
| `hideOnClose(bool)` | When `true`, clicking the window's close button hides the window into the tray instead of quitting (default `false`) |

`hideOnClose` lives on `Tray` (not `DesktopConfig`) because it only makes sense when a tray icon exists — without one, the user would have no way to restore the window.

### Menu Builder

The same `Menu` builder powers both the application menu bar (`DesktopConfig::menu()`) and the tray context menu (`Tray::menu()`).

```php
use NativeBlade\Config\Menu;

NativeBladeConfig::desktop(function (DesktopConfig $config) {
    $config->menu(function (Menu $m) {
        $m->submenu('File', function (Menu $file) {
            $file->item('New', '/new')->icon('plus')->accelerator('CmdOrCtrl+N');
            $file->item('Open', '/open')->accelerator('CmdOrCtrl+O');
            $file->separator();
            $file->item('Quit', 'exit')->accelerator('CmdOrCtrl+Q');
        });
        $m->submenu('Help', function (Menu $help) {
            $help->item('About', '/about');
            $help->item('License', '/license')->disabled(! $user->isPro());
        });
    });
});
```

| Method | Returns | Description |
|--------|---------|-------------|
| `item(string $label, string $action)` | `MenuItem` | Clickable item. `$action` is either a route path (`'/dashboard'`) or a command name (`'exit'`, `'show'`, `'hide'`, custom action). |
| `separator()` | `Menu` | Horizontal divider between groups of items. |
| `submenu(string $label, Closure $callback)` | `Menu` | Nested submenu — the closure receives its own `Menu` instance. Submenus can nest arbitrarily deep. |

`item()` returns a `MenuItem` so you can chain modifiers on the same line:

| Modifier | Description |
|----------|-------------|
| `->icon(string $name)` | Icon shown next to the label (icon name resolved by the host platform) |
| `->disabled(bool $value = true)` | Greys out the item. Accepts any boolean expression: `->disabled(! $user->isAdmin())` |
| `->accelerator(string $shortcut)` | Keyboard shortcut, e.g. `'Ctrl+S'`, `'CmdOrCtrl+Shift+P'`. Use `'CmdOrCtrl+'` for cross-platform shortcuts (⌘ on macOS, Ctrl elsewhere). |
| `->checked(bool $value = true)` | Renders the item with a checkmark prefix — for toggle-style entries. |

Action conventions (same for tray menus and menu bars):

| Action | Behavior |
|--------|----------|
| `/path` | Navigates the app to that route |
| `exit` | Quits the application |
| `show` / `hide` | Shows / hides the main window (useful from a tray menu) |
| Custom string | Forwarded to your `wire:nb-bridge` handler or `#[On('nb:menu')]` listener |

## Android Options

| Method | Description |
|--------|-------------|
| `identifier(string)` | Package name |
| `version(string, int)` | versionName + versionCode |
| `minSdk(int)` | Minimum Android SDK (default: 28) |
| `targetSdk(int)` | Target Android SDK (default: 35) |
| `orientation(string)` | `portrait`, `landscape`, or `auto` |
| `statusBar(style)` | Status bar icon style (`'dark'` or `'light'`). Navigation bar matches automatically. Edge-to-edge is enforced, so the background under both system bars comes from your WebView content (paint via CSS with `env(safe-area-inset-top)`), not from a theme color. |
| `fullscreen(bool)` | Hide status bar and navigation bar |
| `allowBackup(bool)` | `android:allowBackup` in the manifest. Android defaults to true, which restores app data on reinstall (including e.g. the UMP ad-consent state) — set `false` for a clean slate on every reinstall |
| `splashBackground(string)` | Native splash screen color |
| `permissions(array)` | Permission declarations with descriptions |
| `manifestMetaData(array)` | Custom `<meta-data>` entries in `<application>` (escape hatch, see below) |

### Note on native debug symbols & AAB size

NativeBlade configures `src-tauri/Cargo.toml` with:

```toml
[profile.release]
strip = "debuginfo"
debug = false
```

This is intentional, and not the conventional Android setup. Some context:

The standard Android way to ship debug symbols is `ndk { debugSymbolLevel = "SYMBOL_TABLE" }` in `app/build.gradle.kts`, which makes AGP extract symbols into `BUNDLE-METADATA/com.android.tools.build.debugsymbols/` inside the AAB. Play Console reads them and the "missing native debug symbols" warning goes away.

**But AGP only processes libs Gradle itself compiles** (CMake / ndk-build). Pre-built libs in `app/src/main/jniLibs/` are silently skipped — neither symbol-extracted nor stripped. Tauri's Android setup ships the Rust binary precisely this way: `cargo build --target ...` produces `target/<arch>/release/libnativeblade_lib.so`, which is then symlinked into `jniLibs/<abi>/`. AGP ignores it.

Result if you leave Rust's debug info baked in: the `.so` lands in the APK at 100–200MB per arch, the AAB balloons to 200MB+, and `BUNDLE-METADATA/debugsymbols/` stays empty. Play Console then warns about both the size **and** missing symbols — both bugs of the same root cause.

The fix is to strip on the Rust side: `strip = "debuginfo"` removes DWARF (the bulk of the bloat) while leaving the ELF symbol table intact. The `.so` drops to 10–25MB per arch, function names still demangle in crash reports, and Play Console no longer flags the size.

**The "missing native debug symbols" warning will still appear**, because `BUNDLE-METADATA/debugsymbols/` is genuinely empty. This is cosmetic — Play Store accepts the upload and crash reporting still resolves function names via the symbol table. To silence the warning entirely, build with `strip = false` to a side-output, zip up the unstripped `.so` files, and upload them manually under **Play Console → App content → Native debug symbols**. For most apps the warning is fine to ignore.

Tracked upstream: <https://issuetracker.google.com/issues/172248255>.

## iOS Options

| Method | Description |
|--------|-------------|
| `identifier(string)` | Bundle ID |
| `version(string, int)` | CFBundleShortVersionString + CFBundleVersion |
| `minIosVersion(string)` | Minimum iOS version (default: 15.0) |
| `orientation(string)` | `portrait`, `landscape`, or `auto` |
| `statusBar(style)` | Status bar style (`dark` or `light`) |
| `fullscreen(bool)` | Hide status bar |
| `splashBackground(string)` | Native launch screen color |
| `permissions(array)` | NSUsageDescription strings |
| `privacyManifest(array)` | PrivacyInfo.xcprivacy API declarations |
| `infoPlist(array)` | Merge arbitrary keys into Info.plist (escape hatch, see below) |

## Permissions

Use `Permission` constants for autocomplete:

```php
use NativeBlade\Config\Permission;

Permission::CAMERA
Permission::LOCATION
Permission::LOCATION_ALWAYS
Permission::LOCATION_COARSE
Permission::MICROPHONE
Permission::STORAGE
Permission::STORAGE_WRITE
Permission::PHOTOS
Permission::PHOTOS_ADD
Permission::NOTIFICATIONS
Permission::VIBRATE
Permission::BIOMETRIC
Permission::NFC
Permission::CONTACTS
Permission::CALENDAR
Permission::BLUETOOTH
```

## Privacy Manifest (iOS)

Required by Apple since 2024. Use `PrivacyApi` constants:

```php
use NativeBlade\Config\PrivacyApi;

// API Categories
PrivacyApi::USER_DEFAULTS
PrivacyApi::FILE_TIMESTAMP
PrivacyApi::SYSTEM_BOOT_TIME
PrivacyApi::DISK_SPACE
PrivacyApi::ACTIVE_KEYBOARDS

// Reason Codes (examples)
PrivacyApi::USER_DEFAULTS_APP           // App functionality
PrivacyApi::FILE_TIMESTAMP_DISPLAY      // Display to user
PrivacyApi::BOOT_TIME_ELAPSED           // Calculate elapsed time
PrivacyApi::DISK_SPACE_WRITE_CHECK      // Check before writing
```


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

`customPlugins()` wires a third-party Tauri 2 plugin into the binary without hand-editing `Cargo.toml`, `lib.rs`, or the capability files. You still author a normal Tauri plugin crate; this only declares how NativeBlade should plug it in. Full guide: [PLUGINS.md → Declarative wiring](PLUGINS.md#declarative-wiring-customplugins).

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

Everything is written inside NativeBlade's plugin markers and the build enables the feature via `--features` automatically. Because it changes the native binary, a custom plugin ships through a store release, not [bundle push](UPDATES.md). Call it from PHP with `NativeBlade::tauriInvoke(...)`.

`ios_plist` only declares which Info.plist keys the plugin needs (each gets a default string). To set the exact usage-description wording, use `infoPlist()` above (e.g. `infoPlist(['NSFaceIDUsageDescription' => 'Use Face ID to unlock'])`); declare a key in one place, not both, so it isn't written twice.


## Deep Links (Universal / App Links)

Verified https links that open the app directly: Universal Links on iOS, App Links on Android. Custom `myapp://` schemes are intentionally not used (they are unverified and show an app chooser on Android). Requires `Plugin::DEEP_LINK`.

Declare your domains and a handler in the AppServiceProvider:

```php
use NativeBlade\Facades\NativeBlade;
use NativeBlade\Facades\NativeBladeConfig;

NativeBladeConfig::deepLinks(['myapp.com', 'www.myapp.com'], function (string $url) {
    return NativeBlade::navigate(parse_url($url, PHP_URL_PATH) ?: '/');
});
```

The handler runs for both a cold start (app launched from a link) and a link tapped while the app is already running, no matter which screen is showing. Return a `NativeResponse` (typically `navigate`) to route the URL.

Run `php artisan nativeblade:config` to wire the native side.

- **Android (App Links):** an `autoVerify` intent-filter per domain is written into the manifest automatically.

### Association files

Verified links need two files hosted on your domain. Generate them with:

```bash
php artisan nativeblade:deeplinks --team=YOUR_TEAM_ID --fingerprint=AA:BB:CC:...
```

This writes `public/.well-known/assetlinks.json` (Android) and `public/.well-known/apple-app-site-association` (iOS), filled from your `identifier()` config. Host both at `https://yourdomain.com/.well-known/` for every domain. The Apple file must be served with **no file extension** and `Content-Type: application/json`.

- `--fingerprint` is the SHA-256 of your Android signing certificate (`keytool -list -v -keystore your.keystore`).
- `--team` is your Apple Developer Team ID.

Omit the flags to scaffold the files with placeholders you fill in later.

### iOS: Associated Domains capability (manual, in Xcode)

iOS needs the entitlement set in Xcode, the same one-time way push notifications are enabled: open the project, select the app target, go to **Signing & Capabilities**, click **+ Capability**, add **Associated Domains**, and enter `applinks:yourdomain.com` for each domain.

## Firebase & Analytics

`google-services.json` (Android) and `GoogleService-Info.plist` (iOS) configure the whole Firebase project, shared by push, analytics, and any other Firebase service. Point at them once, at the top level:

```php
NativeBladeConfig::firebase(
    base_path('secrets/google-services.json'),
    base_path('secrets/GoogleService-Info.plist'),
);
```

`php artisan nativeblade:config` copies `google-services.json` into the Android project and enables the `google-services` Gradle plugin.

> The push `->fcmConfig(...)` is deprecated in favour of this. It still works for backward compatibility, but the file belongs at the top level since every Firebase service reads it.

Enable Firebase Analytics (needs `Plugin::ANALYTICS` and the firebase config above):

```php
NativeBladeConfig::analyticsConfig(
    autoScreenTracking: true,          // log screen_view on every router navigation
    collectionEnabledByDefault: true,  // false ships with collection off (consent-first)
);
```

See [ANALYTICS.md](ANALYTICS.md) for the logging API, screen tracking, and the consent flow.

## Page Transitions

```php
NativeBladeConfig::transition('slide');  // slide + fade (default in demo)
NativeBladeConfig::transition('fade');   // fade only
NativeBladeConfig::transition('zoom');   // zoom in
NativeBladeConfig::transition('none');   // no transition
```

Per-navigation override:

```php
NativeBlade::navigate('/lesson/1')->transition('slide')->toResponse();
```

Available: `none`, `slide`, `fade`. Page transitions are intentionally limited to these three because each one requires its own dual-iframe choreography in the router. For richer element-level animations, see [ANIMATIONS.md](ANIMATIONS.md) (`nb-animation` attribute and `<x-nativeblade-animate>` component).
