---
title: "Plugins"
description: "The NativeBlade facade and the plugin system."
---

# Native Plugins

NativeBlade ships with every major Tauri 2 plugin pre-registered, pre-permissioned, and bridged into PHP. You don't need to touch Rust, edit capabilities, or write JavaScript, just call the bridge from your Livewire component or Blade template.

This page covers the **plugin system**: which plugins exist, how to declare only the ones you use, and how to add your own. To trigger native actions from PHP, see [Action Response](/core/action-response/). For saved state and runtime checks, see [State Management](/core/state/) and [Platform Detection](/core/platform-detection/).

## Declaring Plugins

By default, every plugin ships in your build. For production apps you should **declare only what you actually use**, App Store and Play Store reviewers flag binaries that reference unused permissions, and unused plugins also bloat the binary.

Declare your plugin set in `app/Providers/AppServiceProvider.php`:

```php
use NativeBlade\Config\Plugin;
use NativeBlade\Facades\NativeBladeConfig;

NativeBladeConfig::plugins([
    Plugin::MEDIA,        // camera, gallery, video picker
    Plugin::PUSH,         // FCM / APNS push notifications
    Plugin::HAPTICS,      // vibration, taptic feedback
    Plugin::GEOLOCATION,  // GPS
]);
```

Run `php artisan nativeblade:config` to apply. NativeBlade regenerates `Cargo.toml`, capabilities, and `package.json` so only the listed plugins compile in. Cargo skips the unused crates entirely, their code never reaches the binary.

### How it works

The `plugins()` declaration drives a Cargo feature toggle on every optional crate:

```toml
# Generated in your src-tauri/Cargo.toml
[features]
default = ["custom-protocol"]
haptics = ["dep:tauri-plugin-haptics"]
media = ["dep:tauri-plugin-nativeblade-media"]
push = ["dep:tauri-plugin-nativeblade-push"]
```

When you run `nativeblade:dev` or `nativeblade:build`, the CLI passes `--features haptics,media,push` to Cargo. Optional crates whose feature isn't enabled are not downloaded, compiled, or linked.

### Always-on plugins

`dialog`, `os`, `process`, `store`, `fs`, and `opener` are always included regardless of declaration, NativeBlade core depends on them.

### Available plugins

Every plugin below is opt-in: declare the ones you use in
`NativeBladeConfig::plugins([...])`. Each row links to its full section.

**Mobile**

| Plugin | What it provides |
|--------|------------------|
| [`Plugin::MEDIA`](/mobile/media/) | Camera, gallery, and video picker. `camera()`, `gallery()`, `video()`. |
| [`Plugin::PUSH`](/mobile/push/) | FCM and APNS push, plus local and scheduled notifications. `notification()`. |
| [`Plugin::SENSORS`](/mobile/sensors/) | Accelerometer, gyroscope, magnetometer, barometer, light. `sensors()`. |
| [`Plugin::GEOLOCATION`](/mobile/geolocation/) | GPS and network location. `nb:geolocation` event. |
| [`Plugin::BIOMETRIC`](/mobile/biometric/) | Face ID, Touch ID, fingerprint. `biometric()`. |
| [`Plugin::BARCODE_SCANNER`](/mobile/barcode-scanner/) | QR and barcode via the camera. `scan()`. |
| [`Plugin::NFC`](/mobile/nfc/) | Read NFC tags. `nfc()`. |
| [`Plugin::HAPTICS`](/mobile/haptics/) | Vibration and haptics. `impact()`, `vibrate()`, `selection()`. |
| [`Plugin::IN_APP_REVIEW`](/mobile/in-app-review/) | Native review prompt. `requestReview()`. |
| [`Plugin::SECURE_STORAGE`](/mobile/secure-storage/) | Encrypted key-value. `setSecure()`, `getSecure()`. |
| [`Plugin::SHARING`](/mobile/sharing/) | Native share sheet. `share()`. |
| [`Plugin::ADMOB`](/mobile/admob/) | Rewarded, interstitial, and banner ads. |
| [`Plugin::PAYMENTS`](/mobile/payments/) | In-app purchases and subscriptions. |
| [`Plugin::ANALYTICS`](/mobile/analytics/) | Firebase events, screens, user properties. |
| [`Plugin::TASK_MANAGER`](/mobile/tasks/) | Background courier: periodic `fetch` and `post`. |
| [`Plugin::NATIVE_NAV`](/mobile/native-nav/) | Native page transitions: native compositor on Android, CSS fallback elsewhere. |

**Cross-platform**

