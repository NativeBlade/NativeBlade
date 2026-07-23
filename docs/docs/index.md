---
title: "Introduction"
description: "NativeBlade turns Laravel and Livewire apps into native mobile and desktop apps, from one PHP codebase."
---

# NativeBlade

Build **native mobile and desktop apps** with the stack you already know:
Laravel, Livewire, Blade. NativeBlade runs your app on a real PHP runtime
(php-wasm) inside a native shell (Tauri), so one codebase ships to Android, iOS,
and desktop.

::: callout tip "One codebase, two surfaces"
The same Livewire app runs everywhere. Most APIs are shared. A few are specific
to where the app runs, so the docs are split to keep it clear which is which.
:::

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

## Start here

- [Architecture](/core/architecture/): how the runtime, shell, and your Livewire
  app fit together. Read this first.
- [Compatibility](/guides/compatibility/): which SDK maps to which Android
  target, NDK, Tauri, and PHP.

## Versioning

NativeBlade ships as **SDK versions**. Each SDK is a release line. The docs you
are reading are pinned to one, and you can switch versions from the menu at the
top of the sidebar. The current line is **SDK 37**.
