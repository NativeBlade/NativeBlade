---
title: "Upgrading"
description: "Move your app to a new NativeBlade SDK version."
---

# Upgrading

NativeBlade ships as SDK versions (SDK 37, SDK 38, and so on). Each SDK is a
release line, and an SDK bump is where breaking changes can land. Moving to a new
SDK is a few steps.

## Steps

1. **Bump the package** to the new SDK line:

   ```bash
   composer require nativeblade/nativeblade:^38
   ```

2. **Sync your project** with the installed version:

   ```bash
   php artisan nativeblade:update
   ```

   This updates `package.json`, `vite.wasm.config.js`, and the Cargo config,
   regenerates your native config (`Cargo.toml`, capabilities, `package.json`
   features), and runs `npm install`. If you had edited `vite.wasm.config.js`,
   the previous file is kept as `vite.wasm.config.js.bak` so you can diff your
   changes back.

3. **Rebuild:**

   ```bash
   npm run build
   php artisan nativeblade:dev
   ```

   The first run after an SDK bump recompiles the Rust binary, which takes a few
   minutes. Later runs are fast.

For mobile, rebuild per platform:

```bash
php artisan nativeblade:dev --platform=android --build
php artisan nativeblade:dev --platform=ios --build
```

## Before you upgrade

- Check [Compatibility](/guides/compatibility/) for the Laravel, Livewire, and
  PHP versions the new SDK requires, and bump those in your project if needed.
- Read the release notes for the SDK you are moving to, for any breaking changes.

## Pinning a version

`composer require nativeblade/nativeblade:^37` keeps you on the SDK 37 line and
still receives its patches and features, but not the next SDK. Bump the major
yourself when you are ready to move.
