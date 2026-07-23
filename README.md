<p align="center">
  <img src="banner_nb.png" alt="NativeBlade" width="100%">
</p>

<p align="center">
  <strong>Build desktop and mobile apps with Laravel + Livewire. No Electron. No React Native. Just PHP.</strong>
</p>

<p align="center">
  <a href="https://docs.nativeblade.dev"><img src="https://img.shields.io/badge/Docs-docs.nativeblade.dev-ff5151" alt="Documentation"></a>
  <a href="https://github.com/NativeBlade/NativeBlade/actions/workflows/tests.yml"><img src="https://github.com/NativeBlade/NativeBlade/actions/workflows/tests.yml/badge.svg" alt="Tests"></a>
  <a href="https://discord.gg/Vzpach5J2h"><img src="https://img.shields.io/badge/Discord-Join%20Community-5865F2?logo=discord&logoColor=white" alt="Discord"></a>
</p>

---

<p align="center">
  <img src="hello.gif" alt="NativeBlade Demo" width="600">
</p>

## What is NativeBlade

NativeBlade lets Laravel developers build desktop and mobile apps using only PHP
and Blade. Your entire Laravel + Livewire application runs inside a PHP
WebAssembly runtime, wrapped in a [Tauri 2](https://v2.tauri.app) shell. No
JavaScript frameworks, no API layers, just the Laravel you already know.

One codebase ships to Windows, macOS, Linux, Android, and iOS.

- **Pure Laravel:** routes, Livewire components, Blade, Eloquent on SQLite.
- **Native shell:** top bar, bottom nav, drawer, modal, tray, all outside the WebView.
- **Native APIs:** dialogs, notifications, camera, geolocation, biometric, NFC, push, and more.
- **Offline first:** SQLite persisted to IndexedDB, works without a server.
- **Tiny bundle:** a full Laravel + Livewire app compresses to about 6 MB gzipped.

## Documentation

Everything lives at **[docs.nativeblade.dev](https://docs.nativeblade.dev)**:
architecture, every plugin, configuration, publishing, and the CLI.

## Quick start

```bash
# 1. Create a Laravel project
composer create-project "laravel/laravel:^13.0" my-app
cd my-app

# 2. Install NativeBlade
composer require nativeblade/nativeblade
php artisan nativeblade:install

# 3. Build the frontend and launch the desktop app
npm run build
php artisan nativeblade:dev
```

Add mobile with `php artisan nativeblade:add android` (or `ios`). The first run
compiles the Rust binary and takes a few minutes; later runs are fast.

Requirements: PHP 8.3+, Laravel 12+, Livewire 4, Node 20+, and
[Rust](https://www.rust-lang.org/tools/install). Full setup is in the
[docs](https://docs.nativeblade.dev).

## Build in the cloud

Build your app for the stores without Android Studio or Xcode, using
**[nativeblade.dev](https://nativeblade.dev)**. Link your GitHub repository and
build for dev, test, and production. The free plan includes 8 builds per month.
See [Publish](https://docs.nativeblade.dev/guides/publish/).

## NativeBlade Portal

A companion app that loads any NativeBlade dev bundle by URL or QR scan, so you
can preview your app on a real device with no build step. Run
`php artisan nativeblade:dev --platform=portal --host=<your-ip>`, then scan the
QR shown in your terminal.

[![Available on the App Store](https://img.shields.io/badge/App%20Store-Download-0a84ff?logo=apple&logoColor=white)](https://apps.apple.com/us/app/nativeblade/id6765935943)
[![Get it on Google Play](https://img.shields.io/badge/Google%20Play-Download-689f38?logo=googleplay&logoColor=white)](https://play.google.com/store/apps/details?id=com.nativeblade.app)

See [Portal](https://docs.nativeblade.dev/guides/portal/).

## NativeBlade Studio

Build NativeBlade apps with a clean, high-level syntax over MCP, using your own
AI. Studio follows MCP standards, so any MCP-capable assistant can drive it.

Repository: [github.com/NativeBlade/studio](https://github.com/NativeBlade/studio).
See [Studio](https://docs.nativeblade.dev/guides/studio/).

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md).

## Sponsor

NativeBlade is free and 100% open source, with no paid tier and no closed core.
If it saves your team time, [sponsoring](SPONSORS.md) keeps it that way. For
teams running in production, [Business Support](SUPPORT.md) offers a private
channel with a guaranteed response time.

## License

MIT

---

<p align="center">
  Built with Laravel, Livewire, Tauri, and PHP WebAssembly.<br>
  <a href="https://www.linkedin.com/in/jefferson-silva-66bba7aa/">Jefferson T.S</a>
</p>
