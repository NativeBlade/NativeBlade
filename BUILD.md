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

## All CLI Commands

| Command | Description |
|---------|-------------|
| `nativeblade:install` | Interactive setup — scaffolds Tauri project, layouts, config |
| `nativeblade:add android` | Add Android platform |
| `nativeblade:add ios` | Add iOS platform (macOS only) |
| `nativeblade:dev` | Start desktop dev with hot reload |
| `nativeblade:dev --platform=android` | Run on Android device |
| `nativeblade:dev --platform=ios` | Run on iOS simulator |
| `nativeblade:config` | Regenerate Tauri configs from PHP |
| `nativeblade:build {platform}` | Build for android, ios, or desktop |
| `nativeblade:icon` | Generate all platform icons from 1024x1024 PNG |
| `nativeblade:php {version?}` | Set PHP WASM version (8.3, 8.4, 8.5) |
| `nativeblade:component {name}` | Create a custom component |

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