| Plugin | What it provides |
|--------|------------------|
| [`Plugin::NETWORK`](/core/network/) | Connectivity status and the `nb:network-changed` event. `networkStatus()`. |
| [`Plugin::CLIPBOARD`](/mobile/clipboard/) | Read and write the clipboard. `clipboardWrite()`, `clipboardRead()`. |
| [`Plugin::UPLOAD`](/core/upload/) | Streaming HTTP uploads. `upload($path, $url)`. |
| [`Plugin::HTTP`](/core/http/) | Native HTTP requests (bypasses CORS). |
| [`Plugin::DEEP_LINK`](/configuration/deep-links/) | Universal and app links via `NativeBladeConfig::deepLinks()`. |

**Desktop**

| Plugin | What it provides |
|--------|------------------|
| [`Plugin::SHELL`](/desktop/shell/) | Run external commands (disabled by default). `shell()`. |

Desktop menus, the system tray, window controls, and multi-window each have
their own page under Desktop: [Menus & Tray](/desktop/menus-tray/),
[Process & Window](/desktop/process/), and [Windows](/desktop/windows/).

> **Behavior when missing:** if a Livewire action calls `NativeBlade::camera()` without declaring `Plugin::MEDIA`, the build fails with a Cargo error pointing at the missing permission. This is intentional, fail at build time, not at runtime.

### Skipping declaration

If you don't call `NativeBladeConfig::plugins([...])`, all plugins are included by default. Useful while prototyping; switch to explicit declaration before shipping.

---

## Using Third-Party Tauri Plugins

You don't have to fork NativeBlade to use Tauri plugins that aren't built in. There are two paths depending on how much glue you want to write.

### Quick path: `tauriInvoke` from PHP (recommended)

NativeBlade ships a generic action that calls **any** Tauri plugin command directly from PHP, no JS handler required.

**Example: using `tauri-plugin-fingerprint`** (a hypothetical third-party plugin)

1. **Install the Rust crate.** Add it to `src-tauri/Cargo.toml` *outside* the `# nativeblade:plugins` markers (anything outside the markers is preserved across `nativeblade:config` runs):

   ```toml
   [dependencies]
   tauri-plugin-fingerprint = "0.1"
   ```

2. **Initialize it** in `src-tauri/src/lib.rs` outside the `// nativeblade:plugins` markers:

   ```rust
   builder.plugin(tauri_plugin_fingerprint::init())
   ```

3. **Grant capabilities** in `src-tauri/capabilities/default.json` outside the entries managed by NativeBlade:

   ```json
   { "permissions": ["fingerprint:default"] }
   ```

4. **Call it from PHP**:

   ```php
   public function login()
   {
       return NativeBlade::tauriInvoke(
           command: 'plugin:fingerprint|authenticate',
           args: ['reason' => 'Confirm to log in'],
           emit: 'fingerprint-result',
       )->toResponse();
   }

   #[On('nb:fingerprint-result')]
   public function onAuth($result = null, $error = null)
   {
       if ($error) { $this->message = $error; return; }
       if ($result?->authenticated) { $this->redirect('/dashboard'); }
   }
   ```

That's it. No JS file, no `bridge.js` patch, no extension to NativeResponse. Anything `invoke()`-able from `@tauri-apps/api/core` works through `tauriInvoke`.

### Declarative wiring: `customPlugins()`

The quick path above asks you to hand-edit three Tauri files (Cargo.toml, lib.rs, capabilities). You don't have to. Declare the plugin from your `AppServiceProvider` and `php artisan nativeblade:config` wires all of it for you, exactly like a built-in plugin.

