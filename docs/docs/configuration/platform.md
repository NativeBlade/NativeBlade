---
title: "Platform Options"
description: "Per-platform build options for desktop, Android, and iOS."
---

# Platform Options

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
| `tray(Closure)` | System tray icon and behavior, see [System Tray](#system-tray) |
| `menu(Closure)` | Native menu bar, see [Menu Builder](#menu-builder) |
| `updateUrl(string)` | URL returning the auto-update version JSON. See [Updates](/guides/updates/) |
| `splashBackground(string)` | Splash screen background color |

> Boolean flags are always written to `tauri.conf.json` with their resolved value, even when you remove the method call. Removing `->decorations(false)` restores the default (`true`) on the next `nativeblade:config` run, so the window goes back to having native chrome without manual cleanup of the config file.

### Menus & Tray

The desktop menu bar and system tray have their own page: [Menus & Tray](/desktop/menus-tray/).

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
| `allowBackup(bool)` | `android:allowBackup` in the manifest. Android defaults to true, which restores app data on reinstall (including e.g. the UMP ad-consent state), set `false` for a clean slate on every reinstall |
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

**But AGP only processes libs Gradle itself compiles** (CMake / ndk-build). Pre-built libs in `app/src/main/jniLibs/` are silently skipped, neither symbol-extracted nor stripped. Tauri's Android setup ships the Rust binary precisely this way: `cargo build --target ...` produces `target/<arch>/release/libnativeblade_lib.so`, which is then symlinked into `jniLibs/<abi>/`. AGP ignores it.

Result if you leave Rust's debug info baked in: the `.so` lands in the APK at 100–200MB per arch, the AAB balloons to 200MB+, and `BUNDLE-METADATA/debugsymbols/` stays empty. Play Console then warns about both the size **and** missing symbols, both bugs of the same root cause.

The fix is to strip on the Rust side: `strip = "debuginfo"` removes DWARF (the bulk of the bloat) while leaving the ELF symbol table intact. The `.so` drops to 10–25MB per arch, function names still demangle in crash reports, and Play Console no longer flags the size.

**The "missing native debug symbols" warning will still appear**, because `BUNDLE-METADATA/debugsymbols/` is genuinely empty. This is cosmetic, Play Store accepts the upload and crash reporting still resolves function names via the symbol table. To silence the warning entirely, build with `strip = false` to a side-output, zip up the unstripped `.so` files, and upload them manually under **Play Console → App content → Native debug symbols**. For most apps the warning is fine to ignore.

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

