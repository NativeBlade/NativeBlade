# Commands

Every NativeBlade artisan command, grouped by what you reach for it. All are `php artisan nativeblade:<name>`.

## Setup

### `nativeblade:install`
Install NativeBlade into an existing Laravel project: scaffolds `src-tauri`, publishes layouts and the WASM app, patches `config/*` and `.env` for the client-side runtime, and runs the first config + icon pass.

```bash
php artisan nativeblade:install
```

### `nativeblade:add {platform}`
Add a mobile platform scaffold (`src-tauri/gen/...`) to the project.

```bash
php artisan nativeblade:add android
php artisan nativeblade:add ios
```

### `nativeblade:php {version?}`
Set the PHP-WASM runtime version (must match your dev PHP, e.g. `8.5`).

```bash
php artisan nativeblade:php 8.5
```

### `nativeblade:icon {source?} {--bg=}`
Generate every platform icon from one 1024x1024 PNG.

```bash
php artisan nativeblade:icon resources/icon.png
php artisan nativeblade:icon resources/icon.png --bg=#0a0a0a
```

## Develop

### `nativeblade:dev {--platform=} {--host=} {--port=} {--build}`
Start the dev server with hot reload. `--platform` is `desktop` (default), `android`, `ios`, or `portal`. `--host` overrides the auto-detected LAN IP for mobile; `--port` is the Vite port (default `1420`); `--build` runs against built assets instead of the Vite server (no HMR).

```bash
php artisan nativeblade:dev                      # desktop, HMR
php artisan nativeblade:dev --platform=android   # install + run on device, HMR
php artisan nativeblade:dev --platform=portal    # serve for the Portal app (QR + URL)
```

### `nativeblade:serve {--host=} {--port=}`
Serve just the Vite dev server + live Laravel bundle (no QR, no platform install). Build the Laravel bundle, watch for PHP/Blade changes, and serve. Pair it with a preview build (see `nativeblade:build --host`) so an installed dev-client connects to it. `--host` is the IP advertised for HMR (auto-detected if empty); `--port` defaults to `1420`.

```bash
php artisan nativeblade:serve
php artisan nativeblade:serve --host=192.168.1.11 --port=1420
```

### `nativeblade:component {name?}`
Scaffold a new NativeBlade component.

```bash
php artisan nativeblade:component card
```

### `nativeblade:config`
Regenerate the Tauri config (manifest, capabilities, Info.plist, Gradle, etc.) from your PHP config. Runs automatically inside `dev`, `serve`, and `build`; run it directly after changing `AppServiceProvider`.

```bash
php artisan nativeblade:config
```

## Build & release

### `nativeblade:build {platform} {--targets=} {--host=} {--port=}`
Build the app. `platform` is `android`, `ios`, or `desktop`. `--targets` limits Android architectures (`aarch64,armv7,x86_64,i686`, default all).

`--host` switches to a **preview / dev-client build**: an installable debug artifact that loads the frontend live from a running dev server (HMR + live Laravel bundle) instead of bundling static assets. Install it once, then run `nativeblade:serve`/`dev` at the same host. Debug-only, never for stores. `--port` matches the dev server port (default `1420`).

```bash
php artisan nativeblade:build android
php artisan nativeblade:build android --targets=aarch64,armv7
php artisan nativeblade:build ios
php artisan nativeblade:build desktop

# preview / dev-client (loads live from the dev server)
php artisan nativeblade:build android --host=192.168.1.11
```

### `nativeblade:bundle {--tag=} {--channel=} {--shell=} {--no-dev}`
Build only the Laravel bundle (`laravel-bundle.json.gz`) for OTA bundle push, without rebuilding the native shell. `--tag` versions the output, `--channel` publishes under a release channel (e.g. `beta`), `--shell` sets the minimum shell version, `--no-dev` runs `composer install --no-dev` first.

```bash
php artisan nativeblade:bundle
php artisan nativeblade:bundle --channel=beta --tag=1.4.0
```

### `nativeblade:sign {platform} {--alias=} {--validity=} {--keystore-path=} {--keystore-password=} {--key-password=}`
Configure release signing: Android keystore or iOS `ExportOptions.plist`. The keystore options support CI mode (pass paths/passwords non-interactively).

```bash
php artisan nativeblade:sign android
php artisan nativeblade:sign ios
```

### `nativeblade:deeplinks {--team=} {--fingerprint=}`
Generate the `.well-known` association files (`assetlinks.json` + `apple-app-site-association`) for verified universal/app links. `--team` is your Apple Team ID; `--fingerprint` is the Android signing cert SHA-256.

```bash
php artisan nativeblade:deeplinks --team=ABCDE12345 --fingerprint=AA:BB:...
```

## Tooling

### `nativeblade:mcp {--test}`
Start the MCP server for AI coding agents (stdio JSON-RPC). `--test` runs a self-test and exits.

```bash
php artisan nativeblade:mcp
php artisan nativeblade:mcp --test
```

## Typical flows

**First run**
```bash
php artisan nativeblade:install
php artisan nativeblade:dev
```

**Mobile dev with HMR on a device**
```bash
php artisan nativeblade:add android
php artisan nativeblade:dev --platform=android
```

**Preview / dev-client (install once, iterate live)**
```bash
php artisan nativeblade:build android --host=192.168.1.11   # install the APK once
php artisan nativeblade:serve --host=192.168.1.11           # serve; the app reconnects with HMR
```

**Release**
```bash
php artisan nativeblade:sign android
php artisan nativeblade:build android
```

**OTA update (no store)**
```bash
php artisan nativeblade:bundle --channel=stable
```
