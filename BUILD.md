# Build

## Command

```bash
php artisan nativeblade:build android
php artisan nativeblade:build ios
php artisan nativeblade:build desktop
```

## What Happens

1. Reads version from `AppServiceProvider`
2. Applies all configs (`nativeblade:config`)
3. Builds frontend (`npm run build` → Vite → WASM bundle)
4. Builds platform binary (`npx tauri build`)
5. Copies artifacts to `build/{platform}/{version}.{ext}`

## Output

```
build/
├── android/
│   ├── 1.0.0.apk
│   └── 1.0.0.aab
├── ios/
│   └── 1.0.0.ipa
└── desktop/
    ├── 1.0.0.msi      (Windows)
    ├── 1.0.0.dmg      (macOS)
    ├── 1.0.0.deb      (Linux)
    └── 1.0.0.rpm      (Linux)
```

## Prerequisites

| Platform | Requirements |
|----------|-------------|
| Desktop | Rust, Node.js 20+ |
| Android | Android SDK, Java 17+, Rust |
| iOS | macOS, Xcode, Rust |

## Production Preview (`--build`)

Run the app using the **pre-built production bundle** instead of the Vite dev server. No HMR, no dev server — exactly what ships to your users, but running locally so you can iterate on Rust code, native shell, and the boot flow without repackaging.

```bash
php artisan nativeblade:dev --build
php artisan nativeblade:dev --platform=android --build
php artisan nativeblade:dev --platform=ios --build
```

What it does:

1. Bundles your Laravel app into `public/laravel-bundle.json.gz` (gzip, level 9)
2. Runs `npx vite build --config vite.wasm.config.js` → `dist-wasm/`
3. Points Tauri at `dist-wasm/` via `frontendDist` (skips Vite dev server entirely)

Use it to validate the real production payload — the same bundle that will ship to Play Store / App Store / your installer — before running the full `nativeblade:build`.

## All CLI Commands

| Command | Description |
|---------|-------------|
| `nativeblade:install` | Interactive setup — scaffolds Tauri project, layouts, config |
| `nativeblade:add android` | Add Android platform |
| `nativeblade:add ios` | Add iOS platform (macOS only) |
| `nativeblade:dev` | Start desktop dev with hot reload |
| `nativeblade:dev --platform=android` | Run on Android device with HMR |
| `nativeblade:dev --platform=ios` | Run on iOS simulator with HMR |
| `nativeblade:dev --build` | Run locally using the production bundle (no HMR, no Vite) |
| `nativeblade:config` | Regenerate Tauri configs from PHP |
| `nativeblade:build {platform}` | Build for android, ios, or desktop |
| `nativeblade:icon` | Generate all platform icons from 1024x1024 PNG |
| `nativeblade:php {version?}` | Set PHP WASM version (8.3, 8.4, 8.5) |
| `nativeblade:component {name}` | Create a custom component |

## Bundle Size — the absurd part

A full Laravel 12 + Livewire 3 app, everything included — routing, Eloquent, migrations, Blade, Carbon, Symfony console, the entire framework — packaged into a **single gzipped JSON** that the PHP-WASM runtime decompresses on boot.

**Real numbers from a production-ready install:**

```
Files: 5,578
Bundle size:   33.80 MB  (JSON)
Bundle size:    6.13 MB  (gzip)
```

**Top 10 packages by size:**

| Size | Package |
|------|---------|
| 7.49 MB | laravel/framework |
| 7.48 MB | livewire/livewire |
| 1.75 MB | psy/psysh |
| 1.55 MB | nesbot/carbon |
| 1.43 MB | nativeblade/nativeblade |
| 1.03 MB | public/ |
| 0.93 MB | nikic/php-parser |
| 0.66 MB | composer/autoload_static.php |
| 0.60 MB | composer/autoload_classmap.php |
| 0.58 MB | symfony/console |

**~6 MB gzipped — your entire Laravel app.** That's smaller than a single hero image on most marketing sites.

### How we got there

- **Gzip on disk, gzip on the wire** — `laravel-bundle.json.gz` is decompressed client-side using the browser's native `DecompressionStream('gzip')`. No zlib.js shim, no extra library
- **Locale-aware filtering** — Carbon's `Lang/` and Symfony's `Resources/translations/` ship only the locales declared in your `.env` (`APP_LOCALE` + `APP_FALLBACK_LOCALE`, plus `en` as fallback). Drop 100+ locale files you'll never use
- **Dev-only noise excluded** — tests, docs, stubs, benchmarks, `.github/`, `.idea/`, PHPStan/Psalm/Pint/Rector/Infection configs, `CHANGELOG`/`UPGRADE`/`CONTRIBUTING`/`SECURITY` files, `vendor/bin/`, `.ide-helper.php`, `.phpstorm.meta.php`
- **NativeBlade source stripped** — the package's own `js/`, `rust/`, and `resources/js/` directories are already compiled into the Tauri binary, so they're excluded from the WASM bundle (saved ~4.8 MB)
- **`composer install --no-dev`** runs automatically before bundling, so `phpunit`, `pint`, `sail`, etc. never make it into the output

### Want it even smaller?

Move `laravel/tinker` to `require-dev` in `composer.json` — it pulls in `psy/psysh` (~1.75 MB) and `nikic/php-parser` (~0.93 MB), neither of which your production app uses. That alone drops the bundle below **~30 MB raw / ~5.3 MB gzipped**.

```json
"require-dev": {
    "laravel/tinker": "^2.10"
}
```

## Icon Generation

```bash
php artisan nativeblade:icon
php artisan nativeblade:icon resources/icons/my-logo.png
php artisan nativeblade:icon --bg=#1a1a2e
```

Generates from a 1024x1024 PNG:
- **Desktop** — 32, 128, 256, 512, .ico, .icns
- **Android** — adaptive icons, round icons, monochrome, XML configs
- **iOS** — all required sizes, Contents.json
