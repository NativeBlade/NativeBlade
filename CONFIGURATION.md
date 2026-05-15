# Configuration

All configuration is done in PHP via your `AppServiceProvider` using the `NativeBladeConfig` facade.

```php
use NativeBlade\Config\AndroidConfig;
use NativeBlade\Config\DesktopConfig;
use NativeBlade\Config\IosConfig;
use NativeBlade\Config\Permission;
use NativeBlade\Config\PrivacyApi;
use NativeBlade\Facades\NativeBladeConfig;

NativeBladeConfig::desktop(function (DesktopConfig $config) {
    $config->title('My App')
        ->identifier('com.myapp.app')
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
        ->statusBar(style: 'dark', color: '#0a0a0a')
        ->navigationBar('#0a0a0a')
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

| Method | Description |
|--------|-------------|
| `title(string)` | Window title and product name |
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
| `hideOnClose(bool)` | Hide to tray instead of closing |
| `tray(icon, tooltip, menu)` | System tray configuration |
| `menu(array)` | Native menu bar |
| `updateUrl(string)` | URL returning the auto-update version JSON. See [UPDATES.md](./UPDATES.md) |
| `splashBackground(string)` | Splash screen background color |

> Boolean flags are always written to `tauri.conf.json` with their resolved value, even when you remove the method call. Removing `->decorations(false)` restores the default (`true`) on the next `nativeblade:config` run, so the window goes back to having native chrome without manual cleanup of the config file.

## Android Options

| Method | Description |
|--------|-------------|
| `identifier(string)` | Package name |
| `version(string, int)` | versionName + versionCode |
| `minSdk(int)` | Minimum Android SDK (default: 28) |
| `targetSdk(int)` | Target Android SDK (default: 35) |
| `orientation(string)` | `portrait`, `landscape`, or `auto` |
| `statusBar(style, color)` | Status bar appearance |
| `navigationBar(color)` | Navigation bar color |
| `fullscreen(bool)` | Hide status bar and navigation bar |
| `splashBackground(string)` | Native splash screen color |
| `permissions(array)` | Permission declarations with descriptions |

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

## Menu & Tray Actions

| Value | Behavior |
|-------|----------|
| `/path` | Navigates to a route |
| `/api/action` | Calls a controller route |
| `exit` | Quits the application |
| `show` | Shows the window (tray only) |
| `---` | Separator |
| `[...]` | Submenu (nested array) |

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

Available: `fade`, `slide`, `slide-left`, `slide-up`, `slide-down`, `zoom`, `flip`, `bounce`, `back`, `blur`, `pop`, or any [Animate.css](https://animate.style/) name directly.