You still author a normal Tauri 2 plugin crate (its `android/` and `ios/` native sources compile through Tauri's own toolchain). `customPlugins()` only tells NativeBlade how to plug that crate into your app.

```php
use NativeBlade\Config\CustomPlugin;

NativeBladeConfig::customPlugins([
    CustomPlugin::init(
        feature: 'fingerprint',                       // Cargo feature name
        feature_crate: 'tauri-plugin-fingerprint',    // crate name
        rust_init: 'tauri_plugin_fingerprint::init()',// expression added to .plugin(...)
        version: '0.1',                               // crates.io, OR use path: for a local/vendor crate
        capabilities: ['fingerprint:default'],
        android_permissions: ['USE_BIOMETRIC'],
        ios_plist: ['NSFaceIDUsageDescription'],
        mobile_only: false,                           // true gates the crate to Android/iOS only
    ),
]);
```

On `nativeblade:config`, NativeBlade writes, between its managed markers:

- **Cargo.toml**, the dependency line (`{ version = "0.1", optional = true }` or `{ path = "...", optional = true }`, mobile-only crates land in the Android/iOS target section) plus the `[features]` entry.
- **src/lib.rs**, the `.plugin(...)` init, gated by the Cargo feature (and by `target_os` when `mobile_only`).
- **capabilities/default.json** (or **mobile.json**), the permissions you listed.
- **AndroidManifest.xml** / **Info.plist**, the OS permissions and usage-description keys.
- **package.json**, any `npm` guest bindings you declared.

The build (`nativeblade:build`) enables the feature automatically via `--features`.

| Field | Required | Notes |
|---|---|---|
| `feature` | yes | Cargo feature name. Must not collide with a built-in feature (see below). |
| `feature_crate` | yes | Crate name, used for the dependency and `dep:<crate>`. |
| `rust_init` | yes | The init expression added to the `.plugin(...)` chain. |
| `version` / `path` | one of | `version` for a crates.io crate; `path` for a local (`../plugins/...`) or vendor (`vendor/org/pkg`) crate. |
| `mobile_only` | no | When true, the crate and its init are gated to Android/iOS. |
| `capabilities` / `mobile_capabilities` | no | Tauri permissions added to `default.json` / `mobile.json`. |
| `android_permissions` / `ios_plist` | no | Manifest `uses-permission` entries / Info.plist usage-description keys. |
| `npm` | no | Guest-JS package(s), `name => version`. |

**iOS usage-description text.** `ios_plist` only declares which keys the plugin needs; each ships with a sensible default string. To set the exact wording the App Store reviewer sees, the app sets it with `IosConfig::infoPlist(['NSFaceIDUsageDescription' => 'Use Face ID to unlock'])`, the [iOS escape hatch](/configuration/native/). Declare a given key in one place, not both, or it lands twice in `Info.plist`.

**Name collisions throw.** A custom `feature` may not reuse a built-in feature name (`media`, `biometric`, `haptics`, etc.), declared or not. This prevents a package from silently shadowing a first-party plugin. To replace a built-in, drop it from `plugins([...])` and give your plugin a different feature name.

**It changes the native binary.** Adding a custom plugin is a shell change, so it ships through a store release (or notarized desktop update), never through [bundle push](/guides/updates/). Existing installs need the new shell before a bundle that calls the plugin will work.

Once wired, call it from PHP via [`tauriInvoke`](#quick-path-tauriinvoke-from-php-recommended) (or wrap it in a typed API as below).

### Idiomatic path: extend the bridge

If you want a strongly-typed PHP API like the built-ins (`NativeBlade::camera()`, `NativeBlade::scan()`, etc.), wrap your invocation in:

1. A method on `NativeResponse` (or a Composer package, see [Composer plugin discovery](#composer-plugin-discovery))
2. Optionally, a builder class in `src/Plugins/` for fluent configuration
3. Optionally, a Blade component (`x-mypackage-fingerprint`) for declarative use

Underneath, it can still call `tauriInvoke`, that's the supported escape hatch. You only need to add a case to the JS bridge if your plugin needs custom JS-side glue (e.g. compressing image data before passing it to PHP).

### Custom Rust commands

If your need isn't covered by an existing Tauri plugin, write a custom Rust command in `src-tauri/src/lib.rs` and register it in `tauri::generate_handler![...]`. Then from PHP:

```php
NativeBlade::tauriInvoke(
    command: 'my_custom_command',
    args: [...],
    emit: 'custom-result',
)->toResponse();
```

For the full Tauri plugin tutorial, see the [Tauri 2 Plugin docs](https://v2.tauri.app/plugin/).

## Composer plugin discovery

You can publish a NativeBlade plugin as a regular Composer package. NativeBlade auto-discovers them via `composer.json`'s `extra.nativeblade` field, same pattern Laravel uses for its own packages.

```json
{
    "name": "myorg/my-nativeblade-plugin",
    "description": "Toast notifications for NativeBlade",
    "type": "library",
    "license": "MIT",
    "require": {
        "php": "^8.3",
        "nativeblade/nativeblade": "*"
    },
    "extra": {
        "nativeblade": {
            "components": {
                "toast": "MyOrg\\Plugin\\ToastComponent"
            },
            "views": "resources/views",
            "js": "resources/js"
        }
    }
}
```

After `composer require myorg/my-nativeblade-plugin`, the consumer can use:

```blade
<x-nativeblade-toast message="Saved!" />
```

No service provider registration needed. Components, views, and JS modules are picked up automatically from `vendor/composer/installed.json`.

---

## See Also

- [Lifecycle](/core/lifecycle/), bridge internals and the exit/re-execute pattern
- [Scheduler](/core/scheduler/), running code on a schedule
- [Filesystem](/core/filesystem/), `Storage::disk('native')` and `native_path()`
- [Database](/core/database/), external MySQL/PostgreSQL via the `nativeblade-db` driver
- [Directives](/core/directives/), full list of `wire:nb-*` directives and attributes
- [Configuration](/configuration/overview/), platform-specific configuration
