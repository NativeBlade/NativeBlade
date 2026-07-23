---
title: "Getting Started"
description: "Set up and run your NativeBlade app on Android and iOS."
---

# Getting Started on Mobile

You need a NativeBlade project first. If you do not have one yet, follow the
[Quick start](/) to create and install it.

## Add the platform

Add Android or iOS to your project:

```bash
php artisan nativeblade:add android
php artisan nativeblade:add ios
```

## Run on a device or emulator

```bash
php artisan nativeblade:dev --platform=android --host=<your-ip>
php artisan nativeblade:dev --platform=ios --host=<your-ip>
```

Mobile needs `--host=<your-local-ip>` so the device can reach the Vite dev
server running on your machine. Replace `<your-ip>` with your computer's LAN IP
(find it with `ipconfig` on Windows, or `ifconfig` / `ip addr` on macOS and
Linux). `localhost` does not work, because the phone cannot reach your machine
through it.

## Easiest way: the Portal app

The NativeBlade Portal loads your dev app on a real device with no Android Studio
or Xcode build. Install it once:

[![Available on the App Store](https://img.shields.io/badge/App%20Store-Download-0a84ff?logo=apple&logoColor=white)](https://apps.apple.com/us/app/nativeblade/id6765935943)
[![Get it on Google Play](https://img.shields.io/badge/Google%20Play-Download-689f38?logo=googleplay&logoColor=white)](https://play.google.com/store/apps/details?id=com.nativeblade.app)

Then run:

```bash
php artisan nativeblade:dev --platform=portal --host=<your-ip>
```

Open the Portal app on your phone, scan the QR shown in your terminal (or paste
the URL), and your app loads in seconds. Switch projects by changing the URL, no
rebuild required. See [Portal](/guides/portal/) for details.
