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
| `statusBar(style, color)` | Status bar appearance |
| `navigationBar(color)` | Navigation bar color |
| `fullscreen(bool)` | Hide status bar and navigation bar |
| `splashBackground(string)` | Native splash screen color |
| `permissions(array)` | Permission declarations with descriptions |

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
